<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the admin Hotels grid's adoption of CS-Cart's NATIVE bulk-action
 * pattern (the products.manage "N selected" contextual bar).
 *
 * CS-Cart core is not vendored in this repo — the microformats resolve
 * against the deployed store's js/tygh/backend/bulkedit.js + tap/longtap
 * driver (their contract was extracted from the live store's own assets).
 * This scan is therefore the CI-checkable proxy: it fails if the required
 * markers are removed or if the old hand-rolled bar/JS creeps back, and it
 * pins the POST contract the three unchanged backend handlers rely on.
 */
final class HotelsGridBulkBarTest extends TestCase
{
    private static function template(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testNativeBulkBarMarkersPresent(): void
    {
        $tpl = self::template();

        foreach ([
            '{script src="js/tygh/backend/bulkedit.js"}',
            'data-ca-bulkedit-component="true"',
            'data-ca-longtap="true"',
            'data-ca-bulkedit-default-object="true"',
            'data-ca-bulkedit-expanded-object="true"',
            'class="bulkedit-toggler"',
            'class="bulkedit-disabler btn"',
            'data-ca-longtap-selected-counter="true"',
            'class="bulkedit-deselect"',
            'class="cm-check-items"',
            'data-ca-longtap-action="setCheckBox"',
            'data-ca-longtap-target="input.cm-item"',
            'class="cm-item"',
        ] as $marker) {
            self::assertStringContainsString($marker, $tpl, "missing native bulk-bar marker: {$marker}");
        }
    }

    public function testActionsPostToTheUnchangedHandlers(): void
    {
        $tpl = self::template();

        // The bar submits the surrounding form — hotel_ids[] checkboxes plus
        // these dispatch[] buttons ARE the handlers' existing POST contract.
        self::assertStringContainsString('name="hotel_ids[]"', $tpl);
        self::assertStringContainsString('name="security_hash"', $tpl);
        self::assertStringContainsString('name="bulk_status"', $tpl);
        self::assertSame(
            2,
            substr_count($tpl, 'name="dispatch[sphinx_holidays.bulk_update_hotels]"'),
            'Activate + Deactivate both post to bulk_update_hotels (bulk_status disambiguates)',
        );
        self::assertStringContainsString('name="dispatch[sphinx_holidays.bulk_sync_images]"', $tpl);
        self::assertStringContainsString('name="dispatch[sphinx_holidays.bulk_delete_hotels]"', $tpl);
        self::assertStringContainsString('cm-confirm', $tpl, 'delete keeps a confirmation step');
    }

    public function testOldHandRolledBarIsGone(): void
    {
        $tpl = self::template();

        self::assertStringNotContainsString('toggleAllHotels', $tpl);
        self::assertStringNotContainsString('cm-item-hotel', $tpl);
        self::assertStringNotContainsString('with_selected', $tpl, 'the static "With selected" strip was replaced');
    }

    // ── City/Country columns from the hotel API address (not the dest tree) ──

    public function testGridShowsAddressCityAndCountryColumns(): void
    {
        $tpl = self::template();

        // Cells render the per-hotel API address fields.
        self::assertStringContainsString('{$hotel.address_city|default:"-"|escape:html}', $tpl);
        self::assertStringContainsString('{$hotel.address_country|default:"-"|escape:html}', $tpl);
        // Sortable headers labelled City / Country.
        self::assertStringContainsString('sort_by=address_city', $tpl);
        self::assertStringContainsString('sort_by=address_country', $tpl);
        self::assertStringContainsString('sphinx_holidays.city', $tpl);
        self::assertStringContainsString('sphinx_holidays.country', $tpl);
        // City text filter in the sidebar.
        self::assertStringContainsString('name="city"', $tpl);
    }

    public function testTreeRegionCityColumnsAndCascadingFiltersRemoved(): void
    {
        $tpl = self::template();

        // The tree-derived Region / City-Resort cells are no longer rendered.
        self::assertStringNotContainsString('$hotel.region_name', $tpl);
        self::assertStringNotContainsString('$hotel.destination_name', $tpl);
        // The cascading tree filter selects + their JS are gone from this page.
        self::assertStringNotContainsString('sphinx_region_filter', $tpl);
        self::assertStringNotContainsString('sphinx_city_filter', $tpl);
        self::assertStringNotContainsString('get_regions', $tpl);
        self::assertStringNotContainsString('get_cities', $tpl);
    }
}
