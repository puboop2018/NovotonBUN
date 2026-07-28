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

    /**
     * The bar is laid out like products.manage: three dropdowns
     * ("N Selected ▾" with All/None, "Status ▾" with Change to Active/Disabled,
     * "Actions ▾" with Sync Images/Delete Selected) and NO icon glyphs.
     */
    public function testBarIsThreeDropdownsWithoutIcons(): void
    {
        $bar = self::expandedBar();

        self::assertSame(
            3,
            substr_count($bar, 'dropdown-toggle'),
            'the bar has exactly three dropdowns: Selected / Status / Actions',
        );

        // "N Selected ▾" — live counter inside the toggle; All + None items.
        self::assertStringContainsString('data-ca-longtap-selected-counter="true"', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_select_all', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_select_none', $bar);
        // "All" drives the native check_all control (same handlers as a user click).
        self::assertStringContainsString("getElementById('sphinx_check_all')", $bar);
        self::assertStringContainsString('id="sphinx_check_all"', $bar);
        // "None" rides the core deselect handler.
        self::assertStringContainsString('class="bulkedit-deselect"', $bar);

        // "Status ▾" — products.manage wording.
        self::assertStringContainsString('sphinx_holidays.bulk_change_to_active', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_change_to_disabled', $bar);
        self::assertStringNotContainsString('bulk_activate', $bar, 'old label replaced by Change to Active');
        self::assertStringNotContainsString('bulk_deactivate', $bar, 'old label replaced by Change to Disabled');

        // "Actions ▾" — Sync Images + Delete Selected as menu items.
        self::assertStringContainsString('sphinx_holidays.bulk_actions', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_sync_images', $bar);
        self::assertStringContainsString('sphinx_holidays.bulk_delete', $bar);

        // No icon glyphs anywhere in the bar.
        self::assertStringNotContainsString('<i class="icon-', $bar, 'the bulk bar is plain text — no icons');
    }

    /** The expanded (bulk-mode) <thead> region only. */
    private static function expandedBar(): string
    {
        $tpl = self::template();

        $start = strpos($tpl, 'data-ca-bulkedit-expanded-object="true"');
        self::assertIsInt($start);
        $end = strpos($tpl, '</thead>', $start);
        self::assertIsInt($end);

        return substr($tpl, $start, $end - $start);
    }

    // ── City/Country columns from the hotel API address (not the dest tree) ──

    /**
     * The City column now leads with the RESOLVED city — the value the
     * product's City feature uses — and only falls back to the raw API
     * address. (An earlier arc had dropped the tree-derived columns because
     * they were routinely empty; HotelLocationResolver made them reliable,
     * so they are authoritative again and the address became the fallback.)
     */
    public function testCityColumnShowsTheResolvedValueWithAddressAsFallback(): void
    {
        $tpl = self::template();

        self::assertStringContainsString('{if $hotel.destination_name}', $tpl);
        self::assertStringContainsString('{elseif $hotel.address_city}', $tpl);
        // Country still comes straight from the API address.
        self::assertStringContainsString('{$hotel.address_country|default:"-"|escape:html}', $tpl);
        // City sorts by the resolved column; Country by the address one.
        self::assertStringContainsString('sort_by=destination_name', $tpl);
        self::assertStringContainsString('sort_by=address_country', $tpl);
        self::assertStringContainsString('sphinx_holidays.city', $tpl);
        self::assertStringContainsString('sphinx_holidays.country', $tpl);
        // City text filter in the sidebar.
        self::assertStringContainsString('name="city"', $tpl);
    }

    public function testCityFilterSearchesBothTheResolvedAndTheAddressCity(): void
    {
        // Otherwise typing what the City column displays would find nothing.
        self::assertStringContainsString(
            'h.address_city LIKE ?l OR h.destination_name LIKE ?l',
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/src/Repository/HotelAdminListingRepository.php',
            ),
        );
    }

    public function testCascadingTreeFiltersStayRemoved(): void
    {
        $tpl = self::template();

        // The resolved Region/City CELLS are back (see above), but the
        // cascading tree filter selects + their JS stay gone from this page.
        self::assertStringNotContainsString('sphinx_region_filter', $tpl);
        self::assertStringNotContainsString('sphinx_city_filter', $tpl);
        self::assertStringNotContainsString('get_regions', $tpl);
        self::assertStringNotContainsString('get_cities', $tpl);
    }
}
