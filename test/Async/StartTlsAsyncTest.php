<?php

/**
 * PHPMailer-Async-Proxy-Workerman — async STARTTLS tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class StartTlsAsyncTest extends TestCase
{
    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl required');
        }
    }

    public function testEnableCryptoOnClosedTransportReturnsFalse(): void
    {
        $t = new WorkermanTransport();
        // No connect() called
        self::assertFalse($t->enableCrypto(\STREAM_CRYPTO_METHOD_TLS_CLIENT, 1));
    }

    public function testEnableCryptoTimesOutAgainstSilentServer(): void
    {
        // A plain TCP listener that never sends TLS bytes; the would-block
        // loop should time out gracefully and return false within the budget.
        $server = new MockSmtpServer();
        $server->setScript(["220 mock\r\n"]); // greeting, then nothing else
        $port = $server->start();

        $t = new WorkermanTransport();
        self::assertTrue($t->connect('127.0.0.1', $port, 5));
        // Drain the greeting first so it doesn't interfere with the handshake
        $t->readLine(512);

        $start = microtime(true);
        $ok = $t->enableCrypto(\STREAM_CRYPTO_METHOD_TLS_CLIENT, 1);
        $elapsed = microtime(true) - $start;

        $t->close();
        $server->stop();

        self::assertFalse($ok, 'TLS handshake against a non-TLS server must fail');
        self::assertLessThan(3.0, $elapsed, 'TLS timeout path should not hang well past its budget');
    }

    public function testCryptoLoopExercisedThroughLargerTimeoutWindowDoesNotBusyLoop(): void
    {
        // Sanity: a 2s budget against a silent server should consume ~2s of
        // wall time (driven by Revolt's onReadable/onWritable suspension)
        // and not burn CPU on a busy loop.
        $server = new MockSmtpServer();
        $server->setScript(["220 mock\r\n"]);
        $port = $server->start();

        $t = new WorkermanTransport();
        self::assertTrue($t->connect('127.0.0.1', $port, 5));
        $t->readLine(512);

        $start = microtime(true);
        $ok = $t->enableCrypto(\STREAM_CRYPTO_METHOD_TLS_CLIENT, 2);
        $elapsed = microtime(true) - $start;

        $t->close();
        $server->stop();

        self::assertFalse($ok);
        self::assertGreaterThanOrEqual(1.5, $elapsed, 'Should suspend on the event loop, not busy-loop');
        self::assertLessThan(4.0, $elapsed);
    }
}
