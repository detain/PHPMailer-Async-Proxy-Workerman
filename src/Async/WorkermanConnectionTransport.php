<?php

/**
 * PHPMailer-Async-Proxy-Workerman — AsyncTcpConnection-backed transport.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

use Closure;
use Fiber;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver as RevoltDriver;
use Revolt\EventLoop\Suspension;
use RuntimeException;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Events\Fiber as WorkermanFiberEvents;
use Workerman\Worker;

/**
 * Non-blocking transport built directly on Workerman's own
 * {@see AsyncTcpConnection}. Reads use Workerman's `onMessage` callback to
 * accumulate into a buffer; the read methods on this class fiber-suspend
 * until enough data is present or a timeout fires. Writes go through
 * `AsyncTcpConnection::send()` which already handles backpressure /
 * partial writes via Workerman's send buffer.
 *
 * The win over the Revolt-only {@see WorkermanTransport}: when a real
 * Workerman worker is hosting this code, the connection plugs into the
 * worker's existing event loop, lifecycle, statistics, and the loop's
 * native I/O multiplexer (libev/libuv via Workerman's Event/Ev drivers).
 *
 * ## Usage contract
 *
 * Unlike {@see WorkermanTransport} (which wraps each method in its own
 * `FiberRunner::run` private loop), this transport REQUIRES a single
 * long-lived event-loop / fiber context across the whole SMTP session.
 * `AsyncTcpConnection` registers a long-lived read watcher in `connect()`;
 * that watcher dies if the event-loop driver changes between calls —
 * which is exactly what `FiberRunner`'s private-loop pattern does.
 *
 * In a real Workerman worker the loop is always alive. In PHPUnit / CLI
 * scripts, wrap the whole session in **one** `FiberRunner::run()`:
 *
 *     FiberRunner::run(function () use ($smtp) {
 *         $smtp->connect(...);
 *         $smtp->hello(...);
 *         $smtp->mail(...);
 *         // ...
 *         $smtp->quit();
 *         $smtp->close();
 *     });
 *
 * Calling any method outside a fiber raises {@see RuntimeException} rather
 * than silently hanging.
 */
final class WorkermanConnectionTransport implements Transport
{
    private ?AsyncTcpConnection $conn = null;

    private string $readBuffer = '';

    private ?Suspension $readWaiter = null;

    private bool $eofSignalled = false;

    private int $readTimeout = 30;

    /**
     * @phpstan-ignore-next-line property.onlyWritten — kept for symmetry with the other transports
     */
    private ?Closure $errorSink = null;

    private ?string $proxyProtocolHeader = null;

    /** @var array{errno: int, errstr: string} */
    private array $connectError = ['errno' => 0, 'errstr' => ''];

    /** @var array{errno: int, errstr: string, errfile: string, errline: int} */
    private array $lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];

    // --- shared global-event-loop binding ---

    /** Tracks the Revolt driver our cached Worker::$globalEvent was bound to. */
    private static ?RevoltDriver $boundRevoltDriver = null;

    /** The Workerman event-loop adapter we installed (so we can detect a real Worker overriding it). */
    private static ?EventInterface $ourEventLoop = null;

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
        $this->assertInsideFiber(__METHOD__);
        self::ensureWorkermanEventLoop();

        // PHPMailer prepends `ssl://` (or `tls://`) onto the host when SMTPSecure
        // is set. Three paths:
        //   1. ssl/tls scheme + PROXY header  -> strip scheme, open plain TCP,
        //                                       write PROXY first, then upgrade
        //                                       manually via cryptoLoop().
        //   2. ssl/tls scheme + no PROXY      -> let Workerman do the handshake
        //                                       natively by passing the scheme
        //                                       through (NOT `tcp://`).
        //   3. no scheme                       -> plain TCP via `tcp://host:port`.
        $deferredCryptoMethod = null;
        $connectionUri = 'tcp://' . $host . ':' . $port;
        if (preg_match('#^(ssl|tls)://(.+)$#i', $host, $matches) === 1) {
            $scheme = strtolower($matches[1]);
            $bareHost = $matches[2];
            if ($this->proxyProtocolHeader !== null && $this->proxyProtocolHeader !== '') {
                $connectionUri = 'tcp://' . $bareHost . ':' . $port;
                $deferredCryptoMethod = $this->resolveImplicitCryptoMethod($contextOptions);
            } else {
                $connectionUri = $scheme . '://' . $bareHost . ':' . $port;
            }
        }

        try {
            $this->conn = new AsyncTcpConnection($connectionUri, $contextOptions);
        } catch (Throwable $t) {
            $this->connectError = ['errno' => 0, 'errstr' => $t->getMessage()];
            $this->conn = null;
            return false;
        }

        $suspension = EventLoop::getSuspension();
        $resolved = false;
        $resolve = function (bool $ok, string $errStr = '', int $errNo = 0) use ($suspension, &$resolved): void {
            if ($resolved) {
                return;
            }
            $resolved = true;
            if (!$ok) {
                $this->connectError = ['errno' => $errNo, 'errstr' => $errStr];
            }
            $suspension->resume($ok);
        };

        $this->conn->onConnect = function () use ($resolve): void {
            $resolve(true);
        };
        $this->conn->onError = function ($conn, $code, $msg) use ($resolve): void {
            $resolve(false, (string) $msg, (int) $code);
        };
        $this->conn->onMessage = $this->messageHandler();
        $this->conn->onClose = $this->closeHandler();

        $timeoutId = EventLoop::delay((float) max(1, $timeout), static function () use ($resolve): void {
            $resolve(false, 'Connection timed out', 110);
        });

        $this->conn->connect();

        try {
            $ok = (bool) $suspension->suspend();
        } finally {
            EventLoop::cancel($timeoutId);
        }

        if (!$ok) {
            $this->close();
            return false;
        }

        $this->readTimeout = $timeout;
        $this->eofSignalled = false;

        if ($this->proxyProtocolHeader !== null && $this->proxyProtocolHeader !== '') {
            $bytes = $this->proxyProtocolHeader;
            if (!$this->writeAll($bytes, $timeout)) {
                $this->connectError = [
                    'errno' => 0,
                    'errstr' => 'Failed to write PROXY protocol header',
                ];
                $this->close();
                return false;
            }
        }

        if ($deferredCryptoMethod !== null) {
            if (!$this->enableCryptoInternal($deferredCryptoMethod, $timeout)) {
                $this->connectError = [
                    'errno' => 0,
                    'errstr' => 'Implicit TLS handshake failed after PROXY header',
                ];
                $this->close();
                return false;
            }
        }

        return true;
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            try {
                $this->conn->close();
            } catch (Throwable $t) {
                // ignored — best-effort cleanup
            }
            $this->conn = null;
        }
        $this->readBuffer = '';
        $this->eofSignalled = true;
        $waiter = $this->readWaiter;
        $this->readWaiter = null;
        if ($waiter !== null) {
            try {
                $waiter->resume('eof');
            } catch (Throwable $t) {
                // ignored — the suspension may already be resolved
            }
        }
    }

    public function isOpen(): bool
    {
        if ($this->conn === null) {
            return false;
        }
        if ($this->readBuffer !== '') {
            return true;
        }
        return !$this->eofSignalled;
    }

    public function write(string $data)
    {
        if ($this->conn === null) {
            return false;
        }
        $this->assertInsideFiber(__METHOD__);
        $written = $this->writeAll($data, $this->readTimeout);
        return $written ?: false;
    }

    public function readLine(int $maxLength): string
    {
        if ($this->conn === null && $this->readBuffer === '') {
            return '';
        }
        $this->assertInsideFiber(__METHOD__);

        $line = $this->extractLine($maxLength);
        if ($line !== null) {
            return $line;
        }
        $deadline = $this->readTimeout > 0 ? microtime(true) + $this->readTimeout : null;
        while (true) {
            if (!$this->waitForData($deadline)) {
                $line = $this->extractLine($maxLength);
                if ($line !== null) {
                    return $line;
                }
                if ($this->readBuffer !== '') {
                    // Flush whatever's left on EOF, like fgets().
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
    }

    public function waitReadable(int $timeoutSeconds): ?bool
    {
        if ($this->conn === null) {
            return null;
        }
        if ($this->readBuffer !== '') {
            return true;
        }
        $this->assertInsideFiber(__METHOD__);
        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
        return $this->waitForData($deadline);
    }

    public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool
    {
        if ($this->conn === null) {
            return false;
        }
        $this->assertInsideFiber(__METHOD__);
        return $this->enableCryptoInternal($cryptoMethod, $timeout);
    }

    public function getMetadata(): array
    {
        if ($this->conn === null) {
            return ['timed_out' => false, 'eof' => true, 'blocked' => false];
        }
        $socket = $this->conn->getSocket();
        if (!is_resource($socket)) {
            return ['timed_out' => false, 'eof' => $this->eofSignalled, 'blocked' => false];
        }
        $meta = stream_get_meta_data($socket);
        if ($this->eofSignalled) {
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
        return $this->conn === null ? null : $this->conn->getSocket();
    }

    // ----------------- internals -----------------

    /**
     * Ensure Worker::$globalEvent is bound to a Revolt-backed event loop
     * that matches the current Revolt driver. This lets AsyncTcpConnection
     * work in any of three contexts:
     *
     *   - inside a real running Workerman Worker — leave the worker's loop alone
     *   - inside a single long-lived FiberRunner::run() — bind ours once
     *   - across multiple FiberRunner::run() calls — see class docblock; this
     *     transport doesn't support that case (private driver dies between
     *     calls), so subsequent calls would throw via the in-fiber guard.
     */
    private static function ensureWorkermanEventLoop(): void
    {
        $currentDriver = EventLoop::getDriver();

        if (isset(Worker::$globalEvent) && Worker::$globalEvent !== self::$ourEventLoop) {
            // Real Worker owns the loop. Trust it.
            self::$boundRevoltDriver = $currentDriver;
            return;
        }

        if (self::$boundRevoltDriver === $currentDriver && self::$ourEventLoop !== null) {
            Worker::$globalEvent = self::$ourEventLoop;
            return;
        }

        self::$ourEventLoop = new WorkermanFiberEvents();
        Worker::$globalEvent = self::$ourEventLoop;
        self::$boundRevoltDriver = $currentDriver;
    }

    /**
     * @throws RuntimeException when called outside an active fiber.
     */
    private function assertInsideFiber(string $method): void
    {
        if (Fiber::getCurrent() === null) {
            throw new RuntimeException(
                $method . '() must be called from inside a fiber. Wrap the SMTP session in '
                . 'FiberRunner::run(...) — see WorkermanConnectionTransport class docblock.'
            );
        }
    }

    /** @return Closure(\Workerman\Connection\TcpConnection, string): void */
    private function messageHandler(): Closure
    {
        return function ($conn, string $data): void {
            $this->readBuffer .= $data;
            $waiter = $this->readWaiter;
            $this->readWaiter = null;
            if ($waiter !== null) {
                try {
                    $waiter->resume('readable');
                } catch (Throwable $t) {
                    // already resolved — ignore
                }
            }
        };
    }

    /** @return Closure(\Workerman\Connection\TcpConnection): void */
    private function closeHandler(): Closure
    {
        return function ($conn): void {
            $this->eofSignalled = true;
            $waiter = $this->readWaiter;
            $this->readWaiter = null;
            if ($waiter !== null) {
                try {
                    $waiter->resume('eof');
                } catch (Throwable $t) {
                    // ignore
                }
            }
        };
    }

    private function extractLine(int $maxLength): ?string
    {
        if ($this->readBuffer === '') {
            return null;
        }
        $nl = strpos($this->readBuffer, "\n");
        if ($nl !== false && $nl < $maxLength - 1) {
            $line = substr($this->readBuffer, 0, $nl + 1);
            $this->readBuffer = (string) substr($this->readBuffer, $nl + 1);
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
     * Suspend the current fiber until onMessage delivers data or the deadline
     * expires. Returns true if data was buffered, false on timeout/EOF.
     */
    private function waitForData(?float $deadline): bool
    {
        if ($this->readBuffer !== '') {
            return true;
        }
        if ($this->conn === null || $this->eofSignalled) {
            return false;
        }

        $suspension = EventLoop::getSuspension();
        $this->readWaiter = $suspension;

        $timerId = null;
        if ($deadline !== null) {
            $delay = max(0.0, $deadline - microtime(true));
            $timerId = EventLoop::delay($delay > 0 ? $delay : 0.001, function () use ($suspension): void {
                if ($this->readWaiter === $suspension) {
                    $this->readWaiter = null;
                    try {
                        $suspension->resume('timeout');
                    } catch (Throwable $t) {
                        // ignore — already resolved
                    }
                }
            });
        }

        try {
            $signal = $suspension->suspend();
        } finally {
            $this->readWaiter = null;
            if ($timerId !== null) {
                EventLoop::cancel($timerId);
            }
        }

        return $signal === 'readable';
    }

    /**
     * Write the whole buffer through `AsyncTcpConnection::send()` and wait
     * for the kernel to actually drain it via onBufferDrain when Workerman
     * had to buffer internally. Returns true on success.
     */
    private function writeAll(string $data, int $timeout): bool
    {
        if ($this->conn === null) {
            return false;
        }
        // send($data, true) — second arg "raw" skips protocol encoding.
        $result = $this->conn->send($data, true);
        if ($result === false) {
            return false;
        }
        if ($result === true) {
            return true;
        }
        // $result === null — Workerman buffered the data. Suspend on
        // onBufferDrain (with a timeout) so the caller knows the write
        // actually went out the door.
        $suspension = EventLoop::getSuspension();
        $resolved = false;
        $resolve = static function (bool $ok) use ($suspension, &$resolved): void {
            if ($resolved) {
                return;
            }
            $resolved = true;
            $suspension->resume($ok);
        };

        $previousDrain = $this->conn->onBufferDrain ?? null;
        $this->conn->onBufferDrain = function ($conn) use ($resolve, $previousDrain): void {
            $resolve(true);
            $conn->onBufferDrain = $previousDrain;
        };
        $previousError = $this->conn->onError ?? null;
        $this->conn->onError = function ($conn, $code, $msg) use ($resolve, $previousError): void {
            $resolve(false);
            $conn->onError = $previousError;
        };

        $timerId = EventLoop::delay((float) max(1, $timeout), static function () use ($resolve): void {
            $resolve(false);
        });
        try {
            return (bool) $suspension->suspend();
        } finally {
            EventLoop::cancel($timerId);
        }
    }

    /**
     * Drive a non-blocking TLS handshake on the raw socket. Mirrors the
     * cryptoLoop in {@see WorkermanTransport} — onReadable + exponential
     * backoff. We pauseRecv() while the handshake runs so Workerman's
     * internal read loop doesn't try to dispatch encrypted bytes as
     * application data.
     */
    private function enableCryptoInternal(int $cryptoMethod, int $timeout): bool
    {
        if ($this->conn === null) {
            return false;
        }
        $socket = $this->conn->getSocket();
        if (!is_resource($socket)) {
            return false;
        }

        $this->conn->pauseRecv();
        try {
            $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
            $backoff = 0.001;

            while (true) {
                $r = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if ($r === true) {
                    return true;
                }
                if ($r === false) {
                    return false;
                }
                $remaining = $deadline === null ? null : ($deadline - microtime(true));
                if ($remaining !== null && $remaining <= 0) {
                    $this->connectError = ['errno' => 0, 'errstr' => 'TLS handshake timed out'];
                    return false;
                }

                $delay = $backoff;
                if ($remaining !== null && $delay > $remaining) {
                    $delay = $remaining;
                }
                $suspension = EventLoop::getSuspension();
                $readId = EventLoop::onReadable($socket, static function () use ($suspension): void {
                    $suspension->resume('readable');
                });
                $timerId = EventLoop::delay(
                    $delay > 0 ? $delay : 0.001,
                    static function () use ($suspension): void {
                        $suspension->resume('backoff');
                    }
                );
                try {
                    $suspension->suspend();
                } finally {
                    EventLoop::cancel($readId);
                    EventLoop::cancel($timerId);
                }
                $backoff = min($backoff * 2, 0.05);
            }
        } finally {
            if ($this->conn !== null) {
                $this->conn->resumeRecv();
            }
        }
    }

    /**
     * Resolve the crypto-method bitmask for the deferred-TLS path. Honors
     * an explicit `ssl.crypto_method` / `tls.crypto_method` from the
     * caller's stream-context options (PHPMailer's `SMTPOptions`), so a
     * caller that locked the protocol set on stock PHPMailer keeps that
     * lock after the PROXY-before-TLS scheme swap. Falls back to
     * TLS_CLIENT + 1.1/1.2 when nothing was supplied.
     *
     * @param array<string,mixed> $contextOptions
     */
    private function resolveImplicitCryptoMethod(array $contextOptions = []): int
    {
        foreach (['ssl', 'tls'] as $bucket) {
            if (
                isset($contextOptions[$bucket]['crypto_method'])
                && is_int($contextOptions[$bucket]['crypto_method'])
            ) {
                return (int) $contextOptions[$bucket]['crypto_method'];
            }
        }

        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        return $method;
    }
}
