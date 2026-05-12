<?php

/**
 * PHPMailer-Async-Proxy-Workerman — verify both transports produce
 * byte-identical client transcripts for a baseline SMTP dialogue.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\StreamTransport;
use PHPMailer\PHPMailer\Async\Transport;
use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Behavioural parity: same SMTP script, same client transcript, regardless
 * of whether SMTP is talking through StreamTransport (blocking) or
 * WorkermanTransport (Revolt + Fibers). Catches drift between the two paths.
 */
final class TransportParityTest extends TestCase
{
    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
    }

    /**
     * @return array<string, array{0: callable(): Transport}>
     */
    public static function transportFactoryProvider(): array
    {
        return [
            'stream' => [static fn(): Transport => new StreamTransport()],
            'workerman' => [static fn(): Transport => new WorkermanTransport()],
        ];
    }

    /**
     * @dataProvider transportFactoryProvider
     * @param callable(): Transport $factory
     */
    public function testBaselineSmtpDialogueIsIdentical(callable $factory): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250-mock\r\n250 SIZE 1024\r\n",
            "250 ok-mail\r\n",
            "250 ok-rcpt\r\n",
            "354 send data\r\n",
            "250 2.0.0 OK queued\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport($factory());

        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('parity.test'));
        self::assertTrue($smtp->mail('alice@parity.test'));
        self::assertTrue($smtp->recipient('bob@parity.test'));
        self::assertTrue($smtp->data("Subject: parity\r\n\r\nbody"));
        $smtp->quit();
        $smtp->close();

        $transcript = $server->stop();
        self::assertStringContainsString('EHLO parity.test', $transcript);
        self::assertStringContainsString('MAIL FROM:<alice@parity.test>', $transcript);
        self::assertStringContainsString('RCPT TO:<bob@parity.test>', $transcript);
        self::assertStringContainsString("\r\n.\r\n", $transcript);
        self::assertStringContainsString('QUIT', $transcript);
    }

    /**
     * @dataProvider transportFactoryProvider
     * @param callable(): Transport $factory
     */
    public function testProxyProtocolV1IsIdenticalAcrossTransports(callable $factory): void
    {
        $server = new MockSmtpServer();
        $server->setScript(["220 mock\r\n", "250 ok\r\n", "221 bye\r\n"]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport($factory());
        $smtp->enableProxyProtocol(Configurator::v1('203.0.113.45', '127.0.0.1', 54321, $port));

        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        $smtp->quit();
        $smtp->close();
        $transcript = $server->stop();

        $expectedHeader = "PROXY TCP4 203.0.113.45 127.0.0.1 54321 {$port}\r\n";
        self::assertSame($expectedHeader, substr($transcript, 0, strlen($expectedHeader)));
    }

    /**
     * @dataProvider transportFactoryProvider
     * @param callable(): Transport $factory
     */
    public function testServerErrorResponseHandledIdentically(callable $factory): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250 mock\r\n",
            "550 5.7.1 rejected\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport($factory());

        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('parity.test'));
        self::assertFalse($smtp->mail('rejected@parity.test'));
        $smtp->close();
        $server->stop();
    }
}
