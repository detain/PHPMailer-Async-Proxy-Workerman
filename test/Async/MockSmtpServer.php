<?php

/**
 * PHPMailer-Async-Proxy-Workerman — Mock SMTP server fixture.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

/**
 * Single-connection scripted TCP listener used by async transport tests.
 *
 * Lifecycle:
 *   1. parent binds a listening socket on 127.0.0.1:0 (kernel-picked port)
 *   2. parent forks; child takes over the listening socket and accepts ONE
 *      connection; parent gets back the port number.
 *   3. parent connects via the transport under test; child replays the
 *      configured script (one server line per matching client write).
 *   4. when parent calls stop(), child has already exited; the captured
 *      client transcript is read from a tempfile and returned.
 *
 * Designed for short-lived dialogues — one server lifetime per test.
 */
final class MockSmtpServer
{
    /** @var list<string> */
    private array $script = [];

    private int $port = 0;

    private string $transcriptFile;

    private int $childPid = 0;

    public function __construct()
    {
        $this->transcriptFile = (string) tempnam(sys_get_temp_dir(), 'mock-smtp-');
    }

    /**
     * Configure the server-side script. Each entry is sent verbatim (include
     * CRLF). The first entry is sent right after accept (the greeting); each
     * subsequent entry is sent on every client write that lands.
     *
     * @param list<string> $script
     */
    public function setScript(array $script): void
    {
        $this->script = $script;
    }

    public function start(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException('bind failed: ' . $errstr);
        }
        $name = stream_socket_get_name($server, false);
        [, $port] = explode(':', (string) $name);
        $this->port = (int) $port;

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('pcntl_fork failed');
        }
        if ($pid === 0) {
            $this->runChild($server);
            exit(0);
        }
        $this->childPid = $pid;
        // Parent doesn't need the listener — child owns it
        fclose($server);
        return $this->port;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Block until the child exits, then return the captured transcript.
     */
    public function stop(int $timeoutSeconds = 5): string
    {
        if ($this->childPid > 0) {
            $deadline = microtime(true) + $timeoutSeconds;
            do {
                $status = 0;
                $waited = pcntl_waitpid($this->childPid, $status, WNOHANG);
                if ($waited === $this->childPid) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    @posix_kill($this->childPid, SIGTERM);
                    pcntl_waitpid($this->childPid, $status);
                    break;
                }
                usleep(20_000);
            } while (true);
            $this->childPid = 0;
        }
        $transcript = @file_get_contents($this->transcriptFile);
        @unlink($this->transcriptFile);
        return $transcript === false ? '' : $transcript;
    }

    /**
     * @param resource $server
     */
    private function runChild($server): void
    {
        $client = @stream_socket_accept($server, 5);
        fclose($server);
        if ($client === false) {
            file_put_contents($this->transcriptFile, '');
            return;
        }

        $scriptCursor = 0;
        $sendNext = function () use ($client, &$scriptCursor): void {
            if ($scriptCursor < count($this->script)) {
                fwrite($client, $this->script[$scriptCursor]);
                $scriptCursor++;
            }
        };

        // greeting
        $sendNext();

        $buf = '';
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $r = [$client];
            $w = null;
            $e = null;
            $n = @stream_select($r, $w, $e, 0, 200_000);
            if ($n === false) {
                continue;
            }
            if ($n === 0) {
                if (feof($client)) {
                    break;
                }
                continue;
            }
            $chunk = @fread($client, 4096);
            if ($chunk === false || ($chunk === '' && feof($client))) {
                break;
            }
            if ($chunk !== '') {
                $buf .= $chunk;
                $sendNext();
                if (str_contains($buf, "QUIT\r\n")) {
                    break;
                }
            }
        }
        file_put_contents($this->transcriptFile, $buf);
        @fclose($client);
    }
}
