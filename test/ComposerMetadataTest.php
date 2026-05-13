<?php

/**
 * PHPMailer-Async-Proxy-Workerman — composer.json metadata invariants.
 *
 * @author    Joe Huss <detain@interserver.net>
 * @copyright 2026 Joe Huss
 * @license   https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace PHPMailer\Test;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pin down two composer.json invariants that have already burned us once:
 *
 *  1. The version string must be Composer-normalizable. `8.0.0-async.0`
 *     was rejected by Composer 2.x as `Invalid version string` — see
 *     codex review on PR #1. Use SemVer 2.0.0 build metadata (`+async.N`)
 *     or a recognized pre-release keyword.
 *
 *  2. The `replace` map must claim `phpmailer/phpmailer` so transitive
 *     deps that require upstream resolve against this fork — see codex
 *     review on PR #1.
 *
 *  3. The `workerman/workerman` constraint must require a version that
 *     ships `Workerman\Events\Fiber`, which is what `config/server.php`
 *     in the consumer wires the worker loop to. 5.0.x had a different
 *     class name; 5.1+ is what we depend on.
 */
final class ComposerMetadataTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $composer;

    public static function set_up_before_class(): void
    {
        self::$composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function testReplaceDeclaresPhpMailerUpstream(): void
    {
        self::assertArrayHasKey('replace', self::$composer);
        self::assertArrayHasKey(
            'phpmailer/phpmailer',
            self::$composer['replace'],
            'replace metadata for phpmailer/phpmailer is required so transitive '
                . 'deps requiring upstream phpmailer/phpmailer resolve against the fork'
        );
    }

    public function testReplaceConstraintIsBoundedToTheTrackedMajor(): void
    {
        $constraint = self::$composer['replace']['phpmailer/phpmailer'];

        // A `*` replace claims this fork satisfies every PHPMailer major
        // (6.x, future 8.x, ...). A consumer with a transitive
        // `phpmailer/phpmailer:^6` dep would then resolve to our 7.0.2-
        // based fork and likely break at runtime. The fork tracks 7.0.2;
        // the replace must be bounded to the 7.x line.
        self::assertNotSame(
            '*',
            $constraint,
            'replace.phpmailer/phpmailer must be bounded (e.g. ^7.0), not "*" '
                . '— see codex review on PR #19'
        );
        self::assertMatchesRegularExpression(
            '/^\^?7\./',
            $constraint,
            "replace.phpmailer/phpmailer = '{$constraint}' — must match the "
                . 'upstream major this fork tracks (currently 7.x).'
        );
    }

    public function testWorkermanConstraintShipsEventsFiberClass(): void
    {
        $constraint = self::$composer['require']['workerman/workerman'] ?? null;
        self::assertNotNull($constraint, 'workerman/workerman must be in require');

        // The class our consumer's config/server.php hard-codes is
        // Workerman\Events\Fiber, added in Workerman 5.1. The 5.0.x line
        // shipped the Revolt-backed driver under a different class name,
        // so `^5.0` is too loose — startup would crash on those installs.
        self::assertMatchesRegularExpression(
            '/\^?5\.([1-9]|\d{2,})/',
            $constraint,
            "workerman constraint '{$constraint}' must require >= 5.1 so "
                . 'Workerman\\Events\\Fiber is guaranteed to exist'
        );
    }

    public function testVersionConstantsAreComposerNormalizable(): void
    {
        $versionFile = trim((string) file_get_contents(__DIR__ . '/../VERSION'));
        // Composer accepts either a stability keyword (-alpha, -beta, -rc) or
        // SemVer 2.0.0 build metadata (+foo). The pre-PR `-async.0` form
        // was rejected by Composer 2.x. Assert the new form is one of those.
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc|dev)\d*)?(?:\+[A-Za-z0-9.-]+)?$/',
            $versionFile,
            "VERSION '{$versionFile}' must use Composer-acceptable suffixes"
        );

        // const VERSION on each public class must mirror VERSION.
        foreach (['PHPMailer', 'SMTP', 'POP3'] as $class) {
            $fq = 'PHPMailer\\PHPMailer\\' . $class;
            $ref = new \ReflectionClass($fq);
            $constant = $ref->getConstant('VERSION');
            self::assertSame(
                $versionFile,
                $constant,
                "{$fq}::VERSION ({$constant}) must equal the VERSION file ({$versionFile})"
            );
        }
    }
}
