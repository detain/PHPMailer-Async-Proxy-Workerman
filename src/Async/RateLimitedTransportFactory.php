<?php

/**
 * PHPMailer-Async-Proxy-Workerman — Rate-limited transport wrapper.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

/**
 * Rate-limited transport wrapper that prevents hitting SMTP server limits.
 *
 * This decorator wraps a Transport and enforces per-host rate limits using
 * a token bucket algorithm. When the rate limit is exceeded, it will wait
 * until the next available slot before returning a transport.
 *
 * Usage:
 *
 *     $factory = new RateLimitedTransportFactory(
 *         messagesPerMinute: 60,
 *         burstSize: 10
 *     );
 *
 *     $transport = $factory->getTransport('smtp.example.com');
 *
 *     // Or use with PHPMailer:
 *     $smtp = new SMTP();
 *     $smtp->setTransport($factory->createRateLimitedTransport('smtp.example.com'));
 *
 * ## Token Bucket Algorithm
 *
 * - Bucket holds up to $burstSize tokens
 * - One token is consumed per message
 * - Tokens refill at a rate of $messagesPerMinute / 60 per second
 * - If bucket is empty, wait until a token is available
 *
 * ## Thread Safety
 *
 * This class uses static state for rate limiting across all instances.
 * In a Workerman worker context, each worker process has its own bucket state.
 * For multi-process deployments, consider using Redis for distributed rate limiting.
 */
final class RateLimitedTransportFactory
{
    /** Default messages per minute per host. */
    public const DEFAULT_MESSAGES_PER_MINUTE = 60;

    /** Default burst size (max tokens in bucket). */
    public const DEFAULT_BURST_SIZE = 10;

    /**
     * Token bucket state per host.
     *
     * @var array<string, array{tokens: float, lastRefill: float}>
     */
    private static array $buckets = [];

    /**
     * Messages per minute rate per host.
     *
     * @var array<string, int>
     */
    private static array $hostLimits = [];

    /**
     * Burst size per host.
     *
     * @var array<string, int>
     */
    private static array $hostBurstSizes = [];

    /**
     * Global default messages per minute.
     */
    private static int $defaultMessagesPerMinute = self::DEFAULT_MESSAGES_PER_MINUTE;

    /**
     * Global default burst size.
     */
    private static int $defaultBurstSize = self::DEFAULT_BURST_SIZE;

    /**
     * Get or create a rate-limited transport for a host.
     *
     * @param string $host SMTP host
     *
     * @return Transport
     */
    public function getTransport(string $host): Transport
    {
        $this->ensureRateLimit($host);
        $this->waitForToken($host);

        return TransportFactory::auto();
    }

    /**
     * Create a rate-limited transport wrapped in a decorator.
     *
     * The returned transport will automatically enforce rate limits on each
     * operation that involves sending (write operations).
     *
     * @param string $host SMTP host
     *
     * @return Transport
     */
    public function createRateLimitedTransport(string $host): Transport
    {
        $this->ensureRateLimit($host);

        $inner = TransportFactory::auto();
        $self = $this;

        return new class ($inner, $host, $self) implements Transport {
            private Transport $inner;
            private string $host;
            private RateLimitedTransportFactory $factory;

            public function __construct(Transport $inner, string $host, RateLimitedTransportFactory $factory)
            {
                $this->inner = $inner;
                $this->host = $host;
                $this->factory = $factory;
            }

            public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool
            {
                // @phpstan-ignore-next-line Private method called from inner class
                $this->factory->waitForToken($this->host);
                return $this->inner->connect($host, $port, $timeout, $contextOptions);
            }

            public function close(): void
            {
                $this->inner->close();
            }

            public function isOpen(): bool
            {
                return $this->inner->isOpen();
            }

            public function write(string $data): int|false
            {
                // Consume a token on write (indicates message data being sent)
                // @phpstan-ignore-next-line Private method called from inner class
                $this->factory->waitForToken($this->host);
                return $this->inner->write($data);
            }

            public function readLine(int $maxLength): string
            {
                return $this->inner->readLine($maxLength);
            }

            public function waitReadable(int $timeoutSeconds): ?bool
            {
                return $this->inner->waitReadable($timeoutSeconds);
            }

            public function enableCrypto(int $cryptoMethod, int $timeout = 30): bool
            {
                return $this->inner->enableCrypto($cryptoMethod, $timeout);
            }

            public function getMetadata(): array
            {
                return $this->inner->getMetadata();
            }

            public function setReadTimeout(int $seconds): void
            {
                $this->inner->setReadTimeout($seconds);
            }

            public function getConnectError(): array
            {
                return $this->inner->getConnectError();
            }

            public function getLastWarning(): array
            {
                return $this->inner->getLastWarning();
            }

            public function clearLastWarning(): void
            {
                $this->inner->clearLastWarning();
            }

            public function getResource()
            {
                return $this->inner->getResource();
            }

            public function setErrorHandler(?callable $handler): void
            {
                $this->inner->setErrorHandler($handler);
            }

            public function setProxyProtocolHeader(?string $bytes): void
            {
                $this->inner->setProxyProtocolHeader($bytes);
            }
        };
    }

    /**
     * Set rate limits for a specific host.
     *
     * @param string $host               Hostname
     * @param int    $messagesPerMinute  Max messages per minute
     * @param int    $burstSize          Max tokens in bucket (burst allowance)
     */
    public static function setHostLimit(string $host, int $messagesPerMinute, int $burstSize = 10): void
    {
        $host = strtolower($host);
        self::$hostLimits[$host] = max(1, $messagesPerMinute);
        self::$hostBurstSizes[$host] = max(1, $burstSize);

        // Initialize bucket if needed
        if (!isset(self::$buckets[$host])) {
            self::$buckets[$host] = [
                'tokens' => (float) $burstSize,
                'lastRefill' => microtime(true),
            ];
        }
    }

    /**
     * Set global default rate limits.
     *
     * @param int $messagesPerMinute  Max messages per minute
     * @param int $burstSize          Max tokens in bucket (burst allowance)
     */
    public static function setDefaultLimit(int $messagesPerMinute, int $burstSize = 10): void
    {
        self::$defaultMessagesPerMinute = max(1, $messagesPerMinute);
        self::$defaultBurstSize = max(1, $burstSize);
    }

    /**
     * Get current rate limit status for a host.
     *
     * @param string $host Hostname
     *
     * @return array{tokens: float, lastRefill: float, messagesPerMinute: int, burstSize: int, availableNow: int}
     */
    public static function getHostStatus(string $host): array
    {
        $host = strtolower($host);
        $limit = self::$hostLimits[$host] ?? self::$defaultMessagesPerMinute;
        $burst = self::$hostBurstSizes[$host] ?? self::$defaultBurstSize;

        // Refill tokens based on elapsed time
        self::refillBucket($host, $limit, $burst);

        $tokens = self::$buckets[$host] ?? ['tokens' => 0.0, 'lastRefill' => microtime(true)];

        return [
            'tokens' => $tokens['tokens'],
            'lastRefill' => $tokens['lastRefill'],
            'messagesPerMinute' => $limit,
            'burstSize' => $burst,
            'availableNow' => (int) floor($tokens['tokens']),
        ];
    }

    /**
     * Reset rate limiting state for a host.
     *
     * @param string|null $host Hostname, or null to reset all
     */
    public static function reset(?string $host = null): void
    {
        if ($host === null) {
            self::$buckets = [];
            self::$hostLimits = [];
            self::$hostBurstSizes = [];
            return;
        }

        $host = strtolower($host);
        unset(self::$buckets[$host], self::$hostLimits[$host], self::$hostBurstSizes[$host]);
    }

    /**
     * Ensure rate limit state exists for a host.
     */
    private function ensureRateLimit(string $host): void
    {
        $host = strtolower($host);

        if (!isset(self::$buckets[$host])) {
            $limit = self::$hostLimits[$host] ?? self::$defaultMessagesPerMinute;
            $burst = self::$hostBurstSizes[$host] ?? self::$defaultBurstSize;

            self::$buckets[$host] = [
                'tokens' => (float) $burst,
                'lastRefill' => microtime(true),
            ];
        }
    }

    /**
     * Wait until a token is available, refilling as needed.
     */
    private function waitForToken(string $host): void
    {
        $host = strtolower($host);
        $limit = self::$hostLimits[$host] ?? self::$defaultMessagesPerMinute;
        $burst = self::$hostBurstSizes[$host] ?? self::$defaultBurstSize;

        while (true) {
            // Refill bucket based on elapsed time
            $this->refillBucket($host, $limit, $burst);

            $tokens = &self::$buckets[$host]['tokens'];

            if ($tokens >= 1.0) {
                $tokens -= 1.0;
                return;
            }

            // Calculate wait time until next token
            // Token refill rate = messagesPerMinute / 60 seconds
            $refillRate = $limit / 60.0; // tokens per second
            $waitTime = (1.0 - $tokens) / $refillRate;

            // Don't wait more than 1 second at a time
            $waitTime = min($waitTime, 1.0);

            // In a Fiber context, we could yield. For blocking context, sleep
            if (function_exists('usleep')) {
                usleep((int)($waitTime * 1000000));
            } else {
                time_nanosleep(0, (int)($waitTime * 1000000000));
            }
        }
    }

    /**
     * Refill the token bucket based on elapsed time.
     *
     * @param string $host  Hostname
     * @param int    $limit Messages per minute
     * @param int    $burst Burst size
     */
    private static function refillBucket(string $host, int $limit, int $burst): void
    {
        $now = microtime(true);

        if (!isset(self::$buckets[$host])) {
            self::$buckets[$host] = [
                'tokens' => (float) $burst,
                'lastRefill' => $now,
            ];
            return;
        }

        $bucket = &self::$buckets[$host];
        $elapsed = $now - $bucket['lastRefill'];

        if ($elapsed > 0) {
            // Calculate tokens to add
            // Refill rate = messagesPerMinute / 60 (per second)
            $refillRate = $limit / 60.0;
            $tokensToAdd = $elapsed * $refillRate;

            $bucket['tokens'] = min((float) $burst, $bucket['tokens'] + $tokensToAdd);
            $bucket['lastRefill'] = $now;
        }
    }
}
