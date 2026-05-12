<?php

/**
 * PHPMailer-Async-Proxy-Workerman — SMTP AUTH over async transport.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\WorkermanTransport;
use PHPMailer\PHPMailer\OAuthTokenProvider;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class AuthAsyncTest extends TestCase
{
    private const USER = 'alice@example.test';
    private const PASS = 'hunter2';

    protected function set_up(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork required');
        }
    }

    public function testAuthPlainHandshake(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250-mock\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 1024\r\n",
            "334\r\n",
            "235 2.7.0 ok\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = $this->newAsyncSmtp();
        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('client.test'));
        self::assertTrue($smtp->authenticate(self::USER, self::PASS, 'PLAIN'));
        $smtp->quit();
        $smtp->close();
        $transcript = $server->stop();

        self::assertStringContainsString('AUTH PLAIN', $transcript);
        self::assertStringContainsString(base64_encode("\0" . self::USER . "\0" . self::PASS), $transcript);
    }

    public function testAuthLoginMultiStep(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250-mock\r\n250 AUTH LOGIN PLAIN\r\n",
            "334 VXNlcm5hbWU6\r\n",            // "Username:"
            "334 UGFzc3dvcmQ6\r\n",            // "Password:"
            "235 2.7.0 ok\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = $this->newAsyncSmtp();
        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('client.test'));
        self::assertTrue($smtp->authenticate(self::USER, self::PASS, 'LOGIN'));
        $smtp->quit();
        $smtp->close();
        $transcript = $server->stop();

        self::assertStringContainsString('AUTH LOGIN', $transcript);
        self::assertStringContainsString(base64_encode(self::USER), $transcript);
        self::assertStringContainsString(base64_encode(self::PASS), $transcript);
    }

    public function testAuthCramMd5Challenge(): void
    {
        $challenge = '<1234.5678@mock>';
        $b64Challenge = base64_encode($challenge);
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250-mock\r\n250 AUTH CRAM-MD5\r\n",
            "334 {$b64Challenge}\r\n",
            "235 2.7.0 ok\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = $this->newAsyncSmtp();
        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('client.test'));
        self::assertTrue($smtp->authenticate(self::USER, self::PASS, 'CRAM-MD5'));
        $smtp->quit();
        $smtp->close();
        $transcript = $server->stop();

        // CRAM-MD5 reply is base64(username + space + hmac-md5(challenge, password))
        $expected = self::USER . ' ' . hash_hmac('md5', $challenge, self::PASS);
        self::assertStringContainsString(base64_encode($expected), $transcript);
    }

    public function testXOAuth2WithStubProvider(): void
    {
        $token = "user=" . self::USER . "\1auth=Bearer abc123\1\1";
        $b64 = base64_encode($token);

        $provider = new class ($b64) implements OAuthTokenProvider {
            public function __construct(private string $token)
            {
            }

            public function getOauth64()
            {
                return $this->token;
            }
        };

        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250-mock\r\n250 AUTH XOAUTH2\r\n",
            "235 2.7.0 ok\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = $this->newAsyncSmtp();
        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('client.test'));
        self::assertTrue($smtp->authenticate(self::USER, '', 'XOAUTH2', $provider));
        $smtp->quit();
        $smtp->close();
        $transcript = $server->stop();

        self::assertStringContainsString('AUTH XOAUTH2 ' . $b64, $transcript);
    }

    public function testMailRcptDataPipelineOverAsyncTransport(): void
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

        $smtp = $this->newAsyncSmtp();
        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('client.test'));
        self::assertTrue($smtp->mail('alice@example.test'));
        self::assertTrue($smtp->recipient('bob@example.test'));
        self::assertTrue($smtp->data("Subject: hi\r\n\r\nhello"));
        $smtp->quit();
        $smtp->close();
        $transcript = $server->stop();

        self::assertStringContainsString('MAIL FROM:<alice@example.test>', $transcript);
        self::assertStringContainsString('RCPT TO:<bob@example.test>', $transcript);
        self::assertStringContainsString('DATA', $transcript);
        self::assertStringContainsString('Subject: hi', $transcript);
        // RFC5321 dot-stuffing: DATA terminates with CRLF.CRLF
        self::assertStringContainsString("\r\n.\r\n", $transcript);
    }

    public function testServerRejectionPropagates(): void
    {
        $server = new MockSmtpServer();
        $server->setScript([
            "220 mock\r\n",
            "250-mock\r\n250 AUTH PLAIN\r\n",
            "535 5.7.8 bad credentials\r\n",
            "221 bye\r\n",
        ]);
        $port = $server->start();

        $smtp = $this->newAsyncSmtp();
        self::assertTrue($smtp->connect('127.0.0.1', $port, 5));
        self::assertTrue($smtp->hello('client.test'));
        self::assertFalse($smtp->authenticate(self::USER, 'wrong', 'PLAIN'));
        $smtp->close();
        $server->stop();
    }

    private function newAsyncSmtp(): SMTP
    {
        $smtp = new SMTP();
        $smtp->setTransport(new WorkermanTransport());
        return $smtp;
    }
}
