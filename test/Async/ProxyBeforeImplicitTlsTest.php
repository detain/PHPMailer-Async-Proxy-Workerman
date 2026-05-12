<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY header must precede implicit TLS.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\StreamTransport;
use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\ProxyProtocol\V1Header;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pin down that when a caller passes `ssl://host` (the PHPMailer SMTPS path)
 * AND has configured a PROXY header, the header bytes are written to the
 * peer BEFORE any TLS ClientHello. Otherwise port-465-style receivers see a
 * TLS handshake first and reject the connection.
 */
final class ProxyBeforeImplicitTlsTest extends TestCase
{
    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
    }

    public function testStreamTransportWritesProxyBeforeTlsHandshake(): void
    {
        $this->assertProxyBeforeTls(new StreamTransport());
    }

    public function testWorkermanTransportWritesProxyBeforeTlsHandshake(): void
    {
        $this->assertProxyBeforeTls(new WorkermanTransport());
    }

    private function assertProxyBeforeTls(\PHPMailer\PHPMailer\Async\Transport $transport): void
    {
        $server = new MockSmtpServer();
        $server->setScript(["220 mock\r\n", "221 bye\r\n"]); // never speaks TLS — we expect first bytes only
        $port = $server->start();

        $proxyHeader = new V1Header('198.51.100.7', '127.0.0.1', 6543, $port);
        $transport->setProxyProtocolHeader($proxyHeader->build());

        // Caller passes ssl:// to model PHPMailer's SMTPSecure='ssl' path. The
        // transport must defer the TLS upgrade until after PROXY has shipped.
        // The TLS handshake will of course fail against the mock (it's just a
        // plain-TCP listener) — we don't care about the return value; we only
        // care about what arrived on the wire before TLS started.
        @$transport->connect('ssl://127.0.0.1', $port, 2);
        $transport->close();

        $transcript = $server->stop();
        self::assertSame(
            $proxyHeader->build(),
            substr($transcript, 0, strlen($proxyHeader->build())),
            'PROXY header must be the very first bytes on the wire, even when ssl:// implicit TLS was requested'
        );
    }
}
