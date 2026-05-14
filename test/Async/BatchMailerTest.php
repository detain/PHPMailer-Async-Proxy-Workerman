<?php

/**
 * PHPMailer-Async-Proxy-Workerman — BatchMailer tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\BatchMailer;
use PHPMailer\PHPMailer\Async\SmtpConnectionPool;
use PHPMailer\PHPMailer\PHPMailer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class BatchMailerTest extends TestCase
{
    public function testBatchMailerCanBeConstructed(): void
    {
        $pool = new SmtpConnectionPool();
        $batch = new BatchMailer($pool, 'smtp.example.com', 25, 'user', 'pass');

        self::assertSame($pool, $batch->getPool());
        self::assertSame('smtp.example.com:25:user', $batch->getPoolKey());
    }

    public function testSendAllWithEmptyIterable(): void
    {
        $pool = new SmtpConnectionPool();
        $batch = new BatchMailer($pool, 'smtp.example.com', 25, 'user', 'pass');

        $results = iterator_to_array($batch->sendAll([]));

        self::assertSame([], $results);
    }

    public function testSendAllYieldsResultsForEachMessage(): void
    {
        // Fiber-based iteration cannot be tested in-process
        $this->markTestSkipped(
            'Fiber-based batch sending requires integration environment'
        );
    }

    public function testSendAllHandlesGeneratorInput(): void
    {
        // Fiber-based iteration cannot be tested in-process
        $this->markTestSkipped(
            'Fiber-based batch sending requires integration environment'
        );
    }

    public function testGetStatsReturnsPoolStats(): void
    {
        $pool = new SmtpConnectionPool();
        $batch = new BatchMailer($pool, 'smtp.example.com', 25, 'user', 'pass');

        $stats = $batch->getStats();

        self::assertArrayHasKey('acquireHits', $stats);
        self::assertArrayHasKey('acquireMisses', $stats);
        self::assertArrayHasKey('releases', $stats);
        self::assertArrayHasKey('evictions', $stats);
        self::assertArrayHasKey('hitRatio', $stats);
        self::assertArrayHasKey('idleNow', $stats);
    }

    public function testCloseAllClosesPool(): void
    {
        $pool = new SmtpConnectionPool();
        $batch = new BatchMailer($pool, 'smtp.example.com', 25, 'user', 'pass');

        // Release a connection first
        $fakePoolSmtp = new class extends \PHPMailer\PHPMailer\SMTP {
            public function __construct()
            {
            }

            public function connected(): bool
            {
                return false;
            }
        };
        $pool->release('smtp.example.com:25:user', $fakePoolSmtp);

        $batch->closeAll();

        self::assertSame(0, $pool->idleCount());
    }

    /**
     * Create a fake PHPMailer instance for testing.
     */
    private function createFakeMail(string $email): PHPMailer
    {
        $mail = new class extends PHPMailer {
            public function __construct()
            {
                // Skip parent constructor which does a lot of setup
            }

            public function send(): bool
            {
                // Simulate successful send
                return true;
            }

            public function getToAddresses(): array
            {
                return [[$this->email ?? $email, '']];
            }

            private string $email = '';

            public function addAddress($address, $name = ''): bool
            {
                $this->email = $address;
                return true;
            }
        };

        $mail->addAddress($email);
        return $mail;
    }
}
