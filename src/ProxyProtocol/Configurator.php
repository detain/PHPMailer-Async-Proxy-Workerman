<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol configurator.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\ProxyProtocol;

/**
 * Fluent factory for {@see HeaderBuilder} instances. Lets callers wire PROXY
 * protocol into PHPMailer in one expressive line:
 *
 *     $mailer->setProxyProtocol(Configurator::v1($srcIp, $dstIp, $srcPort, $dstPort));
 *     $mailer->setProxyProtocol(Configurator::v2($srcIp, $dstIp, $srcPort, $dstPort));
 *     $mailer->setProxyProtocol(Configurator::disabled());
 *
 * The configurator carries no state of its own — every method returns either
 * a builder or `null` (disabled).
 */
final class Configurator
{
    public const VERSION_V1 = 1;
    public const VERSION_V2 = 2;

    /**
     * Build a PROXY v1 (text) header with auto-detected family.
     */
    public static function v1(string $srcIp, string $dstIp, int $srcPort, int $dstPort): V1Header
    {
        return V1Header::auto($srcIp, $dstIp, $srcPort, $dstPort);
    }

    /**
     * Build a PROXY v2 (binary) header with auto-detected family.
     */
    public static function v2(string $srcIp, string $dstIp, int $srcPort, int $dstPort): V2Header
    {
        return V2Header::auto($srcIp, $dstIp, $srcPort, $dstPort);
    }

    /**
     * `PROXY UNKNOWN\r\n` — v1 form that tells the receiver to ignore the
     * advertised peer details. Use when you want to participate in the
     * protocol but have no real peer info to share.
     */
    public static function v1Unknown(): V1Header
    {
        return V1Header::unknown();
    }

    /**
     * Disabled — the SMTP transport interprets `null` as "do not write a
     * PROXY header at all".
     */
    public static function disabled()
    {
        return null;
    }

    /**
     * Generic factory that delegates to v1() or v2() based on the requested
     * version. Useful when reading the version out of configuration.
     *
     * @throws Exception when $version is neither 1 nor 2
     */
    public static function ofVersion(
        int $version,
        string $srcIp,
        string $dstIp,
        int $srcPort,
        int $dstPort
    ): HeaderBuilder {
        if ($version === self::VERSION_V1) {
            return self::v1($srcIp, $dstIp, $srcPort, $dstPort);
        }
        if ($version === self::VERSION_V2) {
            return self::v2($srcIp, $dstIp, $srcPort, $dstPort);
        }
        throw new Exception(sprintf('Unsupported PROXY protocol version %d', $version));
    }
}
