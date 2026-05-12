<?php

/**
 * PHPMailer-Async-Proxy-Workerman — TransportFactory tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\Async;

use PHPMailer\PHPMailer\Async\StreamTransport;
use PHPMailer\PHPMailer\Async\TransportFactory;
use PHPMailer\PHPMailer\Async\WorkermanConnectionTransport;
use PHPMailer\PHPMailer\Async\WorkermanTransport;
use Workerman\Worker;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TransportFactoryTest extends TestCase
{
    /** @var mixed */
    private static $originalGlobalEvent;

    protected function set_up(): void
    {
        if (class_exists(Worker::class)) {
            self::$originalGlobalEvent = Worker::$globalEvent ?? null;
        }
    }

    protected function tear_down(): void
    {
        if (class_exists(Worker::class)) {
            if (self::$originalGlobalEvent === null) {
                // Reset back to "unset" — assign null on the static which
                // PHP treats the same as not-set for the !isset() check.
                Worker::$globalEvent = null;
            } else {
                Worker::$globalEvent = self::$originalGlobalEvent;
            }
        }
    }

    public function testForcedHelpersAlwaysReturnTheRequestedConcreteType(): void
    {
        self::assertInstanceOf(StreamTransport::class, TransportFactory::blocking());
        self::assertInstanceOf(WorkermanTransport::class, TransportFactory::revoltDirect());
        self::assertInstanceOf(WorkermanConnectionTransport::class, TransportFactory::workermanConnection());
    }

    public function testAutoPicksWorkermanTransportOutsideARealWorker(): void
    {
        Worker::$globalEvent = null;
        self::assertInstanceOf(WorkermanTransport::class, TransportFactory::auto());
    }

    public function testAutoPicksWorkermanConnectionWhenARealWorkerIsRunning(): void
    {
        $fakeWorkerLoop = new class () implements \Workerman\Events\EventInterface {
            public function delay(float $delay, callable $func, array $args = []): int
            {
                return 0;
            }
            public function offDelay(int $timerId): bool
            {
                return true;
            }
            public function repeat(float $delay, callable $func, array $args = []): int
            {
                return 0;
            }
            public function offRepeat(int $timerId): bool
            {
                return true;
            }
            public function onReadable($stream, callable $func): void
            {
            }
            public function offReadable($stream): bool
            {
                return true;
            }
            public function onWritable($stream, callable $func): void
            {
            }
            public function offWritable($stream): bool
            {
                return true;
            }
            public function onSignal(int $signal, callable $func): void
            {
            }
            public function offSignal(int $signal): bool
            {
                return true;
            }
            public function run(): void
            {
            }
            public function stop(): void
            {
            }
            public function deleteAllTimer(): void
            {
            }
            public function getTimerCount(): int
            {
                return 0;
            }
            public function setErrorHandler(callable $errorHandler): void
            {
            }
        };

        Worker::$globalEvent = $fakeWorkerLoop;

        self::assertInstanceOf(WorkermanConnectionTransport::class, TransportFactory::auto());
    }
}
