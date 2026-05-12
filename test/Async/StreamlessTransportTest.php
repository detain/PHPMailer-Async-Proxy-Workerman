<?php

/**
 * PHPMailer-Async-Proxy-Workerman — SMTP must work over a Transport that
 * does not expose a PHP stream resource (e.g. a Workerman/coroutine
 * connection wrapping a socket without going through stream_*).
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\Transport;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pin down that {@see SMTP::getSMTPConnection()} no longer needs the transport
 * to hand back a PHP stream resource. Was a real bug — non-stream transports
 * had `connect()` succeed but the SMTP layer returned `false` and aborted
 * before the first read.
 */
final class StreamlessTransportTest extends TestCase
{
    public function testConnectSucceedsWhenTransportHasNoStream(): void
    {
        $smtp = new SMTP();
        $smtp->setTransport(new StreamlessFakeTransport());

        self::assertTrue($smtp->connect('mock.example', 25, 5));
        self::assertTrue($smtp->connected());
    }

    public function testCloseStillWorksWithoutStream(): void
    {
        $smtp = new SMTP();
        $transport = new StreamlessFakeTransport();
        $smtp->setTransport($transport);
        $smtp->connect('mock.example', 25, 5);

        $smtp->close();
        self::assertFalse($smtp->connected());
        self::assertFalse($transport->isOpen());
    }
}

/**
 * Minimal Transport stub that has NO PHP stream resource — getResource()
 * always returns null. Models a Workerman / amphp / coroutine connection
 * where the transport wraps an OS-level socket without exposing it to
 * userland as a PHP `resource`.
 *
 * @internal
 */
final class StreamlessFakeTransport implements Transport
{
    private bool $open = false;
    private string $inbox = "220 mock ready\r\n250 OK\r\n221 bye\r\n";

    public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool
    {
        $this->open = true;
        return true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function write(string $data)
    {
        return strlen($data);
    }

    public function readLine(int $maxLength): string
    {
        if ($this->inbox === '') {
            return '';
        }
        $nl = strpos($this->inbox, "\n");
        if ($nl === false) {
            $line = $this->inbox;
            $this->inbox = '';
            return $line;
        }
        $line = substr($this->inbox, 0, $nl + 1);
        $this->inbox = substr($this->inbox, $nl + 1);
        return $line;
    }

    public function waitReadable(int $timeoutSeconds): ?bool
    {
        return $this->inbox !== '';
    }

    public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool
    {
        return true;
    }

    public function getMetadata(): array
    {
        return ['timed_out' => false, 'eof' => !$this->open && $this->inbox === '', 'blocked' => false];
    }

    public function setReadTimeout(int $seconds): void
    {
    }

    public function getConnectError(): array
    {
        return ['errno' => 0, 'errstr' => ''];
    }

    public function getLastWarning(): array
    {
        return ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];
    }

    public function clearLastWarning(): void
    {
    }

    public function getResource()
    {
        return null;
    }

    public function setErrorHandler(?callable $handler): void
    {
    }

    public function setProxyProtocolHeader(?string $bytes): void
    {
    }
}
