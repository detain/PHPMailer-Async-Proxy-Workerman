<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol + SMTP integration test.
 *
 * Spins up a tiny in-process TCP listener (in a forked child) that records
 * the first bytes it receives, drives PHPMailer's SMTP class against it, and
 * asserts the PROXY header showed up *before* any SMTP traffic.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\ProxyProtocol;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\ProxyProtocol\V1Header;
use PHPMailer\PHPMailer\ProxyProtocol\V2Header;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SmtpIntegrationTest extends TestCase
{
    private const TRANSCRIPT_BYTES = 512;

    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
    }

    public function testV1HeaderArrivesBeforeAnySmtpCommand(): void
    {
        $expected = (new V1Header('203.0.113.45', '127.0.0.1', 54321, 25))->build();
        $transcript = $this->runWithListener(
            static function (SMTP $smtp, int $port): void {
                $smtp->enableProxyProtocol(Configurator::v1('203.0.113.45', '127.0.0.1', 54321, 25));
                $smtp->connect('127.0.0.1', $port, 5);
                // sendCommand("QUIT") would also try to read a response — bypass it
                $smtp->close();
            }
        );

        self::assertNotSame('', $transcript, 'Listener captured no bytes — connect failed?');
        self::assertSame(
            $expected,
            substr($transcript, 0, strlen($expected)),
            'PROXY v1 header must be the first thing on the wire'
        );
    }

    public function testV2HeaderArrivesBeforeAnySmtpCommand(): void
    {
        $expected = V2Header::tcp4('198.51.100.7', '127.0.0.1', 1234, 25)->build();
        $transcript = $this->runWithListener(
            static function (SMTP $smtp, int $port): void {
                $smtp->enableProxyProtocol(Configurator::v2('198.51.100.7', '127.0.0.1', 1234, 25));
                $smtp->connect('127.0.0.1', $port, 5);
                $smtp->close();
            }
        );

        self::assertNotSame('', $transcript);
        self::assertSame(bin2hex($expected), bin2hex(substr($transcript, 0, strlen($expected))));

        // Round-trip parse what the listener actually received
        $parsed = V2Header::parse($transcript);
        self::assertNotNull($parsed);
        self::assertSame('198.51.100.7', $parsed['src_ip']);
        self::assertSame(1234, $parsed['src_port']);
    }

    public function testDisableProxyProtocolSuppressesHeader(): void
    {
        $transcript = $this->runWithListener(
            static function (SMTP $smtp, int $port): void {
                $smtp->enableProxyProtocol(Configurator::v1('203.0.113.45', '127.0.0.1', 54321, 25));
                $smtp->disableProxyProtocol();
                $smtp->connect('127.0.0.1', $port, 5);
                $smtp->close();
            }
        );

        self::assertNotSame(
            "PROXY",
            substr($transcript, 0, 5),
            'Disable should suppress the header entirely'
        );
    }

    public function testPHPMailerForwarderConfiguresUnderlyingSmtp(): void
    {
        $transcript = $this->runWithListener(
            static function (SMTP $smtp, int $port): void {
                $mail = new PHPMailer(true);
                $mail->setSMTPInstance($smtp);
                $mail->setProxyProtocol(Configurator::v1('192.0.2.99', '127.0.0.1', 6789, 25));
                $smtp->connect('127.0.0.1', $port, 5);
                $smtp->close();
            }
        );

        $expected = (new V1Header('192.0.2.99', '127.0.0.1', 6789, 25))->build();
        self::assertSame($expected, substr($transcript, 0, strlen($expected)));
    }

    public function testGetProxyProtocolBuilderRoundTrip(): void
    {
        $smtp = new SMTP();
        self::assertNull($smtp->getProxyProtocolBuilder());

        $builder = Configurator::v1('1.2.3.4', '5.6.7.8', 1234, 25);
        $smtp->enableProxyProtocol($builder);
        self::assertSame($builder, $smtp->getProxyProtocolBuilder());

        $smtp->disableProxyProtocol();
        self::assertNull($smtp->getProxyProtocolBuilder());
    }

    /**
     * Run $clientWork in the parent process against a forked listener that
     * captures every byte it receives until the client closes. Returns the
     * full transcript as a single string.
     *
     * @param \Closure(SMTP, int): void $clientWork
     */
    private function runWithListener(\Closure $clientWork): string
    {
        $port = $this->findFreePort();
        $transcriptFile = tempnam(sys_get_temp_dir(), 'pp-transcript-');

        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Child — the listener
            $server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
            if ($server === false) {
                file_put_contents($transcriptFile, '');
                exit(1);
            }
            $client = @stream_socket_accept($server, 5);
            if ($client !== false) {
                // Drain quickly — send a 220 so a real PHPMailer connect() can complete.
                fwrite($client, "220 mock smtp ready\r\n");
                stream_set_timeout($client, 1, 0);
                $buf = '';
                $deadline = microtime(true) + 1.0;
                while (microtime(true) < $deadline && strlen($buf) < self::TRANSCRIPT_BYTES) {
                    $chunk = @fread($client, 256);
                    if ($chunk === false || $chunk === '') {
                        if (feof($client)) {
                            break;
                        }
                        usleep(10_000);
                        continue;
                    }
                    $buf .= $chunk;
                }
                file_put_contents($transcriptFile, $buf);
                fclose($client);
            }
            fclose($server);
            exit(0);
        }

        // Parent — give the child a moment to bind
        usleep(150_000);

        try {
            $smtp = new SMTP();
            $clientWork($smtp, $port);
        } finally {
            pcntl_waitpid($pid, $status);
        }

        $transcript = file_get_contents($transcriptFile);
        @unlink($transcriptFile);

        return $transcript === false ? '' : $transcript;
    }

    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::fail('Could not allocate an ephemeral port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        [, $port] = explode(':', (string) $name);
        return (int) $port;
    }
}
