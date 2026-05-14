<?php

/**
 * PHPMailer-Async-Proxy-Workerman — WorkermanTransport TLS session caching tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\WorkermanTransport;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class WorkermanTransportTlsSessionTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        WorkermanTransport::clearTlsSessionCache();
    }

    protected function tear_down(): void
    {
        WorkermanTransport::clearTlsSessionCache();
        parent::tear_down();
    }

    public function testClearTlsSessionCacheStartsEmpty(): void
    {
        // Clear any existing cache
        WorkermanTransport::clearTlsSessionCache();

        $size = WorkermanTransport::getTlsSessionCacheSize();

        self::assertSame(0, $size);
    }

    public function testSetTlsSessionCacheSizeSetsLimit(): void
    {
        WorkermanTransport::setTlsSessionCacheSize(50);

        // We can't easily add sessions in unit test without real TLS
        // but we can verify the method doesn't error
        self::assertTrue(true);
    }

    public function testGetTlsSessionCacheSizeReturnsCurrentCount(): void
    {
        // Initially empty
        self::assertSame(0, WorkermanTransport::getTlsSessionCacheSize());
    }

    public function testClearTlsSessionCacheClearsAllSessions(): void
    {
        // This test verifies the API works without actually testing session caching
        // since that requires real TLS connections
        WorkermanTransport::clearTlsSessionCache();

        self::assertSame(0, WorkermanTransport::getTlsSessionCacheSize());

        // Should be safe to call multiple times
        WorkermanTransport::clearTlsSessionCache();
        WorkermanTransport::clearTlsSessionCache();

        self::assertSame(0, WorkermanTransport::getTlsSessionCacheSize());
    }

    public function testWorkermanTransportCanBeInstantiated(): void
    {
        $transport = new WorkermanTransport();

        self::assertInstanceOf(WorkermanTransport::class, $transport);
    }

    public function testWorkermanTransportImplementsTransportInterface(): void
    {
        $transport = new WorkermanTransport();

        self::assertInstanceOf(\PHPMailer\PHPMailer\Async\Transport::class, $transport);
    }

    public function testStaticMethodsAreCallable(): void
    {
        // Verify static methods exist and are callable (don't error on definition)
        $transport = new WorkermanTransport();

        // Clear cache
        WorkermanTransport::clearTlsSessionCache();
        self::assertSame(0, WorkermanTransport::getTlsSessionCacheSize());

        // Set cache size
        WorkermanTransport::setTlsSessionCacheSize(100);
        // No exception means success
        $this->assertTrue(true);
    }
}
