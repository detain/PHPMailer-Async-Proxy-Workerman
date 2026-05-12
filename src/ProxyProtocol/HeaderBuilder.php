<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol header builder contract.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\ProxyProtocol;

/**
 * Anything that knows how to serialise itself into a PROXY protocol header
 * (v1 text or v2 binary) — written to the wire immediately after TCP connect
 * and before any SMTP traffic.
 *
 * @see V1Header
 * @see V2Header
 * @see https://www.haproxy.org/download/2.8/doc/proxy-protocol.txt
 */
interface HeaderBuilder
{
    /**
     * Serialise the header to the exact byte string that must be written
     * onto the wire.
     */
    public function build(): string;

    /**
     * PROXY protocol version represented by this builder (1 or 2).
     */
    public function version(): int;
}
