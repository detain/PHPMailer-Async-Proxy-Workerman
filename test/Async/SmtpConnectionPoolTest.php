<?php

/**
 * PHPMailer-Async-Proxy-Workerman — SmtpConnectionPool tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\SmtpConnectionPool;
use PHPMailer\PHPMailer\SMTP;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SmtpConnectionPoolTest extends TestCase
{
    public function testPoolMissCallsFactoryOnce(): void
    {
        $pool = new SmtpConnectionPool();
        $calls = 0;

        $smtp = $pool->acquireOrNew('host:25:alice', function () use (&$calls) {
            $calls++;
            return new FakePoolSmtp(); // not connected
        });

        self::assertSame(1, $calls);
        self::assertInstanceOf(FakePoolSmtp::class, $smtp);
    }

    public function testReleaseAndReAcquireReusesSameInstance(): void
    {
        $pool = new SmtpConnectionPool();
        $first = new FakePoolSmtp(connected: true);

        $pool->release('host:25:alice', $first);
        self::assertSame(1, $pool->idleCount('host:25:alice'));

        $factoryCalls = 0;
        $second = $pool->acquireOrNew('host:25:alice', function () use (&$factoryCalls) {
            $factoryCalls++;
            return new FakePoolSmtp(connected: true);
        });

        self::assertSame(0, $factoryCalls, 'factory must not run on a pool hit');
        self::assertSame($first, $second);
        self::assertSame(1, $first->resetCount, 'release() must RSET before pooling');
        self::assertSame(1, $first->noopCount, 'acquire must NOOP-health-check the pooled instance');
    }

    public function testFailedNoopDropsAndRefillsViaFactory(): void
    {
        $pool = new SmtpConnectionPool();
        $stale = new FakePoolSmtp(connected: true, noopResult: false);
        $pool->release('k', $stale);

        $fresh = new FakePoolSmtp(connected: true);
        $factoryCalls = 0;
        $out = $pool->acquireOrNew('k', function () use (&$factoryCalls, $fresh) {
            $factoryCalls++;
            return $fresh;
        });

        self::assertSame($fresh, $out);
        self::assertSame(1, $factoryCalls);
        self::assertSame(1, $stale->closeCount, 'stale pool entry must be closed');
    }

    public function testIdleTimeoutExpiresEntries(): void
    {
        $pool = new SmtpConnectionPool(idleTimeoutSec: 0.0);
        $stale = new FakePoolSmtp(connected: true);
        $pool->release('k', $stale);

        usleep(50_000);

        $factoryCalls = 0;
        $pool->acquireOrNew('k', function () use (&$factoryCalls) {
            $factoryCalls++;
            return new FakePoolSmtp();
        });

        self::assertSame(1, $factoryCalls, 'expired entry must be dropped, not returned');
        self::assertSame(1, $stale->closeCount);
    }

    public function testMaxPerKeyEvictsOldestOnOverflow(): void
    {
        $pool = new SmtpConnectionPool(maxPerKey: 2);
        $a = new FakePoolSmtp(connected: true);
        $b = new FakePoolSmtp(connected: true);
        $c = new FakePoolSmtp(connected: true);

        $pool->release('k', $a);
        $pool->release('k', $b);
        $pool->release('k', $c); // overflow — $a should be evicted

        self::assertSame(2, $pool->idleCount('k'));
        self::assertSame(1, $a->closeCount, 'oldest entry must be closed on overflow');
        self::assertSame(0, $b->closeCount);
        self::assertSame(0, $c->closeCount);
    }

    public function testReleaseOfDeadConnectionDoesNothing(): void
    {
        $pool = new SmtpConnectionPool();
        $dead = new FakePoolSmtp(connected: false);
        $pool->release('k', $dead);

        self::assertSame(0, $pool->idleCount('k'));
        self::assertSame(0, $dead->resetCount);
        self::assertSame(0, $dead->closeCount);
    }

    public function testReleaseOnFailedRsetClosesInstead(): void
    {
        $pool = new SmtpConnectionPool();
        $broken = new FakePoolSmtp(connected: true, resetResult: false);
        $pool->release('k', $broken);

        self::assertSame(0, $pool->idleCount('k'));
        self::assertSame(1, $broken->closeCount);
    }

    public function testCloseAllDrainsEveryKey(): void
    {
        $pool = new SmtpConnectionPool();
        $entries = [new FakePoolSmtp(connected: true), new FakePoolSmtp(connected: true)];
        $pool->release('k1', $entries[0]);
        $pool->release('k2', $entries[1]);

        self::assertSame(2, $pool->idleCount());

        $pool->closeAll();

        self::assertSame(0, $pool->idleCount());
        foreach ($entries as $smtp) {
            self::assertSame(1, $smtp->closeCount);
            self::assertSame(1, $smtp->quitCount);
        }
    }

    public function testStatsCountsHitsMissesReleasesAndEvictions(): void
    {
        $pool = new SmtpConnectionPool(maxPerKey: 1);

        // Miss — empty pool calls factory.
        $a = new FakePoolSmtp(connected: true);
        $pool->acquireOrNew('k', static fn() => $a);
        // Release back.
        $pool->release('k', $a);
        // Hit — pop the released entry.
        $pool->acquireOrNew('k', static fn() => $a);
        // Release back again.
        $pool->release('k', $a);
        // Force overflow eviction: release a different SMTP under same key.
        $b = new FakePoolSmtp(connected: true);
        $pool->release('k', $b);

        $stats = $pool->stats();
        self::assertSame(1, $stats['acquireHits']);
        self::assertSame(1, $stats['acquireMisses']);
        self::assertSame(3, $stats['releases']);
        self::assertSame(1, $stats['evictions']);
        self::assertSame(0.5, $stats['hitRatio']);
        self::assertSame(1, $stats['idleNow']);
    }

    public function testStatsHitRatioIsZeroBeforeAnyAcquire(): void
    {
        $stats = (new SmtpConnectionPool())->stats();
        self::assertSame(0.0, $stats['hitRatio']);
        self::assertSame(0, $stats['acquireHits']);
        self::assertSame(0, $stats['acquireMisses']);
    }

    public function testKeysExposesActiveKeys(): void
    {
        $pool = new SmtpConnectionPool();
        $pool->release('a', new FakePoolSmtp(connected: true));
        $pool->release('b', new FakePoolSmtp(connected: true));

        $keys = $pool->keys();
        sort($keys);
        self::assertSame(['a', 'b'], $keys);
    }

    public function testNoopHealthCheckCanBeDisabled(): void
    {
        $pool = new SmtpConnectionPool(useNoopHealthCheck: false);
        $entry = new FakePoolSmtp(connected: true, noopResult: false);
        $pool->release('k', $entry);

        $factoryCalls = 0;
        $out = $pool->acquireOrNew('k', function () use (&$factoryCalls) {
            $factoryCalls++;
            return new FakePoolSmtp();
        });

        self::assertSame($entry, $out, 'with health-check off the pooled entry comes back even though noop would fail');
        self::assertSame(0, $entry->noopCount, 'noop must not be called when disabled');
        self::assertSame(0, $factoryCalls);
    }
}

/**
 * Minimal SMTP subclass that lets pool tests assert on the methods the pool
 * actually exercises (connected / noop / reset / quit / close) without
 * needing real sockets.
 *
 * @internal
 */
final class FakePoolSmtp extends SMTP
{
    public int $resetCount = 0;
    public int $noopCount = 0;
    public int $quitCount = 0;
    public int $closeCount = 0;

    public function __construct(
        private bool $connected = false,
        private bool $noopResult = true,
        private bool $resetResult = true
    ) {
        // no parent::__construct — SMTP has none
    }

    public function connected()
    {
        return $this->connected;
    }

    public function noop()
    {
        $this->noopCount++;
        return $this->noopResult;
    }

    public function reset()
    {
        $this->resetCount++;
        return $this->resetResult;
    }

    public function quit($close_on_error = true)
    {
        $this->quitCount++;
        if ($close_on_error) {
            $this->close();
        }
        return true;
    }

    public function close()
    {
        // Match real SMTP::close() — idempotent. The pool intentionally
        // double-closes (quit() closes on success, then safeClose() guards
        // with another close()) and we don't want that to inflate the
        // assertion count.
        if (!$this->connected) {
            return;
        }
        $this->closeCount++;
        $this->connected = false;
    }
}
