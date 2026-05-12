<?php

/**
 * PHPMailer-Async-Proxy-Workerman — FiberRunner.
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
use Throwable;

/**
 * Run a closure inside a fiber, transparently choosing between three modes:
 *
 *   1. **Already inside a fiber** — the closure is called inline; the fiber
 *      it ran inside is the caller's, which can yield to whatever event loop
 *      is driving it (Workerman, Revolt, hand-rolled).
 *   2. **Reactor already running** — the closure is wrapped in a new fiber
 *      that the existing reactor will tick. `FiberRunner::run()` blocks the
 *      outer (non-fiber) caller on a {@see Revolt\EventLoop\Suspension} until
 *      the inner fiber finishes.
 *   3. **No reactor running** (CLI, PHPUnit) — a private `EventLoop` run is
 *      started just for the duration of the call: schedule the closure in a
 *      fresh fiber, `EventLoop::run()` until the fiber returns, return the
 *      result. This is what lets the upstream PHPMailer test suite call
 *      `SMTP::connect()` synchronously and still get correct behaviour.
 *
 * Exceptions thrown inside the fiber are re-thrown into the caller's frame so
 * the contract is "this looks just like a synchronous call, but it doesn't
 * stall the event loop".
 */
final class FiberRunner
{
    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public static function run(Closure $work)
    {
        // Inside an active fiber — just run inline.
        if (Fiber::getCurrent() !== null) {
            return $work();
        }

        $driver = EventLoop::getDriver();
        $isRunning = $driver->isRunning();

        if (!$isRunning) {
            return self::runWithPrivateLoop($driver, $work);
        }

        return self::runWithExistingLoop($work);
    }

    /**
     * Drive an isolated Revolt run() for the duration of $work.
     *
     * Creates a fresh driver via Revolt's default DriverFactory and installs
     * it as the global driver while $work runs. This keeps any watchers,
     * timers, or deferreds the caller has *already* registered on the
     * outer (not-yet-running) driver out of our event-loop tick — and our
     * `stop()` does not terminate the caller's pending work. The previous
     * global driver is restored in `finally`, including on exceptions.
     *
     * The $driver argument is only used to confirm "no loop is running" at
     * the call site; we never schedule on it.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private static function runWithPrivateLoop(EventLoop\Driver $driver, Closure $work)
    {
        $previousDriver = $driver;
        $isolated = (new EventLoop\DriverFactory())->create();
        EventLoop::setDriver($isolated);

        try {
            $result = null;
            $error = null;
            $done = false;

            $fiber = new Fiber(function () use ($work, &$result, &$error, &$done, $isolated): void {
                try {
                    $result = $work();
                } catch (Throwable $t) {
                    $error = $t;
                } finally {
                    $done = true;
                    $isolated->stop();
                }
            });

            $isolated->queue(static function () use ($fiber): void {
                $fiber->start();
            });

            $isolated->run();

            if (!$done) {
                throw new \RuntimeException('FiberRunner: private loop exited before the fiber completed');
            }
            if ($error !== null) {
                throw $error;
            }
            return $result;
        } finally {
            EventLoop::setDriver($previousDriver);
        }
    }

    /**
     * Schedule on the running reactor; suspend the (non-fiber) caller until
     * the inner fiber returns. This is the path taken from a Workerman
     * `onMessage()` callback that called into PHPMailer without first wrapping
     * in a fiber — uncommon but supported.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private static function runWithExistingLoop(Closure $work)
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::defer(static function () use ($work, $suspension): void {
            $fiber = new Fiber(static function () use ($work, $suspension): void {
                try {
                    $suspension->resume($work());
                } catch (Throwable $t) {
                    $suspension->throw($t);
                }
            });
            $fiber->start();
        });

        return $suspension->suspend();
    }
}
