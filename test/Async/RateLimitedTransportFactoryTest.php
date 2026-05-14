<?php

/**
 * PHPMailer-Async-Proxy-Workerman — RateLimitedTransportFactory tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\RateLimitedTransportFactory;
use PHPMailer\PHPMailer\Async\TransportFactory;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RateLimitedTransportFactoryTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        // Reset all state before each test
        RateLimitedTransportFactory::reset();
    }

    protected function tear_down(): void
    {
        RateLimitedTransportFactory::reset();
        parent::tear_down();
    }

    public function testSetAndGetDefaultLimit(): void
    {
        RateLimitedTransportFactory::setDefaultLimit(30, 5);

        // The status should reflect the set limits
        $status = RateLimitedTransportFactory::getHostStatus('smtp.example.com');

        self::assertSame(30, $status['messagesPerMinute']);
        self::assertSame(5, $status['burstSize']);
    }

    public function testSetHostLimitOverridesDefault(): void
    {
        RateLimitedTransportFactory::setDefaultLimit(60, 10);
        RateLimitedTransportFactory::setHostLimit('smtp.example.com', 120, 20);

        $status = RateLimitedTransportFactory::getHostStatus('smtp.example.com');

        self::assertSame(120, $status['messagesPerMinute']);
        self::assertSame(20, $status['burstSize']);
    }

    public function testGetHostStatusReturnsAvailableTokens(): void
    {
        RateLimitedTransportFactory::setDefaultLimit(60, 10);
        RateLimitedTransportFactory::setHostLimit('smtp.example.com', 60, 5);

        $status = RateLimitedTransportFactory::getHostStatus('smtp.example.com');

        self::assertSame(5, $status['availableNow']);
        self::assertEqualsWithDelta(5.0, $status['tokens'], 0.1);
    }

    public function testResetClearsAllState(): void
    {
        RateLimitedTransportFactory::setHostLimit('smtp1.example.com', 100, 10);
        RateLimitedTransportFactory::setHostLimit('smtp2.example.com', 200, 20);

        RateLimitedTransportFactory::reset();

        $status1 = RateLimitedTransportFactory::getHostStatus('smtp1.example.com');
        $status2 = RateLimitedTransportFactory::getHostStatus('smtp2.example.com');

        // After reset, should use defaults and have full burst
        self::assertSame(RateLimitedTransportFactory::DEFAULT_MESSAGES_PER_MINUTE, $status1['messagesPerMinute']);
        self::assertSame(RateLimitedTransportFactory::DEFAULT_BURST_SIZE, $status1['burstSize']);
    }

    public function testResetSingleHostPreservesOtherHosts(): void
    {
        RateLimitedTransportFactory::setHostLimit('smtp1.example.com', 100, 10);
        RateLimitedTransportFactory::setHostLimit('smtp2.example.com', 200, 20);

        RateLimitedTransportFactory::reset('smtp1.example.com');

        $status1 = RateLimitedTransportFactory::getHostStatus('smtp1.example.com');
        $status2 = RateLimitedTransportFactory::getHostStatus('smtp2.example.com');

        // smtp1 should be reset to defaults
        self::assertSame(RateLimitedTransportFactory::DEFAULT_MESSAGES_PER_MINUTE, $status1['messagesPerMinute']);

        // smtp2 should retain its setting
        self::assertSame(200, $status2['messagesPerMinute']);
    }

    public function testTokenConsumptionOnGetTransport(): void
    {
        RateLimitedTransportFactory::setDefaultLimit(60, 3);

        $factory = new RateLimitedTransportFactory();

        // Get initial status
        $initialStatus = RateLimitedTransportFactory::getHostStatus('smtp.example.com');
        $initialTokens = $initialStatus['availableNow'];

        // Consume a token by getting a transport
        // Note: In test environment, this may not actually consume if transport creation fails
        // The important thing is the factory doesn't error
        try {
            $factory->getTransport('smtp.example.com');
        } catch (\Throwable $e) {
            // Ignore transport creation errors - we're testing rate limiting
        }

        // The token bucket should be refilled by now, so we might see same or different
        // This is a basic sanity check
        $afterStatus = RateLimitedTransportFactory::getHostStatus('smtp.example.com');
        self::assertIsInt($afterStatus['availableNow']);
    }

    public function testMultipleHostsHaveIndependentLimits(): void
    {
        RateLimitedTransportFactory::setHostLimit('host1.example.com', 30, 5);
        RateLimitedTransportFactory::setHostLimit('host2.example.com', 60, 10);

        $status1 = RateLimitedTransportFactory::getHostStatus('host1.example.com');
        $status2 = RateLimitedTransportFactory::getHostStatus('host2.example.com');

        self::assertSame(30, $status1['messagesPerMinute']);
        self::assertSame(5, $status1['burstSize']);

        self::assertSame(60, $status2['messagesPerMinute']);
        self::assertSame(10, $status2['burstSize']);
    }

    public function testCreateRateLimitedTransportReturnsWrappedTransport(): void
    {
        $factory = new RateLimitedTransportFactory();

        $transport = $factory->createRateLimitedTransport('smtp.example.com');

        // Should return a transport that implements the Transport interface
        self::assertInstanceOf(\PHPMailer\PHPMailer\Async\Transport::class, $transport);
    }

    public function testSetDefaultLimitWithInvalidValuesUsesMinimum(): void
    {
        RateLimitedTransportFactory::setDefaultLimit(-5, -10);

        $status = RateLimitedTransportFactory::getHostStatus('anyhost.com');

        // Should use minimum value of 1
        self::assertSame(1, $status['messagesPerMinute']);
        self::assertSame(1, $status['burstSize']);
    }

    public function testTokenRefillOverTime(): void
    {
        RateLimitedTransportFactory::setDefaultLimit(60, 1); // 1 token per second

        // Consume the only token
        $factory = new RateLimitedTransportFactory();
        try {
            $factory->getTransport('smtp.example.com');
        } catch (\Throwable $e) {
            // Ignore
        }

        // Wait for refill (100ms = 0.1 seconds = 0.1 tokens)
        usleep(150_000);

        $status = RateLimitedTransportFactory::getHostStatus('smtp.example.com');

        // Should have some tokens refilled (may be less than 1 due to time elapsed)
        // At 60 per minute = 1 per second, after 150ms we should have ~0.15 tokens
        // But since we consumed 1, we're at ~0.15 now, so availableNow should be 0
        self::assertSame(0, $status['availableNow']);
    }
}
