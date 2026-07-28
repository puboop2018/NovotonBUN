<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the two recoveries for a lost ?:sphinx_hotels.product_id link.
 *
 * Sphinx hotel products carry <ISO-2 country><hotel_id> codes (TR3612), but
 * the PDP resolves hotels BY product_id — when the link column is wiped
 * (fresh reinstall, table re-sync) the product renders as a plain catalog
 * item: no booking form, no location line (field case: Rixos Downtown,
 * TR3612). Two independent healers keep that state from sticking:
 *
 *  1. SphinxHotelProductProvider::resolveProduct falls back to parsing the
 *     product code and re-writes the link on first product-page view;
 *  2. HotelSyncService::relinkExistingProducts scans the REAL code shape —
 *     it used to scan only the legacy configured prefix ('SPX'), which no
 *     synced product has carried since country prefixes shipped, so the
 *     relink pass silently matched nothing.
 */
final class HotelProductRelinkTest extends TestCase
{
    public function testResolveProductHealsTheLinkFromTheProductCode(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Providers/SphinxHotelProductProvider.php',
        );

        // Code shape: exactly two uppercase letters + the hotel id. A 2-letter
        // match cannot swallow novoton's NVT+digits codes.
        self::assertStringContainsString("preg_match('/^[A-Z]{2}(\\d+)\$/', \$productCode, \$m)", $src);
        // The heal writes the link back…
        self::assertStringContainsString(
            'UPDATE ?:sphinx_hotels SET product_id = ?i WHERE hotel_id = ?s',
            $src,
        );
        // …but never steals a hotel already linked to a DIFFERENT product —
        // that conflict is served read-only and logged.
        self::assertStringContainsString('$linked !== $productId', $src);
        self::assertStringContainsString('fn_log_event', $src);
        // The row fetch must expose product_id for the conflict check.
        self::assertStringContainsString('SELECT hotel_id, product_id, name', $src);
    }

    public function testRelinkButtonIsGatedOnRELINKABLEProducts(): void
    {
        // The button used to render only when countOrphanedProducts() > 0 —
        // hotels whose PRODUCT was deleted, the opposite direction, which
        // relink cannot repair. On the common "hotel rows lost" store that
        // count is 0, so the only UI entry point to the repair was hidden
        // (and the action is POST-only, so no URL could stand in for it).
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6) . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/manage.tpl',
        );
        self::assertStringContainsString('{if $unlinked_sphinx_products > 0}', $tpl);
        self::assertStringContainsString('({$unlinked_sphinx_products})', $tpl);
        self::assertStringNotContainsString('{if $orphaned_spx_products > 0}', $tpl);

        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/backend/sphinx_holidays.php',
        );
        self::assertStringContainsString("\$view->assign('unlinked_sphinx_products'", $controller);
        self::assertStringContainsString('countUnlinkedProducts(', $controller);

        // The count must describe exactly what the relink pass will process:
        // sphinx-shaped codes with no hotel row pointing at the product.
        $stats = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Repository/HotelStatsRepository.php',
        );
        self::assertStringContainsString('public function countUnlinkedProducts(string $legacyPrefix): int', $stats);
        self::assertStringContainsString("'^[A-Z]{2}[0-9]+\$'", $stats);
        self::assertStringContainsString('NOT EXISTS', $stats);
        self::assertStringContainsString('h.product_id = p.product_id', $stats);
    }

    public function testRelinkPassScansTheCountryPrefixedCodeShape(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Services/HotelSyncService.php',
        );

        self::assertStringContainsString("product_code REGEXP ?s", $src);
        self::assertStringContainsString("'^[A-Z]{2}[0-9]+\$'", $src);
        // Per-row hotel-id extraction handles BOTH shapes (legacy prefix and
        // country prefix) — a fixed prefix-length substr would mangle one.
        self::assertStringContainsString('str_starts_with($code, $prefix)', $src);
        self::assertStringContainsString("preg_replace('/^[A-Z]{2}/', '', \$code)", $src);
    }
}
