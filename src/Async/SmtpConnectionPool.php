<?php

/**
 * PHPMailer-Async-Proxy-Workerman — process-local SMTP connection pool.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

use Closure;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

/**
 * Reusable pool of connected + authenticated {@see SMTP} instances, keyed by
 * an opaque string the caller chooses (typically "host:port:username").
 *
 * Usage:
 *
 *     $pool = new SmtpConnectionPool(maxPerKey: 8, idleTimeoutSec: 60);
 *
 *     $key = "smtp.example.com:25:alice";
 *     $smtp = $pool->acquireOrNew($key, function () {
 *         $s = new SMTP();
 *         $s->setTransport(new WorkermanConnectionTransport());
 *         return $s;
 *     });
 *
 *     if (!$smtp->connected()) {
 *         // Fresh instance — caller does the connect / hello / authenticate dance
 *         $smtp->connect('smtp.example.com', 25);
 *         $smtp->hello('client.example');
 *         $smtp->authenticate('alice', $pass);
 *     }
 *     // ... mail() / recipient() / data() ...
 *     $pool->release($key, $smtp);
 *
 * The pool is **process-local**. In a Workerman worker that means each worker
 * keeps its own pool — which is the right granularity since worker processes
 * never share file descriptors.
 *
 * ## PROXY-protocol interaction
 *
 * A pooled connection sent its PROXY header once at connect time, advertising
 * a specific peer. Reusing it for a *different* end-user would silently lie
 * to the relay about the source IP. **The pool intentionally has no PROXY
 * awareness:** callers that flip PROXY on per-request should pick a key that
 * uniquely identifies the (peer-IP, peer-port) tuple, or — much simpler —
 * bypass the pool entirely when PROXY is active.
 *
 * Examples of safe keys:
 *
 *  - "host:port:user"                        — PROXY disabled
 *  - "host:port:user:srcip:srcport"          — PROXY enabled, per-peer pool
 */
final class SmtpConnectionPool
{
    /** @var array<string, list<SMTP>> */
    private array $idle = [];

    /** @var array<string, list<float>> idle-since timestamps parallel to $idle */
    private array $idleSince = [];

    private int $maxPerKey;
    private float $idleTimeoutSec;
    private bool $useNoopHealthCheck;

    // --- counters (read-only metrics for ops / smoke tests) ---

    private int $acquireHits = 0;
    private int $acquireMisses = 0;
    private int $releases = 0;
    private int $evictions = 0;

    public function __construct(
        int $maxPerKey = 8,
        float $idleTimeoutSec = 60.0,
        bool $useNoopHealthCheck = true
    ) {
        $this->maxPerKey = max(1, $maxPerKey);
        $this->idleTimeoutSec = $idleTimeoutSec;
        $this->useNoopHealthCheck = $useNoopHealthCheck;
    }

    /**
     * Pop the most recently used idle entry under $key if it's still healthy,
     * otherwise call $newFactory() and return a fresh instance.
     *
     * The returned SMTP is either:
     *   - connected + authenticated (pool hit; skip handshake/auth),
     *   - or freshly constructed and NOT connected (pool miss; caller does
     *     the full connect/hello/auth dance).
     *
     * `$newFactory` is only invoked on a pool miss.
     *
     * @param Closure(): SMTP $newFactory
     */
    public function acquireOrNew(string $key, Closure $newFactory): SMTP
    {
        while (!empty($this->idle[$key])) {
            $smtp = array_pop($this->idle[$key]);
            $since = array_pop($this->idleSince[$key]);

            if ((microtime(true) - (float) $since) > $this->idleTimeoutSec) {
                $this->safeClose($smtp);
                continue;
            }
            if (!$smtp->connected()) {
                $this->safeClose($smtp);
                continue;
            }
            if ($this->useNoopHealthCheck) {
                try {
                    if (!$smtp->noop()) {
                        $this->safeClose($smtp);
                        continue;
                    }
                } catch (Throwable $t) {
                    $this->safeClose($smtp);
                    continue;
                }
            }
            $this->acquireHits++;
            return $smtp;
        }

        $this->acquireMisses++;
        return $newFactory();
    }

    /**
     * Return $smtp to the pool under $key. Issues an RSET to clear any
     * in-flight transaction state; closes the connection if RSET fails or
     * the connection is already dead.
     */
    public function release(string $key, SMTP $smtp): void
    {
        if (!$smtp->connected()) {
            return;
        }
        try {
            if (!$smtp->reset()) {
                $this->safeClose($smtp);
                return;
            }
        } catch (Throwable $t) {
            $this->safeClose($smtp);
            return;
        }

        if (!isset($this->idle[$key])) {
            $this->idle[$key] = [];
            $this->idleSince[$key] = [];
        }
        if (count($this->idle[$key]) >= $this->maxPerKey) {
            // Over capacity — close the LRU end instead of refusing the
            // freshest one (keeps the warm pool warmer).
            $oldest = array_shift($this->idle[$key]);
            array_shift($this->idleSince[$key]);
            if ($oldest !== null) {
                $this->safeClose($oldest);
                $this->evictions++;
            }
        }
        $this->idle[$key][] = $smtp;
        $this->idleSince[$key][] = microtime(true);
        $this->releases++;
    }

    /**
     * Close every pooled SMTP across every key. Idempotent.
     */
    public function closeAll(): void
    {
        foreach ($this->idle as $list) {
            foreach ($list as $smtp) {
                $this->safeClose($smtp);
            }
        }
        $this->idle = [];
        $this->idleSince = [];
    }

    /**
     * Number of idle pooled entries (for diagnostics / metrics).
     */
    public function idleCount(?string $key = null): int
    {
        if ($key !== null) {
            return isset($this->idle[$key]) ? count($this->idle[$key]) : 0;
        }
        $sum = 0;
        foreach ($this->idle as $list) {
            $sum += count($list);
        }
        return $sum;
    }

    /**
     * Cumulative pool counters since construction. Useful for ops dashboards
     * and smoke tests. Fields:
     *
     *   - acquireHits   : pool hits that returned a healthy session
     *   - acquireMisses : pool misses that called the new-factory closure
     *   - releases      : entries handed back via release()
     *   - evictions     : entries closed because the per-key cap overflowed
     *   - hitRatio      : acquireHits / max(1, acquireHits + acquireMisses)
     *   - idleNow       : current total idle entries across all keys
     *
     * @return array{
     *     acquireHits: int,
     *     acquireMisses: int,
     *     releases: int,
     *     evictions: int,
     *     hitRatio: float,
     *     idleNow: int,
     * }
     */
    public function stats(): array
    {
        $total = $this->acquireHits + $this->acquireMisses;
        return [
            'acquireHits'   => $this->acquireHits,
            'acquireMisses' => $this->acquireMisses,
            'releases'      => $this->releases,
            'evictions'     => $this->evictions,
            'hitRatio'      => $total === 0 ? 0.0 : $this->acquireHits / $total,
            'idleNow'       => $this->idleCount(),
        ];
    }

    /**
     * Keys currently holding at least one idle entry. Order is not stable.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $out = [];
        foreach ($this->idle as $k => $list) {
            if (!empty($list)) {
                $out[] = (string) $k;
            }
        }
        return $out;
    }

    /**
     * Try a clean QUIT then close. Swallows exceptions — the pool's job is
     * to make sure no caller ever sees a half-dead connection, not to
     * surface remote-side teardown errors.
     */
    private function safeClose(SMTP $smtp): void
    {
        try {
            if ($smtp->connected()) {
                @$smtp->quit();
            }
        } catch (Throwable $t) {
            // ignored
        }
        try {
            $smtp->close();
        } catch (Throwable $t) {
            // ignored
        }
    }
}
