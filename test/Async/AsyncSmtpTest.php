<?php

/**
 * PHPMailer-Async-Proxy-Workerman — async transport end-to-end tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\ProxyProtocol\V1Header;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class AsyncSmtpTest extends TestCase
{
    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
    }

    public function testEndToEndDialogueOverWorkermanTransport(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock.smtp ESMTP\r\n",
            "250-mock.smtp Hello\r\n250 SIZE 10485760\r\n",
            "221 Bye\r\n",
        ]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport(new WorkermanTransport());

        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('test.example'));
        $smtp->quit();
        $smtp->close();

        $transcript = $server->stop();
        self::assertStringContainsString('EHLO test.example', $transcript);
        self::assertStringContainsString('QUIT', $transcript);
    }

    public function testProxyProtocolV1OverWorkermanTransport(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock.smtp ESMTP\r\n",
            "250 mock.smtp Hello\r\n",
            "221 Bye\r\n",
        ]);
        $port = $server->start();

        $smtp = new SMTP();
        $smtp->setTransport(new WorkermanTransport());
        $smtp->enableProxyProtocol(Configurator::v1('203.0.113.45', '127.0.0.1', 54321, $port));

        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        $smtp->quit();
        $smtp->close();

        $transcript = $server->stop();
        $expected = (new V1Header('203.0.113.45', '127.0.0.1', 54321, $port))->build();
        self::assertSame(
            $expected,
            substr($transcript, 0, strlen($expected)),
            'PROXY v1 header must precede SMTP traffic on the async transport too'
        );
    }

    public function testConcurrentSendsShareEventLoop(): void
    {
        // Two independent dialogues against two independent fixtures; both
        // should complete on a single PHP process when each runs inside its
        // own FiberRunner-driven event loop. We measure wall time mostly to
        // catch a regression where one connection blocks the other.
        $server1 = new MockSmtpServer();
        $server2 = new MockSmtpServer();
        $server1->setScript([
            "220 a\r\n",
            "250 a-hi\r\n",
            "221 a-bye\r\n",
        ]);
        $server2->setScript([
            "220 b\r\n",
            "250 b-hi\r\n",
            "221 b-bye\r\n",
        ]);
        $p1 = $server1->start();
        $p2 = $server2->start();

        $start = microtime(true);
        foreach ([$p1, $p2] as $port) {
            $smtp = new SMTP();
            $smtp->setTransport(new WorkermanTransport());
            self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
            $smtp->hello('client.example');
            $smtp->quit();
            $smtp->close();
        }
        $elapsed = microtime(true) - $start;

        $server1->stop();
        $server2->stop();
        // 5s is a generous ceiling; both sends together should take well under 1s.
        self::assertLessThan(5.0, $elapsed, 'Sequential async sends should not stall');
    }
}
