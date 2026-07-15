<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Circuits & Package-routes admin surface: read-only paginated grids
 * (the CS-Cart pagination.tpl include + sortable headers), a per-page "Sync now"
 * control, the two menu tabs, and the backend controller GET/POST modes. These
 * are the admin UI over the already-existing static-data sync pipeline.
 */
final class CircuitsPackageRoutesGridTest extends TestCase
{
    private static function viewTpl(string $file): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testCircuitsGridHasPaginationSortAndSync(): void
    {
        $tpl = self::viewTpl('circuits.tpl');

        self::assertStringContainsString('common/pagination.tpl', $tpl);
        self::assertStringContainsString('$search.sort_order_toggle', $tpl);
        self::assertStringContainsString('{foreach from=$circuits', $tpl);
        self::assertStringContainsString('sphinx_holidays.sync_circuits', $tpl);
        self::assertStringContainsString('sphinx_holidays.sync_now', $tpl);
    }

    public function testPackageRoutesGridHasPaginationSortAndSync(): void
    {
        $tpl = self::viewTpl('package_routes.tpl');

        self::assertStringContainsString('common/pagination.tpl', $tpl);
        self::assertStringContainsString('$search.sort_order_toggle', $tpl);
        self::assertStringContainsString('{foreach from=$package_routes', $tpl);
        self::assertStringContainsString('sphinx_holidays.sync_package_routes', $tpl);
        self::assertStringContainsString('sphinx_holidays.sync_now', $tpl);
    }

    public function testMenuTabsRegisterBothPages(): void
    {
        $path = dirname(__DIR__, 3) . '/schemas/menu/actions.post.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        // Each dispatch string appears as a tab href, a lang-key label, AND in
        // $pages (so the tab strip renders on the new pages too) — at least the
        // href + $pages pair must be present.
        self::assertGreaterThanOrEqual(2, substr_count($src, "'sphinx_holidays.circuits'"));
        self::assertGreaterThanOrEqual(2, substr_count($src, "'sphinx_holidays.package_routes'"));
        // The pages list must carry both so the tabs render on the grid pages.
        self::assertMatchesRegularExpression('/\$pages\s*=\s*\[.*sphinx_holidays\.circuits.*sphinx_holidays\.package_routes.*\];/s', $src);
    }

    public function testBackendControllerRoutesGridAndSyncModes(): void
    {
        $path = dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString("\$mode === 'circuits'", $src);
        self::assertStringContainsString("\$mode === 'package_routes'", $src);
        self::assertStringContainsString("\$mode === 'sync_circuits'", $src);
        self::assertStringContainsString("\$mode === 'sync_package_routes'", $src);
    }

    /**
     * The dashboard cron-URL base must come from config.http_location, not
     * fn_url('', 'C') — the latter already ends in "index.php" on installs
     * without SEO clean-URLs, so appending "index.php?..." produced a broken
     * "index.phpindex.php" (surfaced by the local Docker sandbox).
     */
    public function testCronUrlBaseUsesHttpLocationNotFnUrl(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php',
        );

        self::assertStringContainsString("Registry::get('config.http_location')", $src);
        self::assertStringNotContainsString("\$base_url = TypeCoerce::toString(fn_url('', 'C'))", $src);
    }
}
