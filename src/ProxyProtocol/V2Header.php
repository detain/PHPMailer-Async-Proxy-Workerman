<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol v2 header.
 *
 * @see       https://github.com/detain/PHPMailer-Async-Proxy-Workerman
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\PHPMailer\ProxyProtocol;

/**
 * PROXY Protocol v2 — binary frame format. Layout:
 *
 *   - 12-byte signature \x0D\x0A\x0D\x0A\x00\x0D\x0A\x51\x55\x49\x54\x0A
 *   - 1 byte           version (high nibble, 0x2) + command (low nibble: 0=LOCAL, 1=PROXY)
 *   - 1 byte           family (high nibble: 0=AF_UNSPEC, 1=AF_INET, 2=AF_INET6, 3=AF_UNIX)
 *                       + transport (low nibble: 0=UNSPEC, 1=STREAM, 2=DGRAM)
 *   - 2 bytes BE       address-block length
 *   - addresses:
 *       TCP/IPv4 -> 4+4+2+2 = 12 bytes
 *       TCP/IPv6 -> 16+16+2+2 = 36 bytes
 *
 * @see https://www.haproxy.org/download/2.8/doc/proxy-protocol.txt §2.2
 */
final class V2Header implements HeaderBuilder
{
    public const SIGNATURE = "\x0D\x0A\x0D\x0A\x00\x0D\x0A\x51\x55\x49\x54\x0A";

    public const COMMAND_LOCAL = 0x20;
    public const COMMAND_PROXY = 0x21;

    public const FAMILY_UNSPEC = 0x00;
    public const FAMILY_TCP4 = 0x11;
    public const FAMILY_TCP6 = 0x21;

    private int $command;
    private int $family;
    private string $srcIp;
    private string $dstIp;
    private int $srcPort;
    private int $dstPort;

    /**
     * @param int    $command  COMMAND_PROXY (default) or COMMAND_LOCAL.
     * @param int    $family   FAMILY_TCP4 / FAMILY_TCP6 / FAMILY_UNSPEC.
     * @param string $srcIp    Source IP in presentation form.
     * @param string $dstIp    Destination IP in presentation form.
     * @param int    $srcPort  0–65535.
     * @param int    $dstPort  0–65535.
     *
     * @throws Exception on invalid combinations or out-of-range inputs.
     */
    public function __construct(
        int $command,
        int $family,
        string $srcIp,
        string $dstIp,
        int $srcPort,
        int $dstPort
    ) {
        if ($command !== self::COMMAND_PROXY && $command !== self::COMMAND_LOCAL) {
            throw new Exception(sprintf('Invalid v2 command byte 0x%02X', $command));
        }
        if (!in_array($family, [self::FAMILY_UNSPEC, self::FAMILY_TCP4, self::FAMILY_TCP6], true)) {
            throw new Exception(sprintf('Unsupported v2 family byte 0x%02X', $family));
        }

        if ($family === self::FAMILY_TCP4) {
            self::ensureV4($srcIp, 'source');
            self::ensureV4($dstIp, 'destination');
        } elseif ($family === self::FAMILY_TCP6) {
            self::ensureV6($srcIp, 'source');
            self::ensureV6($dstIp, 'destination');
        }

        self::ensurePort($srcPort, 'source');
        self::ensurePort($dstPort, 'destination');

        $this->command = $command;
        $this->family = $family;
        $this->srcIp = $srcIp;
        $this->dstIp = $dstIp;
        $this->srcPort = $srcPort;
        $this->dstPort = $dstPort;
    }

    /** Convenience constructor — TCP/IPv4 PROXY command. */
    public static function tcp4(string $srcIp, string $dstIp, int $srcPort, int $dstPort): self
    {
        return new self(self::COMMAND_PROXY, self::FAMILY_TCP4, $srcIp, $dstIp, $srcPort, $dstPort);
    }

    /** Convenience constructor — TCP/IPv6 PROXY command. */
    public static function tcp6(string $srcIp, string $dstIp, int $srcPort, int $dstPort): self
    {
        return new self(self::COMMAND_PROXY, self::FAMILY_TCP6, $srcIp, $dstIp, $srcPort, $dstPort);
    }

    /**
     * Detect IPv4 vs IPv6 from the source IP. Throws when it's neither.
     */
    public static function auto(string $srcIp, string $dstIp, int $srcPort, int $dstPort): self
    {
        if (filter_var($srcIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            return self::tcp6($srcIp, $dstIp, $srcPort, $dstPort);
        }
        if (filter_var($srcIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return self::tcp4($srcIp, $dstIp, $srcPort, $dstPort);
        }
        throw new Exception(sprintf('Source IP %s is neither IPv4 nor IPv6', $srcIp));
    }

    public function build(): string
    {
        $src = inet_pton($this->srcIp);
        $dst = inet_pton($this->dstIp);
        if ($src === false || $dst === false) {
            throw new Exception('Unable to pack PROXY v2 addresses');
        }
        $addresses = $src . $dst;

        $portsPacked = pack('nn', $this->srcPort, $this->dstPort);
        $payload = $addresses . $portsPacked;
        $addressLength = strlen($payload);

        return self::SIGNATURE
            . chr($this->command)
            . chr($this->family)
            . pack('n', $addressLength)
            . $payload;
    }

    public function version(): int
    {
        return 2;
    }

    public function command(): int
    {
        return $this->command;
    }

    public function family(): int
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

    /**
     * Parse a v2 header (handy for tests + downstream consumers that want to
     * inspect what a peer wrote). Returns null when the bytes are not a v2
     * frame. The buffer may contain trailing data beyond the header — `$bytes`
     * is consumed in full and the caller can compute the next offset from
     * the returned `length` field.
     *
     * @return array{
     *     command: int,
     *     family: int,
     *     src_ip: string,
     *     dst_ip: string,
     *     src_port: int,
     *     dst_port: int,
     *     header_length: int
     * }|null
     */
    public static function parse(string $bytes): ?array
    {
        if (strlen($bytes) < 16 || substr($bytes, 0, 12) !== self::SIGNATURE) {
            return null;
        }
        $verCmd = ord($bytes[12]);
        $family = ord($bytes[13]);
        $addrLen = unpack('n', substr($bytes, 14, 2))[1];
        if (strlen($bytes) < 16 + $addrLen) {
            return null;
        }

        if (($verCmd & 0xF0) !== 0x20) {
            return null; // not v2
        }

        if ($family === self::FAMILY_TCP4 && $addrLen >= 12) {
            $src = inet_ntop(substr($bytes, 16, 4));
            $dst = inet_ntop(substr($bytes, 20, 4));
            $sport = unpack('n', substr($bytes, 24, 2))[1];
            $dport = unpack('n', substr($bytes, 26, 2))[1];
        } elseif ($family === self::FAMILY_TCP6 && $addrLen >= 36) {
            $src = inet_ntop(substr($bytes, 16, 16));
            $dst = inet_ntop(substr($bytes, 32, 16));
            $sport = unpack('n', substr($bytes, 48, 2))[1];
            $dport = unpack('n', substr($bytes, 50, 2))[1];
        } else {
            return [
                'command' => $verCmd,
                'family' => $family,
                'src_ip' => '',
                'dst_ip' => '',
                'src_port' => 0,
                'dst_port' => 0,
                'header_length' => 16 + $addrLen,
            ];
        }

        return [
            'command' => $verCmd,
            'family' => $family,
            'src_ip' => (string) $src,
            'dst_ip' => (string) $dst,
            'src_port' => (int) $sport,
            'dst_port' => (int) $dport,
            'header_length' => 16 + $addrLen,
        ];
    }

    private static function ensureV4(string $ip, string $label): void
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            throw new Exception(sprintf('%s IP %s is not valid IPv4', ucfirst($label), $ip));
        }
    }

    private static function ensureV6(string $ip, string $label): void
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
            throw new Exception(sprintf('%s IP %s is not valid IPv6', ucfirst($label), $ip));
        }
    }

    private static function ensurePort(int $port, string $label): void
    {
        if ($port < 0 || $port > 65535) {
            throw new Exception(sprintf('%s port %d out of range', ucfirst($label), $port));
        }
    }
}
