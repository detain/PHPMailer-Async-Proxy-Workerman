<?php

/**
 * PHPMailer-Async-Proxy-Workerman — blocking-stream transport.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

/**
 * Blocking-stream transport — mirrors upstream PHPMailer SMTP's classic socket
 * flow byte-for-byte: `stream_socket_client`/`fsockopen`, `fwrite`, `fgets`,
 * `stream_select`, `stream_socket_enable_crypto`, `fclose`. Used as the default
 * when Workerman is not loaded, and as the test-suite control transport.
 *
 * Behaviour is intentionally pre-Workerman so the entire upstream PHPUnit suite
 * keeps passing unchanged against this class.
 */
final class StreamTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    private int $readTimeout = 30;

    private ?\Closure $errorSink = null;

    /** @var array{errno: int, errstr: string} */
    private array $connectError = ['errno' => 0, 'errstr' => ''];

    /** @var array{errno: int, errstr: string, errfile: string, errline: int} */
    private array $lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];

    private ?string $proxyProtocolHeader = null;

    public function setErrorHandler(?callable $handler): void
    {
        $this->errorSink = $handler === null ? null : \Closure::fromCallable($handler);
    }

    public function setProxyProtocolHeader(?string $bytes): void
    {
        $this->proxyProtocolHeader = $bytes;
    }

    public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool
    {
        static $streamOk = null;
        if ($streamOk === null) {
            $streamOk = function_exists('stream_socket_client');
        }

        $errno = 0;
        $errstr = '';

        $this->installHandler();
        try {
            if ($streamOk) {
                $context = stream_context_create($contextOptions);
                $this->socket = @stream_socket_client(
                    $host . ':' . $port,
                    $errno,
                    $errstr,
                    (float) $timeout,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            } else {
                $this->socket = @fsockopen($host, $port, $errno, $errstr, (float) $timeout);
            }
        } finally {
            $this->restoreHandler();
        }

        if (!is_resource($this->socket)) {
            $this->connectError = ['errno' => (int) $errno, 'errstr' => (string) $errstr];
            $this->socket = null;
            return false;
        }

        // Match upstream SMTP::getSMTPConnection() — Windows skips stream_set_timeout/set_time_limit
        if (strpos(PHP_OS, 'WIN') !== 0) {
            $max = (int) ini_get('max_execution_time');
            $disabled = (string) ini_get('disable_functions');
            if ($max !== 0 && $timeout > $max && strpos($disabled, 'set_time_limit') === false) {
                @set_time_limit($timeout);
            }
            stream_set_timeout($this->socket, $timeout, 0);
        }

        $this->readTimeout = $timeout;
        $this->connectError = ['errno' => 0, 'errstr' => ''];

        if ($this->proxyProtocolHeader !== null && $this->proxyProtocolHeader !== '') {
            $bytes = $this->proxyProtocolHeader;
            $expected = strlen($bytes);
            $written = $this->write($bytes);
            if ($written === false || $written !== $expected) {
                $this->connectError = [
                    'errno' => 0,
                    'errstr' => sprintf(
                        'Failed to write PROXY protocol header (%d/%d bytes)',
                        $written === false ? 0 : $written,
                        $expected
                    ),
                ];
                $this->close();
                return false;
            }
        }

        return true;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function isOpen(): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        return !feof($this->socket);
    }

    public function write(string $data)
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        $this->installHandler();
        try {
            return fwrite($this->socket, $data);
        } finally {
            $this->restoreHandler();
        }
    }

    public function readLine(int $maxLength): string
    {
        if (!is_resource($this->socket)) {
            return '';
        }
        $line = @fgets($this->socket, $maxLength);
        return $line === false ? '' : $line;
    }

    public function waitReadable(int $timeoutSeconds): ?bool
    {
        if (!is_resource($this->socket)) {
            return null;
        }
        $read = [$this->socket];
        $write = null;
        $except = null;

        $this->installHandler();
        try {
            $n = @stream_select($read, $write, $except, $timeoutSeconds);
        } finally {
            $this->restoreHandler();
        }

        if ($n === false) {
            return null;
        }
        return $n > 0;
    }

    public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        $this->installHandler();
        try {
            $ok = stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
        } finally {
            $this->restoreHandler();
        }
        return (bool) $ok;
    }

    public function getMetadata(): array
    {
        if (!is_resource($this->socket)) {
            return ['timed_out' => false, 'eof' => true, 'blocked' => false];
        }
        return stream_get_meta_data($this->socket);
    }

    public function setReadTimeout(int $seconds): void
    {
        $this->readTimeout = $seconds;
        if (is_resource($this->socket) && strpos(PHP_OS, 'WIN') !== 0) {
            stream_set_timeout($this->socket, $seconds, 0);
        }
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
