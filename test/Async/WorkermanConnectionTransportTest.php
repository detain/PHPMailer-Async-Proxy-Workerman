<?php

/**
 * PHPMailer-Async-Proxy-Workerman — WorkermanConnectionTransport tests.
 *
 * Exercises the AsyncTcpConnection-backed transport end-to-end against
 * the in-process pcntl_fork'd MockSmtpServer. Each scenario is wrapped
 * in one FiberRunner::run() because AsyncTcpConnection holds long-lived
 * event-loop watchers that would die if we entered/exited FiberRunner
 * between methods — see the class docblock on WorkermanConnectionTransport.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\FiberRunner;
use PHPMailer\PHPMailer\Async\WorkermanConnectionTransport;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\ProxyProtocol\V1Header;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class WorkermanConnectionTransportTest extends TestCase
{
    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
        if (!class_exists(\Workerman\Connection\AsyncTcpConnection::class)) {
            self::markTestSkipped('workerman/workerman not installed');
        }
    }

    public function testBasicSmtpDialogueOverAsyncTcpConnection(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock ESMTP\r\n",
            "250-mock\r\n250 SIZE 1024\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport(new WorkermanConnectionTransport());

        $results = FiberRunner::run(function () use ($smtp, $port): array {
            $r = ['connect' => $smtp->connect('127.0.0.1', $port, 5)];
            $r['hello'] = $smtp->hello('client.example');
            $smtp->quit();
            $smtp->close();
            return $r;
        });
        $transcript = $server->stop();

        self::assertTrue($results['connect']);
        self::assertTrue($results['hello']);
        self::assertStringContainsString('EHLO client.example', $transcript);
        self::assertStringContainsString('QUIT', $transcript);
    }

    public function testProxyProtocolV1HeaderArrivesFirst(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250 mock\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport(new WorkermanConnectionTransport());
        $smtp->enableProxyProtocol(Configurator::v1('203.0.113.45', '127.0.0.1', 54321, $port));

        $connected = FiberRunner::run(function () use ($smtp, $port): bool {
            $ok = $smtp->connect('127.0.0.1', $port, 5);
            $smtp->quit();
            $smtp->close();
            return $ok;
        });
        $transcript = $server->stop();

        self::assertTrue($connected);
        $expected = (new V1Header('203.0.113.45', '127.0.0.1', 54321, $port))->build();
        self::assertSame(
            $expected,
            substr($transcript, 0, strlen($expected)),
            'PROXY v1 header must precede SMTP traffic on the AsyncTcpConnection transport too'
        );
    }

    public function testMailRcptDataPipeline(): void
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
        $smtp->setTransport(new WorkermanConnectionTransport());

        $results = FiberRunner::run(function () use ($smtp, $port): array {
            $r = [];
            $r['connect'] = $smtp->connect('127.0.0.1', $port, 5);
            $r['hello'] = $smtp->hello('client.test');
            $r['mail'] = $smtp->mail('alice@example.test');
            $r['rcpt'] = $smtp->recipient('bob@example.test');
            $r['data'] = $smtp->data("Subject: hi\r\n\r\nhello");
            $smtp->quit();
            $smtp->close();
            return $r;
        });
        $transcript = $server->stop();

        foreach (['connect', 'hello', 'mail', 'rcpt', 'data'] as $stage) {
            self::assertTrue($results[$stage], $stage . ' failed');
        }
        self::assertStringContainsString('MAIL FROM:<alice@example.test>', $transcript);
        self::assertStringContainsString('RCPT TO:<bob@example.test>', $transcript);
        self::assertStringContainsString("\r\n.\r\n", $transcript);
    }

    public function testConnectFailureReturnsFalseWithError(): void
    {
        $smtp = new SMTP();
        $smtp->setTransport(new WorkermanConnectionTransport());

        $ok = FiberRunner::run(static function () use ($smtp): bool {
            return $smtp->connect('127.0.0.1', 1, 1); // port 1 — not listening
        });
        self::assertFalse($ok);
    }

    public function testMethodOutsideFiberThrows(): void
    {
        // Per the contract, calling these outside a fiber must raise rather
        // than silently hang. Pin that down.
        $t = new WorkermanConnectionTransport();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be called from inside a fiber');
        $t->connect('127.0.0.1', 25, 1);
    }
}
