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

namespace PHPMailer\PHPMailer;

/**
 * Exception thrown when all retry attempts have failed.
 *
 * This happens when SmtpConnectionPool::acquireOrNewWithRetry() has exhausted
 * all configured retry attempts without successfully acquiring a connection.
 */
class AllRetriesFailedException extends Exception
{
}
