<?php

/**
 * PHPMailer-Async-Proxy-Workerman — caller-supplied TLS crypto_method
 * passthrough on the deferred-TLS (PROXY + ssl://) path.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\StreamTransport;
use PHPMailer\PHPMailer\Async\WorkermanConnectionTransport;
use ReflectionMethod;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pin down that callers who lock the TLS protocol set via
 * `$contextOptions['ssl']['crypto_method']` get that lock applied
 * when the transport defers the TLS handshake (PROXY-before-TLS).
 *
 * `resolveImplicitCryptoMethod()` is private, so we drive it via
 * reflection. The alternative — wiring a real TLS server that we can
 * inspect the cipher of — adds a lot of surface for a contract check.
 */
final class CryptoMethodPassthroughTest extends TestCase
{
    public function testStreamTransportHonorsExplicitCryptoMethod(): void
    {
        $reflect = new ReflectionMethod(StreamTransport::class, 'resolveImplicitCryptoMethod');
        $reflect->setAccessible(true);

        $custom = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        $result = $reflect->invoke(null, ['ssl' => ['crypto_method' => $custom]]);
        self::assertSame($custom, $result);

        $result2 = $reflect->invoke(null, ['tls' => ['crypto_method' => $custom]]);
        self::assertSame($custom, $result2, 'tls bucket honored too');
    }

    public function testStreamTransportFallsBackWhenNoCryptoMethodSupplied(): void
    {
        $reflect = new ReflectionMethod(StreamTransport::class, 'resolveImplicitCryptoMethod');
        $reflect->setAccessible(true);

        $default = $reflect->invoke(null, []);
        self::assertSame(STREAM_CRYPTO_METHOD_TLS_CLIENT, $default & STREAM_CRYPTO_METHOD_TLS_CLIENT);
        // Should include TLS 1.2 by default when the constant is defined.
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            self::assertSame(
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                $default & STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );
        }
    }

    public function testStreamTransportIgnoresNonIntegerCryptoMethod(): void
    {
        $reflect = new ReflectionMethod(StreamTransport::class, 'resolveImplicitCryptoMethod');
        $reflect->setAccessible(true);

        // A bogus string should be ignored, not blow up.
        $result = $reflect->invoke(null, ['ssl' => ['crypto_method' => 'TLS_1_3_PLEASE']]);
        self::assertNotSame(0, $result, 'fell back to default');
    }

    public function testWorkermanConnectionTransportHonorsExplicitCryptoMethod(): void
    {
        if (!class_exists(\Workerman\Connection\AsyncTcpConnection::class)) {
            self::markTestSkipped('workerman/workerman not installed');
        }
        $reflect = new ReflectionMethod(
            WorkermanConnectionTransport::class,
            'resolveImplicitCryptoMethod'
        );
        $reflect->setAccessible(true);

        $custom = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        $transport = new WorkermanConnectionTransport();
        $result = $reflect->invoke($transport, ['ssl' => ['crypto_method' => $custom]]);
        self::assertSame($custom, $result);
    }

    public function testWorkermanConnectionTransportFallsBackWithoutSupply(): void
    {
        if (!class_exists(\Workerman\Connection\AsyncTcpConnection::class)) {
            self::markTestSkipped('workerman/workerman not installed');
        }
        $reflect = new ReflectionMethod(
            WorkermanConnectionTransport::class,
            'resolveImplicitCryptoMethod'
        );
        $reflect->setAccessible(true);

        $transport = new WorkermanConnectionTransport();
        $default = $reflect->invoke($transport, []);
        self::assertSame(
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
            $default & STREAM_CRYPTO_METHOD_TLS_CLIENT
        );
    }
}
