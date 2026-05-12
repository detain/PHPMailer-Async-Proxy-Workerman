<?php

/**
 * PHPMailer-Async-Proxy-Workerman — POP3::disconnect must always close
 * the transport, even when the connection is already at EOF.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\POP3;

use PHPMailer\PHPMailer\Async\Transport;
use PHPMailer\PHPMailer\POP3;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression test for the codex review on the POP3-async PR: before the
 * fix, a POP3 server that hung up early (e.g. after an auth failure)
 * left $this->transport holding the stream resource, leaking the FD
 * until the next connect or object destruction.
 *
 * @group pop3
 */
final class Pop3DisconnectClosesTransportTest extends TestCase
{
    public function testDisconnectClosesTransportEvenAfterEof(): void
    {
        $transport = new RecordingTransport();
        $pop = new POP3();
        $pop->setTransport($transport);
        // Pretend a connect happened and then the peer hung up.
        $transport->openState = true;

        // Simulate EOF: peer closed the read side. From POP3's point of
        // view the connection is no longer "open" but the transport
        // still holds a stream until close() is called.
        $transport->openState = false;
        $transport->resourceState = 'still-allocated';

        $pop->disconnect();

        self::assertTrue(
            $transport->closeCalled,
            'disconnect() must close() the transport on EOF, otherwise '
                . 'the underlying stream resource leaks'
        );
    }

    public function testDisconnectIsNoOpWhenNoTransportEverBuilt(): void
    {
        $pop = new POP3();
        // No setTransport() — disconnect() before any I/O. Must not blow up.
        $pop->disconnect();
        // No assertion needed — reaching here is the assertion.
        self::assertTrue(true);
    }

    public function testDisconnectClosesGracefullyOnOpenSession(): void
    {
        $transport = new RecordingTransport();
        $pop = new POP3();
        $pop->setTransport($transport);
        $transport->openState = true;

        $pop->disconnect();

        self::assertContains('QUIT' . POP3::LE, $transport->writes, 'polite QUIT was sent');
        self::assertTrue($transport->closeCalled);
    }
}

/**
 * Bare-minimum Transport stub that records the calls POP3 makes on it.
 *
 * @internal
 */
final class RecordingTransport implements Transport
{
    public bool $openState = false;
    public bool $closeCalled = false;
    public ?string $resourceState = null;
    /** @var list<string> */
    public array $writes = [];

    public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool
    {
        $this->openState = true;
        return true;
    }

    public function close(): void
    {
        $this->closeCalled = true;
        $this->openState = false;
        $this->resourceState = null;
    }

    public function isOpen(): bool
    {
        return $this->openState;
    }

    public function write(string $data)
    {
        $this->writes[] = $data;
        return strlen($data);
    }

    public function readLine(int $maxLength): string
    {
        return "+OK closing\r\n";
    }

    public function waitReadable(int $timeoutSeconds): ?bool
    {
        return true;
    }

    public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool
    {
        return true;
    }

    public function getMetadata(): array
    {
        return ['timed_out' => false, 'eof' => !$this->openState, 'blocked' => false];
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
