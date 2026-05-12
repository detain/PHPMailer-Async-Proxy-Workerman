<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol v1 header.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\ProxyProtocol;

/**
 * PROXY Protocol v1 — the text-mode header, one CRLF-terminated line:
 *
 *     PROXY TCP4 <src_ip> <dst_ip> <src_port> <dst_port>\r\n
 *     PROXY TCP6 <src_ip> <dst_ip> <src_port> <dst_port>\r\n
 *     PROXY UNKNOWN\r\n
 *
 * Family is auto-detected from the source IP unless explicitly set. The line
 * is bounded to 107 bytes including CRLF per the HAProxy spec; this builder
 * enforces that limit in the constructor.
 *
 * Maps directly to the existing sync `app/Mail/ProxySMTP::sendProxyHeader()`
 * implementation in the parent project — same on-the-wire output.
 */
final class V1Header implements HeaderBuilder
{
    public const FAMILY_TCP4 = 'TCP4';
    public const FAMILY_TCP6 = 'TCP6';
    public const FAMILY_UNKNOWN = 'UNKNOWN';

    /** @var int */
    public const MAX_LENGTH = 107; // RFC: 108 max incl trailing NUL; HAProxy spec quotes 107 + CRLF

    private string $family;
    private string $srcIp;
    private string $dstIp;
    private int $srcPort;
    private int $dstPort;

    /**
     * @throws Exception when inputs are out of range or the family is mismatched
     */
    public function __construct(
        string $srcIp,
        string $dstIp,
        int $srcPort,
        int $dstPort,
        ?string $family = null
    ) {
        $autoDetect = $family === null;
        $detectedFamily = $family ?? self::detectFamily($srcIp);

        if (
            $detectedFamily !== self::FAMILY_TCP4
            && $detectedFamily !== self::FAMILY_TCP6
            && $detectedFamily !== self::FAMILY_UNKNOWN
        ) {
            throw new Exception(sprintf('Invalid PROXY v1 family %s', $detectedFamily));
        }

        // When the caller asked for auto-detect, a UNKNOWN result means the
        // source IP is malformed. Don't silently degrade to the spec-mandated
        // "PROXY UNKNOWN\r\n" form — that's a footgun (the user thinks the
        // real peer is being advertised). The explicit no-peer path lives at
        // V1Header::unknown() / Configurator::v1Unknown().
        if ($autoDetect && $detectedFamily === self::FAMILY_UNKNOWN) {
            throw new Exception(sprintf(
                'Source IP %s is neither valid IPv4 nor IPv6 (use V1Header::unknown() if no peer info is available)',
                $srcIp
            ));
        }

        if ($detectedFamily !== self::FAMILY_UNKNOWN) {
            $ipv4Flag = ($detectedFamily === self::FAMILY_TCP4) ? \FILTER_FLAG_IPV4 : \FILTER_FLAG_IPV6;
            if (filter_var($srcIp, \FILTER_VALIDATE_IP, $ipv4Flag) === false) {
                throw new Exception(sprintf('Source IP %s is not a valid %s address', $srcIp, $detectedFamily));
            }
            if (filter_var($dstIp, \FILTER_VALIDATE_IP, $ipv4Flag) === false) {
                throw new Exception(sprintf('Destination IP %s is not a valid %s address', $dstIp, $detectedFamily));
            }
            if ($srcPort < 0 || $srcPort > 65535) {
                throw new Exception(sprintf('Source port %d out of range', $srcPort));
            }
            if ($dstPort < 0 || $dstPort > 65535) {
                throw new Exception(sprintf('Destination port %d out of range', $dstPort));
            }
        }

        $this->family = $detectedFamily;
        $this->srcIp = $srcIp;
        $this->dstIp = $dstIp;
        $this->srcPort = $srcPort;
        $this->dstPort = $dstPort;
    }

    /**
     * Convenience constructor — guesses family from the source IP.
     */
    public static function auto(string $srcIp, string $dstIp, int $srcPort, int $dstPort): self
    {
        return new self($srcIp, $dstIp, $srcPort, $dstPort);
    }

    /**
     * `PROXY UNKNOWN\r\n` — the spec-mandated form when the receiver should
     * ignore the rest of the header. Useful as a "no-op but still advertise
     * v1" path when we have no real source IP.
     */
    public static function unknown(): self
    {
        return new self('0.0.0.0', '0.0.0.0', 0, 0, self::FAMILY_UNKNOWN);
    }

    public function build(): string
    {
        if ($this->family === self::FAMILY_UNKNOWN) {
            return "PROXY UNKNOWN\r\n";
        }

        $header = sprintf(
            "PROXY %s %s %s %d %d\r\n",
            $this->family,
            $this->srcIp,
            $this->dstIp,
            $this->srcPort,
            $this->dstPort
        );

        if (strlen($header) > self::MAX_LENGTH + 2) { // CRLF padding
            throw new Exception('PROXY v1 header exceeded the spec maximum line length');
        }

        return $header;
    }

    public function version(): int
    {
        return 1;
    }

    public function family(): string
    {
        return $this->family;
    }

    public function sourceIp(): string
    {
        return $this->srcIp;
    }

    public function destinationIp(): string
    {
        return $this->dstIp;
    }

    public function sourcePort(): int
    {
        return $this->srcPort;
    }

    public function destinationPort(): int
    {
        return $this->dstPort;
    }

    private static function detectFamily(string $ip): string
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            return self::FAMILY_TCP6;
        }
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return self::FAMILY_TCP4;
        }
        return self::FAMILY_UNKNOWN;
    }
}
