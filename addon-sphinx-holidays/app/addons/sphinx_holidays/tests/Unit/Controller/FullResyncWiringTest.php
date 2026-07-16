<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard "Sync hotels/destinations" buttons are INCREMENTAL; the "Full
 * re-sync" buttons post `full=1`. This pins the controller wiring that forwards
 * that flag to the sync services.
 *
 * A silent revert to an unconditional incremental sync is exactly what left stale
 * coordinates in place — an untouched hotel is filtered out by `updated_since`, so
 * its row (and its latitude/longitude) is never rewritten. `full=1` nulls that
 * filter so every row is re-fetched and overwritten.
 */
final class FullResyncWiringTest extends TestCase
{
    private static function controllerSrc(): string
    {
        $path = dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testHotelSyncForwardsTheFullFlag(): void
    {
        $src = self::controllerSrc();

        self::assertStringContainsString("\$fullSync = !empty(\$_REQUEST['full']);", $src);
        self::assertStringContainsString('$service->sync($countryCodes, [], $fullSync)', $src);
    }

    public function testDestinationSyncForwardsTheFullFlag(): void
    {
        $src = self::controllerSrc();

        self::assertStringContainsString('$service->sync($fullSync)', $src);
    }
}
