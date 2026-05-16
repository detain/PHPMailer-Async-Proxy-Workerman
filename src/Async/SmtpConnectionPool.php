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
 *
 * ## Circuit Breaker
 *
 * The pool includes a per-key circuit breaker to prevent hammering a failing
 * SMTP server. When failures exceed the threshold, the circuit "opens" and
 * fails fast for a reset timeout period.
 *
 *     $pool = new SmtpConnectionPool();
 *     $pool->setCircuitBreaker(failureThreshold: 5, resetTimeout: 60);
 *
 * ## Observability Hooks
 *
 * Set callbacks to be notified of pool events:
 *
 *     $pool->setObservers(
 *         onAcquire: fn($smtp, $key) => $metrics->increment('smtp.acquire'),
 *         onRelease: fn($smtp, $key) => $metrics->increment('smtp.release'),
 *         onEvict: fn($smtp, $key) => $metrics->increment('smtp.evict'),
 *         onConnectFailure: fn($key, $error) => $alerts->alert('smtp failure')
 *     );
 *
 * ## Retry with Exponential Backoff
 *
 * The acquireOrNewWithRetry method automatically retries failed connections
 * with exponential backoff:
 *
 *     $smtp = $pool->acquireOrNewWithRetry($key, $factory, maxRetries: 3);
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
    private int $connectFailures = 0;

    // --- circuit breaker state ---

    /** @var array<string, array{open: bool, failures: int, lastFailure: float}> */
    private array $circuitState = [];

    private int $circuitFailureThreshold = 5;
    private float $circuitResetTimeout = 60.0;

    // --- observability hooks ---

    /** @var Closure|null */
    private ?Closure $onAcquire = null;

    /** @var Closure|null */
    private ?Closure $onRelease = null;

    /** @var Closure|null */
    private ?Closure $onEvict = null;

    /** @var Closure|null */
    private ?Closure $onConnectFailure = null;

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
     * Set circuit breaker parameters.
     *
     * @param int   $failureThreshold Number of failures before circuit opens
     * @param float $resetTimeout     Seconds before trying again after circuit opens
     */
    public function setCircuitBreaker(int $failureThreshold = 5, float $resetTimeout = 60.0): void
    {
        $this->circuitFailureThreshold = max(1, $failureThreshold);
        $this->circuitResetTimeout = max(0.1, $resetTimeout);
    }

    /**
     * Set observability hooks for pool events.
     *
     * @param Closure|null $onAcquire Called when a connection is acquired from
     *                                pool (fn(SMTP $smtp, string $key): void)
     * @param Closure|null $onRelease Called when a connection is released to
     *                                pool (fn(SMTP $smtp, string $key): void)
     * @param Closure|null $onEvict   Called when a connection is evicted
     *                                (fn(SMTP $smtp, string $key): void)
     * @param Closure|null $onConnectFailure Called when a connection attempt
     *                                       fails (fn(string $key, Throwable $e): void)
     */
    public function setObservers(
        ?Closure $onAcquire = null,
        ?Closure $onRelease = null,
        ?Closure $onEvict = null,
        ?Closure $onConnectFailure = null
    ): void {
        $this->onAcquire = $onAcquire;
        $this->onRelease = $onRelease;
        $this->onEvict = $onEvict;
        $this->onConnectFailure = $onConnectFailure;
    }

    /**
     * Check if circuit is open for a given key.
     */
    public function isCircuitOpen(string $key): bool
    {
        if (!isset($this->circuitState[$key])) {
            return false;
        }

        $state = $this->circuitState[$key];

        // If circuit is open and timeout hasn't expired, return true (fail fast)
        if ($state['open'] && (microtime(true) - $state['lastFailure']) < $this->circuitResetTimeout) {
            return true;
        }

        // Circuit is not open (still accumulating failures) - do NOT unset
        // Only unset when circuit was open and timeout expired (to allow retry)
        if ($state['open']) {
            unset($this->circuitState[$key]);
        }
        return false;
    }

    /**
     * Record a successful connection or operation.
     */
    public function recordSuccess(string $key): void
    {
        unset($this->circuitState[$key]);
    }

    /**
     * Record a connection failure.
     */
    public function recordFailure(string $key): void
    {
        if (!isset($this->circuitState[$key])) {
            $this->circuitState[$key] = [
                'open' => false,
                'failures' => 0,
                'lastFailure' => 0.0,
            ];
        }

        $state = &$this->circuitState[$key];
        $state['failures']++;
        $state['lastFailure'] = microtime(true);

        if ($state['failures'] >= $this->circuitFailureThreshold) {
            $state['open'] = true;
        }
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
     *
     * @throws \PHPMailer\PHPMailer\CircuitOpenException if circuit breaker is open for this key
     */
    public function acquireOrNew(string $key, Closure $newFactory): SMTP
    {
        // Check circuit breaker first
        if ($this->isCircuitOpen($key)) {
            throw new \PHPMailer\PHPMailer\CircuitOpenException(
                "SMTP circuit breaker open for '{$key}' - too many recent failures"
            );
        }

        while (!empty($this->idle[$key])) {
            $smtp = array_pop($this->idle[$key]);
            $since = array_pop($this->idleSince[$key]);

            if ((microtime(true) - (float) $since) > $this->idleTimeoutSec) {
                $this->safeClose($smtp, $key);
                continue;
            }
            if (!$smtp->connected()) {
                $this->safeClose($smtp, $key);
                continue;
            }
            if ($this->useNoopHealthCheck) {
                try {
                    if (!$smtp->noop()) {
                        $this->safeClose($smtp, $key);
                        continue;
                    }
                } catch (Throwable $t) {
                    $this->safeClose($smtp, $key);
                    continue;
                }
            }
            $this->acquireHits++;
            $this->invokeHook($this->onAcquire, $smtp, $key);
            return $smtp;
        }

        $this->acquireMisses++;
        $smtp = $newFactory();

        return $smtp;
    }

    /**
     * Acquire a connection with automatic retry and exponential backoff.
     *
     * If a connection failure occurs during the factory call or initial
     * health check, this method retries up to $maxRetries times with
     * exponential backoff between attempts.
     *
     * @param Closure(): SMTP $newFactory
     * @param int           $maxRetries Maximum number of retry attempts
     * @param float         $baseBackoff Base backoff time in seconds (doubles each retry)
     *
     * @return SMTP
     *
     * @throws \PHPMailer\PHPMailer\CircuitOpenException if circuit breaker is open
     * @throws \PHPMailer\PHPMailer\AllRetriesFailedException if all retries fail
     */
    public function acquireOrNewWithRetry(
        string $key,
        Closure $newFactory,
        int $maxRetries = 3,
        float $baseBackoff = 0.1
    ): SMTP {
        $lastError = null;
        $backoff = $baseBackoff;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            // Check circuit breaker before each attempt
            if ($this->isCircuitOpen($key)) {
                throw new \PHPMailer\PHPMailer\CircuitOpenException(
                    "SMTP circuit breaker open for '{$key}'"
                );
            }

            try {
                $smtp = $this->acquireOrNew($key, $newFactory);

                // Fresh instance returned — caller will call smtpConnect() to
                // establish the connection. Do NOT record success here since
                // the connection hasn't been proven working yet.

                return $smtp;
            } catch (\PHPMailer\PHPMailer\CircuitOpenException $t) {
                // Circuit breaker opened while trying to connect - re-throw immediately without catching
                throw $t;
            } catch (Throwable $t) {
                $lastError = $t;
                $this->recordFailure($key);
                $this->connectFailures++;

                // Invoke failure hook
                $this->invokeHook($this->onConnectFailure, $key, $t);

                // If circuit is now open, stop retrying
                if ($this->isCircuitOpen($key)) {
                    throw new \PHPMailer\PHPMailer\CircuitOpenException(
                        "SMTP circuit breaker opened for '{$key}' after {$attempt} failures"
                    );
                }

                // If we have retries left, wait with exponential backoff
                if ($attempt < $maxRetries) {
                    usleep((int)($backoff * 1000000));
                    $backoff *= 2; // Exponential backoff
                }
            }
        }

        // All retries exhausted
        $errorMsg = $lastError ? $lastError->getMessage() : 'unknown error';
        throw new \PHPMailer\PHPMailer\AllRetriesFailedException(
            "All {$maxRetries} retries failed for '{$key}': {$errorMsg}"
        );
    }

    /**
     * Return $smtp to the pool under $key. Issues an RSET to clear any
     * in-flight transaction state; closes the connection if RSET fails or
     * the connection is already dead.
     */
    public function release(string $key, SMTP $smtp): void
    {
        if (!$smtp->connected()) {
            $this->recordFailure($key);
            $this->safeClose($smtp, $key);
            return;
        }
        try {
            if (!$smtp->reset()) {
                $this->recordFailure($key);
                $this->safeClose($smtp, $key);
                return;
            }
        } catch (Throwable $t) {
            $this->recordFailure($key);
            $this->safeClose($smtp, $key);
            return;
        }

        // Connection is healthy — record success before adding to pool
        $this->recordSuccess($key);

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
                $this->safeClose($oldest, $key);
                $this->evictions++;
                $this->invokeHook($this->onEvict, $oldest, $key);
            }
        }
        $this->idle[$key][] = $smtp;
        $this->idleSince[$key][] = microtime(true);
        $this->releases++;
        $this->invokeHook($this->onRelease, $smtp, $key);
    }

    /**
     * Close every pooled SMTP across every key. Idempotent.
     */
    public function closeAll(): void
    {
        foreach ($this->idle as $key => $list) {
            foreach ($list as $smtp) {
                $this->safeClose($smtp, (string) $key);
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
     *   - connectFailures: connection attempts that failed (via retry logic)
     *   - hitRatio      : acquireHits / max(1, acquireHits + acquireMisses)
     *   - idleNow       : current total idle entries across all keys
     *
     * @return array{
     *     acquireHits: int,
     *     acquireMisses: int,
     *     releases: int,
     *     evictions: int,
     *     connectFailures: int,
     *     hitRatio: float,
     *     idleNow: int,
     * }
     */
    public function stats(): array
    {
        $total = $this->acquireHits + $this->acquireMisses;
        return [
            'acquireHits'     => $this->acquireHits,
            'acquireMisses'   => $this->acquireMisses,
            'releases'        => $this->releases,
            'evictions'       => $this->evictions,
            'connectFailures' => $this->connectFailures,
            'hitRatio'        => $total === 0 ? 0.0 : $this->acquireHits / $total,
            'idleNow'         => $this->idleCount(),
        ];
    }

    /**
     * Get circuit breaker state for all keys.
     *
     * @return array<string, array{open: bool, failures: int, lastFailure: float, timeUntilReset: float|null}>
     */
    public function getCircuitBreakerState(): array
    {
        $result = [];
        $now = microtime(true);

        foreach ($this->circuitState as $key => $state) {
            $timeUntilReset = null;
            if ($state['open']) {
                $timeUntilReset = max(0, $this->circuitResetTimeout - ($now - $state['lastFailure']));
            }

            $result[$key] = [
                'open' => $state['open'],
                'failures' => $state['failures'],
                'lastFailure' => $state['lastFailure'],
                'timeUntilReset' => $timeUntilReset,
            ];
        }

        return $result;
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
     * Reset all counters (useful for testing or metrics rotation).
     */
    public function resetCounters(): void
    {
        $this->acquireHits = 0;
        $this->acquireMisses = 0;
        $this->releases = 0;
        $this->evictions = 0;
        $this->connectFailures = 0;
    }

    /**
     * Try a clean QUIT then close. Swallows exceptions — the pool's job is
     * to make sure no caller ever sees a half-dead connection, not to
     * surface remote-side teardown errors.
     */
    private function safeClose(SMTP $smtp, string $key): void
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

    /**
     * Invoke a hook callback if it's set, with proper error handling.
     *
     * @param Closure|null $hook
     * @param mixed        ...$args
     */
    private function invokeHook(?Closure $hook, ...$args): void
    {
        if ($hook === null) {
            return;
        }

        try {
            $hook(...$args);
        } catch (Throwable $t) {
            // Swallow hook errors - don't let them disrupt pool operations
        }
    }
}
