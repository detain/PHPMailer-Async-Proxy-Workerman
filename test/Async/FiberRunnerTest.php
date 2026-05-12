<?php

/**
 * PHPMailer-Async-Proxy-Workerman — FiberRunner tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use Fiber;
use PHPMailer\PHPMailer\Async\FiberRunner;
use Revolt\EventLoop;
use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class FiberRunnerTest extends TestCase
{
    public function testReturnsClosureResultFromNonFiberContext(): void
    {
        $value = FiberRunner::run(static fn(): int => 42);
        self::assertSame(42, $value);
    }

    public function testInlineCallFromWithinExistingFiber(): void
    {
        $inner = null;
        $fiber = new Fiber(function () use (&$inner): void {
            $inner = FiberRunner::run(static function (): string {
                return 'inline-' . (Fiber::getCurrent() !== null ? 'inside-fiber' : 'outside');
            });
        });
        $fiber->start();
        // Fiber is single-shot — body returns synchronously since FiberRunner just calls inline.
        self::assertSame('inline-inside-fiber', $inner);
    }

    public function testExceptionsPropagateUnwrapped(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        FiberRunner::run(static function (): void {
            throw new RuntimeException('boom');
        });
    }

    public function testAsyncWorkInsidePrivateLoop(): void
    {
        $hits = [];
        $result = FiberRunner::run(static function () use (&$hits) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.01, static function () use ($suspension, &$hits): void {
                $hits[] = 'timer-fired';
                $suspension->resume('woken');
            });
            $hits[] = 'before-suspend';
            $woken = $suspension->suspend();
            $hits[] = 'after-suspend';
            return $woken;
        });

        self::assertSame('woken', $result);
        self::assertSame(['before-suspend', 'timer-fired', 'after-suspend'], $hits);
    }

    public function testPrivateLoopDoesNotLeakWatchers(): void
    {
        // Schedule and immediately cancel a watcher; the private loop should
        // finish promptly after $work returns without hanging on pending state.
        $observed = false;
        FiberRunner::run(static function () use (&$observed) {
            $id = EventLoop::delay(60, static function (): void {
                // would fire much later — must not block our run
            });
            EventLoop::cancel($id);
            $observed = true;
        });
        self::assertTrue($observed);
    }

    public function testSequentialCallsAllowMultipleInvocations(): void
    {
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = FiberRunner::run(static fn(): int => $i);
        }
        self::assertSame([0, 1, 2], $results);
    }

    public function testPrivateLoopDoesNotFireCallersPreScheduledCallbacks(): void
    {
        // The caller has registered Revolt watchers + a deferred but has
        // NOT yet started the reactor. Our FiberRunner::run() must drive an
        // ISOLATED driver so the caller's pending work does not get ticked
        // mid-call (which would terminate when our fiber finishes).
        $fired = false;
        $deferId = EventLoop::defer(static function () use (&$fired): void {
            $fired = true;
        });

        try {
            FiberRunner::run(static fn(): string => 'inner-work');
            self::assertFalse(
                $fired,
                "Caller's deferred callback fired during a private FiberRunner run — driver isolation regression"
            );
        } finally {
            EventLoop::cancel($deferId);
        }
    }

    public function testPrivateLoopRestoresOriginalDriverEvenOnException(): void
    {
        $original = EventLoop::getDriver();
        try {
            FiberRunner::run(static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (RuntimeException) {
            // expected
        }
        self::assertSame($original, EventLoop::getDriver(), 'driver swap was not unwound');
    }
}
