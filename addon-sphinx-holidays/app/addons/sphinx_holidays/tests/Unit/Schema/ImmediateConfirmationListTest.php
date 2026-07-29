<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "Hotels with immediate confirmation" switch end-to-end.
 *
 * The confirmation field exists only on live search offers
 * (/api/v1/hotels/results), never in the static hotel catalog the sync
 * imports — so "only immediate hotels" is implemented as: the sync's
 * availability gate probes searches and DELETES hotels with no
 * confirmation=immediate offer. That reaches the product catalog too — a
 * LINKED hotel loses its CS-Cart product first and its row second — so
 * unbookable hotels exist nowhere: not in the hotel list, not as products.
 * The grid consequently has NO hide-filter: it shows exactly what is
 * stored. One setting drives all of it, default ON.
 */
final class ImmediateConfirmationListTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testSettingIsACheckboxDefaultOnWithTheRequestedLabel(): void
    {
        $xml = self::src('addon.xml');

        $pos = strpos($xml, '<item id="require_immediate_availability">');
        self::assertIsInt($pos, 'setting must stay declared in addon.xml');
        $item = substr($xml, $pos, 220);
        self::assertStringContainsString('<type>checkbox</type>', $item);
        self::assertStringContainsString('<default_value>Y</default_value>', $item, 'default is checked');

        self::assertStringContainsString('<![CDATA[Hotels with immediate confirmation]]>', $xml);
        self::assertStringContainsString('<![CDATA[Hoteluri cu confirmare imediată]]>', $xml);
    }

    public function testGridShowsExactlyWhatIsStoredWithNoHideFilter(): void
    {
        // The rule lives in the DATA (the gate deletes unbookable hotels and
        // their products), so the grid never filters — hiding rows would just
        // disguise legacy-flagged backlog until the next cron resolves it.
        self::assertStringContainsString(
            'HotelAdminListingRepository($perPage > 0 ? $perPage : 50)',
            self::src('func.php'),
        );

        $repo = self::src('src/Repository/HotelAdminListingRepository.php');
        self::assertStringNotContainsString('onlyImmediateConfirmation', $repo);
        self::assertStringNotContainsString('hidden_no_availability', $repo);

        // …and the sync gate reads the ONE setting, so a single checkbox
        // governs list + product catalog + storefront search.
        self::assertStringContainsString(
            'ConfigProvider::shouldRequireImmediateAvailability()',
            self::src('src/Services/HotelSyncService.php'),
        );
    }

    public function testGateDeletesUnlinkedButHidesLinkedProductsAndRestoresThem(): void
    {
        // User ruling, second iteration: unbookable UNLINKED hotels are
        // deleted, but a LINKED hotel keeps row + product — the product is
        // set to Hidden (reachable by direct link, absent from listings and
        // searches) and reactivated when immediate availability returns.
        // Deleting a seasonal hotel's product would burn its URL and SEO.
        $gate = self::src('src/Services/HotelAvailabilityGate.php');
        self::assertStringContainsString('deleteUnlinkedBatch($toDelete)', $gate);
        self::assertStringContainsString('hideProductsBatch($toHideProducts)', $gate);
        self::assertStringContainsString('markSkippedBatch($toHideHotels', $gate);
        self::assertStringContainsString('showProductsBatch($toRestoreProducts)', $gate);
        // The delete-the-product experiment is over.
        self::assertStringNotContainsString('fn_delete_product', $gate);

        // Both status flips are guarded so an admin's manual Hidden/Disabled
        // choice is never overridden in either direction.
        $repo = self::src('src/Repository/HotelSkipRepository.php');
        self::assertStringContainsString("SET status = 'H' WHERE product_id IN (?n) AND status = 'A'", $repo);
        self::assertStringContainsString("SET status = 'A' WHERE product_id IN (?n) AND status = 'H'", $repo);

        // Candidates include LINKED hotels — the old unlinked-only predicate
        // is exactly what kept unbookable hotels visible in CS-Cart searches.
        self::assertStringContainsString(
            'SELECT hotel_id, destination_id, product_id, product_skip_reason',
            $repo,
        );

        // The setting tooltip documents the hide/restore behaviour.
        $xml = self::src('addon.xml');
        self::assertStringNotContainsString('are never deleted', $xml);
        self::assertStringContainsString('set to Hidden', $xml);
        self::assertStringContainsString('reactivated when immediate availability returns', $xml);
    }

    public function testHotelsGridHasNoHiddenRowsNotice(): void
    {
        // The notice ("[count] hotel(s) hidden…") described the hide-filter;
        // both are gone, and with them the raw
        // "_sphinx_holidays.hotels_hidden_no_availability" label that stores
        // with an unseeded lang table rendered above the grid.
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl',
        );
        self::assertStringNotContainsString('hidden_no_availability', $tpl);

        self::assertStringNotContainsString('hotels_hidden_no_availability', self::src('lang_keys.php'));
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(
                dirname(__DIR__, 6) . '/var/langs/' . $lang . '/addons/sphinx_holidays.po',
            );
            self::assertStringNotContainsString('hotels_hidden_no_availability', $po);
        }
    }
}
