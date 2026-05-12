<?php

/**
 * PHPMailer-Async-Proxy-Workerman — Transport interface.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

/**
 * Byte-level transport contract used by {@see \PHPMailer\PHPMailer\SMTP}.
 *
 * Two implementations live alongside this interface:
 *
 *   - {@see StreamTransport}    — blocking stream_socket_client / fwrite / fgets,
 *                                 matches upstream PHPMailer behaviour byte-for-byte.
 *   - {@see WorkermanTransport} — non-blocking via Workerman 5 + Revolt event-loop +
 *                                 PHP Fibers (added in a later commit).
 *
 * The SMTP class delegates every socket I/O to a Transport, letting `SMTP::send()`
 * yield to a Workerman event-loop instead of stalling a worker process. Protocol
 * concerns (the 4th-char continuation rule, EHLO parsing, etc.) stay in SMTP.php.
 */
interface Transport
{
    /**
     * Open a TCP connection.
     *
     * On failure the implementation MUST populate {@see getConnectError()} with the
     * underlying errno/errstr (so callers can pass them up to PHPMailer's user code).
     *
     * @param string             $host           Hostname or IP.
     * @param int                $port           TCP port.
     * @param int                $timeout        Connect timeout, seconds.
     * @param array<string,mixed> $contextOptions Passed verbatim to stream_context_create()
     *                                            for the blocking transport. Ignored where it
     *                                            does not apply.
     */
    public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool;

    /**
     * Close the connection and release any underlying resource.
     */
    public function close(): void;

    /**
     * True iff the connection is open and not at EOF.
     */
    public function isOpen(): bool;

    /**
     * Write raw bytes.
     *
     * @return int|false Bytes written or false on failure.
     */
    public function write(string $data);

    /**
     * Read a single line (up to $maxLength bytes, or until LF).
     *
     * Returns the line *including* its terminator on success, '' on EOF/timeout
     * (caller should treat empty string as "no more input").
     */
    public function readLine(int $maxLength): string;

    /**
     * Block until input is available or the timeout expires.
     *
     * @return true|false|null true if readable, false on timeout, null on signal
     *                         interrupt / error — caller can inspect getLastWarning()
     *                         to decide whether to retry (matches the existing
     *                         SOCKET_EINTR retry path in SMTP::get_lines()).
     */
    public function waitReadable(int $timeoutSeconds): ?bool;

    /**
     * Upgrade the connection to TLS.
     *
     * @param int $cryptoMethod STREAM_CRYPTO_METHOD_* bitmask.
     * @param int $timeout      Handshake timeout (seconds). Only honoured by transports
     *                          that actually drive the handshake themselves.
     */
    public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool;

    /**
     * Per-connection metadata. Same shape as stream_get_meta_data() — implementations
     * MUST provide at least `timed_out`, `eof`, `blocked` keys.
     *
     * @return array<string,mixed>
     */
    public function getMetadata(): array;

    /**
     * Set the per-read timeout, in seconds.
     */
    public function setReadTimeout(int $seconds): void;

    /**
     * Connect-time error captured during the last {@see connect()} call.
     *
     * @return array{errno: int, errstr: string}
     */
    public function getConnectError(): array;

    /**
     * Last PHP warning captured by the transport, if any. Useful for matching
     * the existing SOCKET_EINTR retry logic without leaking a PHP error handler
     * up to the caller.
     *
     * @return array{errno: int, errstr: string, errfile: string, errline: int}
     */
    public function getLastWarning(): array;

    /**
     * Clear any captured warning state.
     */
    public function clearLastWarning(): void;

    /**
     * Underlying stream resource, when one exists. Provided for backward
     * compatibility with code that pokes SMTP::$smtp_conn directly. May return
     * null for transports that do not own a PHP stream (e.g. Workerman).
     *
     * @return resource|null
     */
    public function getResource();

    /**
     * Install (or clear, with null) a callback to receive PHP warnings raised
     * during any I/O on this transport. Mirrors PHPMailer's existing
     * `set_error_handler([$this, 'errorHandler'])` pattern so consumer code
     * keeps capturing connection-failure detail the same way it always has.
     */
    public function setErrorHandler(?callable $handler): void;
}
