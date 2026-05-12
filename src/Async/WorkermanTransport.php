<?php

/**
 * PHPMailer-Async-Proxy-Workerman — Workerman/Revolt-based async transport.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

use Closure;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

/**
 * Non-blocking transport that yields to the Workerman / Revolt event loop on
 * every I/O point — connect, read, write, TLS upgrade, close. Drop-in for
 * {@see StreamTransport} so SMTP keeps the same public surface.
 *
 * Implementation uses raw non-blocking stream sockets + Revolt watchers. That
 * matches what Workerman 5 itself uses under the hood (Workerman 5's Driver is
 * a Revolt driver), and avoids tying the SMTP protocol logic to the Workerman
 * `AsyncTcpConnection` abstraction.
 *
 * Every entry point is funneled through {@see FiberRunner::run()} so callers
 * outside a fiber (CLI, PHPUnit) still get correct synchronous-looking
 * behaviour via a private event loop run.
 */
final class WorkermanTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    private string $readBuffer = '';

    private int $readTimeout = 30;

    private ?Closure $errorSink = null;

    /** @var array{errno: int, errstr: string} */
    private array $connectError = ['errno' => 0, 'errstr' => ''];

    /** @var array{errno: int, errstr: string, errfile: string, errline: int} */
    private array $lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];

    private ?string $proxyProtocolHeader = null;

    private bool $eof = false;

    public function setErrorHandler(?callable $handler): void
    {
        $this->errorSink = $handler === null ? null : Closure::fromCallable($handler);
    }

    public function setProxyProtocolHeader(?string $bytes): void
    {
        $this->proxyProtocolHeader = $bytes;
    }

    public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool
    {
        return FiberRunner::run(function () use ($host, $port, $timeout, $contextOptions): bool {
            $errno = 0;
            $errstr = '';

            $this->installHandler();
            try {
                $context = stream_context_create($contextOptions);
                $sock = @stream_socket_client(
                    'tcp://' . $host . ':' . $port,
                    $errno,
                    $errstr,
                    (float) $timeout,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                    $context
                );
            } finally {
                $this->restoreHandler();
            }

            if (!is_resource($sock)) {
                $this->connectError = ['errno' => (int) $errno, 'errstr' => (string) $errstr];
                return false;
            }

            stream_set_blocking($sock, false);
            $this->socket = $sock;
            $this->eof = false;
            $this->readTimeout = $timeout;

            if (!$this->waitForConnected($timeout)) {
                $this->close();
                return false;
            }

            if ($this->proxyProtocolHeader !== null && $this->proxyProtocolHeader !== '') {
                $bytes = $this->proxyProtocolHeader;
                $written = $this->writeAll($bytes, $timeout);
                if ($written !== strlen($bytes)) {
                    $this->connectError = [
                        'errno' => 0,
                        'errstr' => sprintf(
                            'Failed to write PROXY protocol header (%d/%d bytes)',
                            $written,
                            strlen($bytes)
                        ),
                    ];
                    $this->close();
                    return false;
                }
            }

            $this->connectError = ['errno' => 0, 'errstr' => ''];
            return true;
        });
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->readBuffer = '';
        $this->eof = true;
    }

    public function isOpen(): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        if ($this->eof) {
            return false;
        }
        if ($this->readBuffer !== '') {
            return true; // bytes already pulled out of the kernel
        }
        return !feof($this->socket);
    }

    public function write(string $data)
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        return FiberRunner::run(function () use ($data) {
            return $this->writeAll($data, $this->readTimeout);
        });
    }

    public function readLine(int $maxLength): string
    {
        if (!is_resource($this->socket) && $this->readBuffer === '') {
            return '';
        }

        return FiberRunner::run(function () use ($maxLength): string {
            // Already have a complete line buffered?
            $line = $this->extractLine($maxLength);
            if ($line !== null) {
                return $line;
            }

            $deadline = $this->readTimeout > 0 ? microtime(true) + $this->readTimeout : null;
            while (true) {
                if (!$this->readChunk($deadline)) {
                    // Socket closed, timed out, or error — return whatever buffer holds
                    $line = $this->extractLine($maxLength);
                    if ($line !== null) {
                        return $line;
                    }
                    // Last-resort flush partial buffer to mimic fgets() EOF behaviour
                    if ($this->readBuffer !== '') {
                        $partial = substr($this->readBuffer, 0, $maxLength - 1);
                        $this->readBuffer = (string) substr($this->readBuffer, strlen($partial));
                        return $partial;
                    }
                    return '';
                }
                $line = $this->extractLine($maxLength);
                if ($line !== null) {
                    return $line;
                }
            }
        });
    }

    public function waitReadable(int $timeoutSeconds): ?bool
    {
        if (!is_resource($this->socket)) {
            return null;
        }
        if ($this->readBuffer !== '') {
            return true;
        }

        return FiberRunner::run(function () use ($timeoutSeconds): ?bool {
            $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
            return $this->readChunk($deadline) ? true : false;
        });
    }

    public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        return FiberRunner::run(function () use ($cryptoMethod, $timeout): bool {
            return $this->cryptoLoop($cryptoMethod, $timeout);
        });
    }

    /**
     * Drive a non-blocking TLS handshake. On a non-blocking stream,
     * stream_socket_enable_crypto() returns three states:
     *
     *   - true  — handshake complete, success
     *   - false — handshake failed (cert, protocol mismatch, etc.)
     *   - 0     — would block; the kernel needs more I/O — caller MUST
     *             re-call after the socket becomes readable AND/OR writable
     *
     * We loop on (0), suspending on EventLoop::onReadable + onWritable
     * (whichever fires first) up to the timeout, then retry the crypto
     * call. This is the documented pattern for streams-API non-blocking
     * TLS — see https://www.php.net/stream_socket_enable_crypto.
     */
    private function cryptoLoop(int $cryptoMethod, int $timeout): bool
    {
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        while (true) {
            $this->installHandler();
            try {
                $r = @stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
            } finally {
                $this->restoreHandler();
            }

            if ($r === true) {
                return true;
            }
            if ($r === false) {
                return false;
            }
            // $r === 0 — would block. Wait for readable OR writable, then retry.
            $remaining = $deadline === null ? null : ($deadline - microtime(true));
            if ($remaining !== null && $remaining <= 0) {
                $this->connectError = ['errno' => 0, 'errstr' => 'TLS handshake timed out'];
                return false;
            }

            $suspension = EventLoop::getSuspension();
            $readId = EventLoop::onReadable($this->socket, static function () use ($suspension): void {
                $suspension->resume('readable');
            });
            $writeId = EventLoop::onWritable($this->socket, static function () use ($suspension): void {
                $suspension->resume('writable');
            });
            $timerId = null;
            if ($remaining !== null) {
                $delay = $remaining > 0 ? $remaining : 0.001;
                $timerId = EventLoop::delay($delay, static function () use ($suspension): void {
                    $suspension->resume('timeout');
                });
            }
            try {
                $signal = $suspension->suspend();
            } finally {
                EventLoop::cancel($readId);
                EventLoop::cancel($writeId);
                if ($timerId !== null) {
                    EventLoop::cancel($timerId);
                }
            }
            if ($signal === 'timeout') {
                $this->connectError = ['errno' => 0, 'errstr' => 'TLS handshake timed out'];
                return false;
            }
            // loop: try crypto again on either readable or writable
        }
    }

    public function getMetadata(): array
    {
        if (!is_resource($this->socket)) {
            return ['timed_out' => false, 'eof' => true, 'blocked' => false];
        }
        $meta = stream_get_meta_data($this->socket);
        if ($this->eof) {
            $meta['eof'] = true;
        }
        return $meta;
    }

    public function setReadTimeout(int $seconds): void
    {
        $this->readTimeout = $seconds;
    }

    public function getConnectError(): array
    {
        return $this->connectError;
    }

    public function getLastWarning(): array
    {
        return $this->lastWarning;
    }

    public function clearLastWarning(): void
    {
        $this->lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];
    }

    public function getResource()
    {
        return $this->socket;
    }

    // --------------- internals ---------------

    /**
     * Block (in fiber-yielding form) on socket-writable until the async connect
     * has either succeeded or failed. Returns false if the connection failed.
     */
    private function waitForConnected(int $timeout): bool
    {
        $suspension = EventLoop::getSuspension();

        $writeId = EventLoop::onWritable($this->socket, static function () use ($suspension): void {
            $suspension->resume(true);
        });
        $timeoutId = EventLoop::delay((float) max(1, $timeout), static function () use ($suspension): void {
            $suspension->resume(false);
        });

        try {
            $ok = (bool) $suspension->suspend();
        } finally {
            EventLoop::cancel($writeId);
            EventLoop::cancel($timeoutId);
        }
        if (!$ok) {
            $this->connectError = ['errno' => 110, 'errstr' => 'Connection timed out'];
            return false;
        }

        // The socket is writable — confirm there was no async connect error.
        if (function_exists('socket_import_stream') && function_exists('socket_get_option')) {
            $importedSocket = @socket_import_stream($this->socket);
            if ($importedSocket !== false && $importedSocket !== null) {
                $err = @socket_get_option($importedSocket, SOL_SOCKET, SO_ERROR);
                if (is_int($err) && $err !== 0) {
                    $msg = function_exists('socket_strerror') ? socket_strerror($err) : ('errno ' . $err);
                    $this->connectError = ['errno' => $err, 'errstr' => (string) $msg];
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Pull as much as the kernel has buffered into $this->readBuffer. Suspends
     * the fiber on a readable watcher with a deadline. Returns false on EOF /
     * timeout / error.
     */
    private function readChunk(?float $deadline): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        $suspension = EventLoop::getSuspension();

        $readId = EventLoop::onReadable($this->socket, static function () use ($suspension): void {
            $suspension->resume('readable');
        });

        $remaining = $deadline === null ? null : max(0.0, $deadline - microtime(true));
        $timerId = null;
        if ($remaining !== null) {
            $delay = $remaining > 0 ? $remaining : 0.001;
            $timerId = EventLoop::delay($delay, static function () use ($suspension): void {
                $suspension->resume('timeout');
            });
        }

        try {
            $signal = $suspension->suspend();
        } finally {
            EventLoop::cancel($readId);
            if ($timerId !== null) {
                EventLoop::cancel($timerId);
            }
        }

        if ($signal !== 'readable') {
            return false; // timed out
        }

        $this->installHandler();
        try {
            $bytes = @fread($this->socket, 8192);
        } finally {
            $this->restoreHandler();
        }
        if ($bytes === false) {
            return false;
        }
        if ($bytes === '' && feof($this->socket)) {
            $this->eof = true;
            return false;
        }
        $this->readBuffer .= $bytes;
        return true;
    }

    /**
     * Extract one line up to $maxLength bytes from the read buffer if a
     * terminator is present (or we have $maxLength bytes already). Returns
     * null when no line is yet available.
     */
    private function extractLine(int $maxLength): ?string
    {
        if ($this->readBuffer === '') {
            return null;
        }
        $nlPos = strpos($this->readBuffer, "\n");
        if ($nlPos !== false && $nlPos < $maxLength - 1) {
            $line = substr($this->readBuffer, 0, $nlPos + 1);
            $this->readBuffer = (string) substr($this->readBuffer, $nlPos + 1);
            return $line;
        }
        if (strlen($this->readBuffer) >= $maxLength - 1) {
            $line = substr($this->readBuffer, 0, $maxLength - 1);
            $this->readBuffer = (string) substr($this->readBuffer, $maxLength - 1);
            return $line;
        }
        return null;
    }

    /**
     * Write the whole buffer, suspending on writable when the kernel returns
     * a short write. Returns total bytes written (0..strlen(\$data)).
     */
    private function writeAll(string $data, int $timeout): int
    {
        $total = 0;
        $remaining = $data;
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        while ($remaining !== '') {
            $this->installHandler();
            try {
                $written = @fwrite($this->socket, $remaining);
            } finally {
                $this->restoreHandler();
            }

            if ($written === false) {
                return $total;
            }
            if ($written > 0) {
                $total += $written;
                $remaining = (string) substr($remaining, $written);
                if ($remaining === '') {
                    return $total;
                }
            }

            // Short write or zero — wait for writable
            $suspension = EventLoop::getSuspension();
            $writeId = EventLoop::onWritable($this->socket, static function () use ($suspension): void {
                $suspension->resume('writable');
            });
            $timerId = null;
            if ($deadline !== null) {
                $r = max(0.0, $deadline - microtime(true));
                $timerId = EventLoop::delay($r > 0 ? $r : 0.001, static function () use ($suspension): void {
                    $suspension->resume('timeout');
                });
            }
            try {
                $signal = $suspension->suspend();
            } finally {
                EventLoop::cancel($writeId);
                if ($timerId !== null) {
                    EventLoop::cancel($timerId);
                }
            }
            if ($signal === 'timeout') {
                return $total;
            }
        }

        return $total;
    }

    private function installHandler(): void
    {
        if ($this->errorSink === null) {
            return;
        }
        $sink = $this->errorSink;
        $self = $this;
        set_error_handler(static function ($errno, $errmsg, $errfile = '', $errline = 0) use ($sink, $self): bool {
            $self->lastWarning = [
                'errno' => (int) $errno,
                'errstr' => (string) $errmsg,
                'errfile' => (string) $errfile,
                'errline' => (int) $errline,
            ];
            $sink($errno, $errmsg, $errfile, $errline);
            return true;
        });
    }

    private function restoreHandler(): void
    {
        if ($this->errorSink === null) {
            return;
        }
        restore_error_handler();
    }
}
