<?php

/**
 * PHPMailer-Async-Proxy-Workerman — PROXY Protocol v1 header tests.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test\ProxyProtocol;

use PHPMailer\PHPMailer\ProxyProtocol\Configurator;
use PHPMailer\PHPMailer\ProxyProtocol\Exception;
use PHPMailer\PHPMailer\ProxyProtocol\V1Header;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class V1HeaderTest extends TestCase
{
    public function testIpv4HeaderProducesExactWireFormat(): void
    {
        $header = new V1Header('203.0.113.45', '10.0.0.1', 54321, 25);

        self::assertSame("PROXY TCP4 203.0.113.45 10.0.0.1 54321 25\r\n", $header->build());
        self::assertSame(1, $header->version());
        self::assertSame(V1Header::FAMILY_TCP4, $header->family());
    }

    public function testIpv6HeaderProducesExactWireFormat(): void
    {
        $header = new V1Header('2001:db8::1', '2001:db8::2', 12345, 587);

        self::assertSame("PROXY TCP6 2001:db8::1 2001:db8::2 12345 587\r\n", $header->build());
        self::assertSame(V1Header::FAMILY_TCP6, $header->family());
    }

    public function testFamilyIsAutoDetectedFromSourceIp(): void
    {
        self::assertSame(
            V1Header::FAMILY_TCP4,
            V1Header::auto('1.2.3.4', '5.6.7.8', 1, 2)->family()
        );
        self::assertSame(
            V1Header::FAMILY_TCP6,
            V1Header::auto('::1', '::2', 1, 2)->family()
        );
    }

    public function testUnknownHeaderHasSpecMandatedForm(): void
    {
        self::assertSame("PROXY UNKNOWN\r\n", V1Header::unknown()->build());
    }

    public function testInvalidSourceIpThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Source IP not-an-ip is not a valid TCP4 address');

        new V1Header('not-an-ip', '10.0.0.1', 1, 2, V1Header::FAMILY_TCP4);
    }

    public function testFamilyMismatchedToSourceThrows(): void
    {
        $this->expectException(Exception::class);

        // explicit IPv4 family with an IPv6 src should fail
        new V1Header('::1', '::2', 1, 2, V1Header::FAMILY_TCP4);
    }

    public function testPortOutOfRangeThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Source port -1 out of range');

        new V1Header('1.2.3.4', '5.6.7.8', -1, 25);
    }

    public function testBuildMatchesLegacySyncImplementation(): void
    {
        // Byte-for-byte parity with app/Mail/ProxySMTP::sendProxyHeader() in
        // the parent project so the swap-over in step 14 is observably
        // identical on the wire.
        $expected = sprintf("PROXY %s %s %s %d %d\r\n", 'TCP4', '198.51.100.7', '10.20.30.40', 33333, 25);
        $header = new V1Header('198.51.100.7', '10.20.30.40', 33333, 25);

        self::assertSame($expected, $header->build());
    }

    public function testConfiguratorV1Helper(): void
    {
        $builder = Configurator::v1('203.0.113.45', '10.0.0.1', 54321, 25);

        self::assertInstanceOf(V1Header::class, $builder);
        self::assertSame("PROXY TCP4 203.0.113.45 10.0.0.1 54321 25\r\n", $builder->build());
    }

    public function testConfiguratorDisabledIsNull(): void
    {
        self::assertNull(Configurator::disabled());
    }

    public function testBuiltHeaderRespectsSpecMaxLength(): void
    {
        // longest realistic IPv6 produces ~ 76 bytes, well under 107.
        $src = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $dst = '2001:0db8:85a3:0000:0000:8a2e:0370:7335';
        $built = (new V1Header($src, $dst, 65535, 65535))->build();

        self::assertLessThanOrEqual(V1Header::MAX_LENGTH + 2, strlen($built));
    }
}
