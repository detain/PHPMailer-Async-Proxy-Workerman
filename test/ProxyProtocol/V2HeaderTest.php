<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol v2 header tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\ProxyProtocol;

use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\ProxyProtocol\Exception;
use PHPMailer\PHPMailer\ProxyProtocol\V2Header;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class V2HeaderTest extends TestCase
{
    /**
     * Verifies byte-for-byte against the HAProxy v2 spec for TCP/IPv4
     * src=127.0.0.1:12345  dst=127.0.0.1:25
     *
     *   12-byte signature   0D 0A 0D 0A 00 0D 0A 51 55 49 54 0A
     *   1 byte ver+cmd      21         (v2 + PROXY)
     *   1 byte fam+proto    11         (AF_INET + STREAM)
     *   2 bytes length BE   00 0C      (12 bytes payload)
     *   payload             7F 00 00 01 7F 00 00 01 30 39 00 19
     */
    public function testTcp4HeaderMatchesSpecBytes(): void
    {
        $built = V2Header::tcp4('127.0.0.1', '127.0.0.1', 12345, 25)->build();

        $expected = V2Header::SIGNATURE
            . "\x21"     // ver=2, cmd=PROXY
            . "\x11"     // AF_INET + STREAM
            . "\x00\x0C" // 12-byte payload
            . "\x7F\x00\x00\x01" // 127.0.0.1 src
            . "\x7F\x00\x00\x01" // 127.0.0.1 dst
            . "\x30\x39" // 12345 src port (BE)
            . "\x00\x19" // 25 dst port (BE)
        ;
        self::assertSame(bin2hex($expected), bin2hex($built));
        self::assertSame(28, strlen($built));
    }

    public function testTcp6HeaderMatchesSpecBytes(): void
    {
        $built = V2Header::tcp6('::1', '::2', 1, 2)->build();

        $expected = V2Header::SIGNATURE
            . "\x21"
            . "\x21"     // AF_INET6 + STREAM
            . "\x00\x24" // 36-byte payload
            . inet_pton('::1')
            . inet_pton('::2')
            . "\x00\x01"
            . "\x00\x02"
        ;
        self::assertSame(bin2hex($expected), bin2hex($built));
        self::assertSame(52, strlen($built));
    }

    public function testParseRoundTripsAv4Header(): void
    {
        $built = V2Header::tcp4('203.0.113.45', '10.0.0.1', 54321, 25)->build();
        $parsed = V2Header::parse($built);

        self::assertNotNull($parsed);
        self::assertSame(V2Header::COMMAND_PROXY, $parsed['command']);
        self::assertSame(V2Header::FAMILY_TCP4, $parsed['family']);
        self::assertSame('203.0.113.45', $parsed['src_ip']);
        self::assertSame('10.0.0.1', $parsed['dst_ip']);
        self::assertSame(54321, $parsed['src_port']);
        self::assertSame(25, $parsed['dst_port']);
        self::assertSame(28, $parsed['header_length']);
    }

    public function testParseRoundTripsAv6Header(): void
    {
        $built = V2Header::tcp6('2001:db8::1', '2001:db8::2', 65535, 587)->build();
        $parsed = V2Header::parse($built);

        self::assertNotNull($parsed);
        self::assertSame(V2Header::FAMILY_TCP6, $parsed['family']);
        self::assertSame('2001:db8::1', $parsed['src_ip']);
        self::assertSame('2001:db8::2', $parsed['dst_ip']);
        self::assertSame(65535, $parsed['src_port']);
        self::assertSame(587, $parsed['dst_port']);
    }

    public function testParseReturnsNullForNonV2Bytes(): void
    {
        self::assertNull(V2Header::parse("PROXY TCP4 1.2.3.4 5.6.7.8 1 2\r\n"));
        self::assertNull(V2Header::parse("not a proxy header at all"));
        self::assertNull(V2Header::parse(""));
    }

    public function testParseTreatsParseAsAdditiveAtBufferStart(): void
    {
        // A real implementation might have trailing app-layer bytes — parse
        // must report only the header_length and leave the remainder for the caller.
        $built = V2Header::tcp4('1.2.3.4', '5.6.7.8', 80, 443)->build();
        $withTrailer = $built . "HELO example.com\r\n";

        $parsed = V2Header::parse($withTrailer);

        self::assertNotNull($parsed);
        self::assertSame(28, $parsed['header_length']);
    }

    public function testAutoDetectsIpFamily(): void
    {
        self::assertSame(V2Header::FAMILY_TCP4, V2Header::auto('1.2.3.4', '5.6.7.8', 1, 2)->family());
        self::assertSame(V2Header::FAMILY_TCP6, V2Header::auto('::1', '::2', 1, 2)->family());
    }

    public function testInvalidV4AddressThrows(): void
    {
        $this->expectException(Exception::class);
        V2Header::tcp4('999.999.999.999', '1.2.3.4', 80, 80);
    }

    public function testIpFamilyMismatchThrows(): void
    {
        $this->expectException(Exception::class);
        V2Header::tcp4('::1', '1.2.3.4', 80, 80);
    }

    public function testConfiguratorV2Helper(): void
    {
        $builder = Configurator::v2('1.2.3.4', '5.6.7.8', 1234, 5678);

        self::assertInstanceOf(V2Header::class, $builder);
        self::assertSame(28, strlen($builder->build()));
    }

    public function testConfiguratorOfVersionDispatch(): void
    {
        self::assertSame(1, Configurator::ofVersion(1, '1.2.3.4', '5.6.7.8', 1, 2)->version());
        self::assertSame(2, Configurator::ofVersion(2, '1.2.3.4', '5.6.7.8', 1, 2)->version());

        $this->expectException(Exception::class);
        Configurator::ofVersion(99, '1.2.3.4', '5.6.7.8', 1, 2);
    }
}
