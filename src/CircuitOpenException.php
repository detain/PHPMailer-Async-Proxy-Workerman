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
 * Exception thrown when the SMTP circuit breaker is open.
 *
 * This happens when too many connection failures have occurred for a given
 * SMTP host, and the circuit breaker is in its "open" state to prevent
 * hammering a failing server.
 */
class CircuitOpenException extends Exception
{
}
