<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol exception.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\ProxyProtocol;

use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Thrown when a PROXY protocol header is constructed with invalid inputs
 * (e.g. malformed IP, IPv4 src paired with IPv6 dst, port out of range).
 *
 * Inherits from {@see PHPMailerException} so any consumer that already
 * catches `PHPMailer\PHPMailer\Exception` keeps working.
 */
class Exception extends PHPMailerException
{
}
