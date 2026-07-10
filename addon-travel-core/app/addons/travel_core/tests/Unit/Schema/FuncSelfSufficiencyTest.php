<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards travel_core's INSTALLABILITY.
 *
 * During the addon's own installation it is not yet active, so CS-Cart never
 * runs init.php — and init.php is the ONLY registrar of the
 * Tygh\Addons\TravelCore\* autoloader. CS-Cart loads func.php and invokes the
 * fn_settings_variants_addons_travel_core_* callbacks while building the
 * selectbox settings, so ANY class referenced from func.php without an
 * explicit require makes the install die in a silent fatal ("click Install,
 * nothing happens" — shipped once, 2026-07-10, when TypeCoerce entered the
 * variants callbacks unguarded).
 *
 * The test reproduces that exact environment in a CLEAN php subprocess:
 * CS-Cart stubs only, NO autoloader, include func.php, call the variants
 * callbacks that CS-Cart calls during install.
 */
class FuncSelfSufficiencyTest extends TestCase
{
    public function testFuncPhpSurvivesInstallRequestWithoutAutoloader(): void
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/install_environment_sim.php';
        $funcPhp = dirname(__DIR__, 3) . '/func.php';
        self::assertFileExists($fixture);
        self::assertFileExists($funcPhp);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($funcPhp) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        self::assertSame(
            0,
            $exitCode,
            "func.php is not self-sufficient for the install request (no autoloader):\n{$output}",
        );
        self::assertStringContainsString(
            'OK variants',
            $output,
            "install-time settings-variants callbacks did not complete:\n{$output}",
        );
        self::assertStringNotContainsString('Fatal error', $output);
    }
}
