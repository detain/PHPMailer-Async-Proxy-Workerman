<?php

/**
 * PHPMailer-Async-Proxy-Workerman — Transport factory helper.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
 * One-stop helper for picking the right SMTP transport at runtime.
 *
 * Decision tree (in order of preference):
 *
 *   1. **Real Workerman worker is running** — return
 *      {@see WorkermanConnectionTransport} so the SMTP session plugs into the
 *      worker's existing event loop, statistics, and I/O multiplexer.
 *      Detected by `Worker::$globalEvent` being set to an instance the
 *      transport itself didn't install.
 *   2. **Revolt is available** — return {@see WorkermanTransport} (Revolt-
 *      direct). Works in any environment with `revolt/event-loop`, including
 *      CLI scripts and PHPUnit, because each method spins up a private
 *      Revolt loop via {@see FiberRunner} when needed.
 *   3. **Neither** — return {@see StreamTransport}, the blocking fallback
 *      that mirrors upstream PHPMailer behaviour byte-for-byte.
 *
 * The factory never returns null — callers can always plug the result
 * straight into `SMTP::setTransport()`.
 */
final class TransportFactory
{
    public static function auto(): Transport
    {
        if (self::workermanWorkerIsRunning()) {
            return new WorkermanConnectionTransport();
        }
        if (self::revoltAvailable()) {
            return new WorkermanTransport();
        }
        return new StreamTransport();
    }

    /**
     * Force-pick the blocking-stream transport. Useful when a caller wants
     * to match upstream PHPMailer behaviour explicitly (e.g. in a test
     * that asserts byte-for-byte equivalence with a non-fork install).
     */
    public static function blocking(): Transport
    {
        return new StreamTransport();
    }

    /**
     * Force-pick the Revolt-direct transport. Works anywhere Revolt does
     * — does NOT require a Workerman worker. Best general-purpose choice
     * when you want async I/O without tying yourself to Workerman's
     * connection lifecycle.
     */
    public static function revoltDirect(): Transport
    {
        return new WorkermanTransport();
    }

    /**
     * Force-pick the AsyncTcpConnection-based transport. Requires a
     * long-lived event loop (real Workerman worker OR caller wrapping the
     * whole SMTP session in a single `FiberRunner::run()`). Throws at use
     * time if the contract is broken — see {@see WorkermanConnectionTransport}.
     */
    public static function workermanConnection(): Transport
    {
        return new WorkermanConnectionTransport();
    }

    /**
     * Detect whether we're running inside a real Workerman worker. The
     * heuristic: `Worker::$globalEvent` is set, and the instance is not
     * one our own transport installed for itself (which we wouldn't trust
     * to be long-lived).
     */
    private static function workermanWorkerIsRunning(): bool
    {
        if (!class_exists(Worker::class) || !class_exists(AsyncTcpConnection::class)) {
            return false;
        }
        if (!isset(Worker::$globalEvent)) {
            return false;
        }
        // If `Worker::$globalEvent` is our own lazily-installed adapter,
        // there's no real Worker — fall back to the Revolt-direct path so
        // the caller doesn't get a fiber-context exception out of nowhere.
        $loop = Worker::$globalEvent;
        $loopClass = get_class($loop);
        return $loopClass !== 'Workerman\\Events\\Fiber'
            || self::workermanFiberEventsWasInstalledByARealWorker($loop);
    }

    /**
     * When a real worker is running it installs `Workerman\Events\Fiber`
     * too — same class, different ownership. Distinguish via the
     * transport's own static cache (`WorkermanConnectionTransport::$ourEventLoop`).
     */
    private static function workermanFiberEventsWasInstalledByARealWorker(object $loop): bool
    {
        // Reflection is cheap and avoids exposing the static internal state.
        try {
            $ref = new \ReflectionProperty(WorkermanConnectionTransport::class, 'ourEventLoop');
            $ref->setAccessible(true);
            $ours = $ref->getValue();
            return $loop !== $ours;
        } catch (\Throwable $t) {
            return true; // benefit of the doubt — assume it's a real worker
        }
    }

    private static function revoltAvailable(): bool
    {
        return class_exists(\Revolt\EventLoop::class);
    }
}
