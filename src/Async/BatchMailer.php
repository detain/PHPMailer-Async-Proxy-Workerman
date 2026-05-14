<?php

/**
 * PHPMailer-Async-Proxy-Workerman — Simplified batch email sender.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

/**
 * Simplified batch sending with automatic SMTPKeepAlive management.
 *
 * This class handles the common pattern of sending multiple emails over a
 * shared SMTP connection (or connection pool). It automatically:
 * - Acquires/releases connections from the pool
 * - Sets SMTPKeepAlive on each mail
 * - Handles connection lifecycle (connect/hello/auth when needed)
 * - Yields results as a generator for memory efficiency
 *
 * Usage:
 *
 *     $pool = new SmtpConnectionPool();
 *     $batch = new BatchMailer($pool, 'smtp.example.com', 25, 'user', 'pass');
 *
 *     foreach ($batch->sendAll($messages) as $result) {
 *         echo "Sent to {$result['email']}: " . ($result['ok'] ? 'OK' : $result['error']) . "\n";
 *     }
 *
 * For non-blocking async sending with Fibers (PHP 8.1+):
 *
 *     Fiber::run(function() use ($batch, $messages) {
 *         foreach ($batch->sendAll($messages) as $result) {
 *             echo "Sent to {$result['email']}\n";
 *             Fiber::suspend(); // Yield to event loop
 *         }
 *     });
 *
 * @implements \IteratorAggregate<string, array{email: string, ok: bool, error: ?string}>
 */
final class BatchMailer implements \IteratorAggregate
{
    /** @var SmtpConnectionPool */
    private SmtpConnectionPool $pool;

    /** @var \Closure(): SMTP */
    private \Closure $smtpFactory;

    /** @var string */
    private string $poolKey;

    /** @var int */
    private int $connectTimeout;

    /** @var int */
    // @phpstan-ignore property.onlyWritten
    private int $idleTimeoutSec;

    /**
     * Get the idle timeout for pool entries.
     */
    public function getIdleTimeoutSec(): int
    {
        return $this->idleTimeoutSec;
    }

    /**
     * @param SmtpConnectionPool $pool Connection pool to use
     * @param string             $host SMTP host
     * @param int                $port SMTP port
     * @param string             $username Authentication username
     * @param string|null        $password Authentication password
     * @param int                $connectTimeout Timeout for connect in seconds
     * @param int                $idleTimeoutSec Idle timeout for pool entries
     */
    public function __construct(
        SmtpConnectionPool $pool,
        string $host,
        int $port,
        string $username,
        ?string $password = null,
        int $connectTimeout = 30,
        int $idleTimeoutSec = 60
    ) {
        $this->pool = $pool;
        $this->poolKey = "{$host}:{$port}:{$username}";
        $this->connectTimeout = $connectTimeout;
        $this->idleTimeoutSec = $idleTimeoutSec;
        $this->smtpFactory = $this->createFactory($host, $port, $username, $password);
    }

    /**
     * Send multiple emails via the connection pool.
     *
     * @param iterable<PHPMailer> $messages Emails to send
     *
     * @return \Generator<string, array{email: string, ok: bool, error: ?string}>
     *         Yields result arrays keyed by email address
     */
    public function sendAll(iterable $messages): \Generator
    {
        foreach ($messages as $mail) {
            yield from $this->sendOne($mail);
        }
    }

    /**
     * Send a single email via the connection pool.
     *
     * @return \Generator<string, array{email: string, ok: bool, error: ?string}>
     */
    private function sendOne(PHPMailer $mail): \Generator
    {
        $smtp = $this->pool->acquireOrNew($this->poolKey, $this->smtpFactory);

        try {
            // Ensure connection is established if this is a fresh instance
            if (!$smtp->connected()) {
                $this->connect($smtp, $mail);
            }

            // Wire this PHPMailer instance to use our SMTP instance
            // and enable SMTPKeepAlive so the connection stays open
            $mail->setSMTPInstance($smtp);
            $mail->SMTPKeepAlive = true;

            // Get recipient for result
            $email = $this->getFirstRecipient($mail);

            // Attempt send
            $ok = $mail->send();
            $error = $ok ? null : $mail->ErrorInfo;

            yield $email => ['email' => $email, 'ok' => $ok, 'error' => $error];
        } catch (Throwable $t) {
            $email = $this->getFirstRecipient($mail);
            yield $email => ['email' => $email, 'ok' => false, 'error' => $t->getMessage()];

            // Connection is likely dead, let release() handle it
            // Mark as not connected so pool doesn't reuse
            try {
                $smtp->close();
            } catch (Throwable $e) {
                // Ignore cleanup errors
            }
        } finally {
            // Always release back to pool (even on failure, if connection is alive)
            try {
                if ($smtp->connected()) {
                    $this->pool->release($this->poolKey, $smtp);
                }
            } catch (Throwable $e) {
                // Ignore release errors during cleanup
            }
        }
    }

    /**
     * Connect and authenticate an SMTP instance.
     */
    private function connect(SMTP $smtp, PHPMailer $mail): void
    {
        if (!$smtp->connect($mail->Host, $mail->Port, $this->connectTimeout)) {
                throw new Exception(
                    'Connect failed: ' . ($smtp->getLastReply() ?? 'unknown error')
                );
        }

        $smtp->hello(gethostname() ?: 'localhost');

        if ($mail->SMTPAuth && !empty($mail->Username)) {
            if (!$smtp->authenticate($mail->Username, $mail->Password)) {
                throw new Exception(
                    'Auth failed: ' . ($smtp->getLastReply() ?? 'unknown error')
                );
            }
        }
    }

    /**
     * Create the SMTP factory closure for pool misses.
     *
     * @return \Closure(): SMTP
     */
    private function createFactory(string $host, int $port, string $username, ?string $password): \Closure
    {
        return static function () use ($username): SMTP {
            $s = new SMTP();
            $s->setTransport(TransportFactory::auto());
            // @phpstan-ignore-next-line XCLIENT_attributes is not a defined SMTP property
            $s->XCLIENT_attributes = [
                'NAME' => $username,
            ];
            return $s;
        };
    }

    /**
     * Get the first recipient email address from a PHPMailer instance.
     */
    private function getFirstRecipient(PHPMailer $mail): string
    {
        $addresses = $mail->getToAddresses();
        if (!empty($addresses)) {
            return $addresses[0][0];
        }

        $addresses = $mail->getCCAddresses();
        if (!empty($addresses)) {
            return $addresses[0][0];
        }

        $addresses = $mail->getBCCAddresses();
        if (!empty($addresses)) {
            return $addresses[0][0];
        }

        return 'unknown';
    }

    /**
     * Get the connection pool being used.
     */
    public function getPool(): SmtpConnectionPool
    {
        return $this->pool;
    }

    /**
     * Get the pool key being used.
     */
    public function getPoolKey(): string
    {
        return $this->poolKey;
    }

    /**
     * Get the underlying SMTP factory.
     *
     * @return \Closure(): SMTP
     */
    public function getSmtpFactory(): \Closure
    {
        return $this->smtpFactory;
    }

    /**
     * Allow iteration via foreach without explicitly calling sendAll.
     *
     * @return \Generator<string, array{email: string, ok: bool, error: ?string}>
     */
    #[\ReturnTypeWillChange]
    public function getIterator(): \Generator
    {
        return $this->sendAll([]);
    }

    /**
     * Close all pooled connections.
     */
    public function closeAll(): void
    {
        $this->pool->closeAll();
    }

    /**
     * Get pool statistics.
     *
     * @return array{acquireHits: int, acquireMisses: int, releases: int, evictions: int, hitRatio: float, idleNow: int}
     */
    public function getStats(): array
    {
        return $this->pool->stats();
    }
}
