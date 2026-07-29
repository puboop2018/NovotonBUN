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

    public function testGateDeletesUnavailableHotelsLinkedOnesWithTheirProduct(): void
    {
        // "We will have only hotels that have confirmation: immediate in the
        // hotel list AND in CS-Cart" — the gate's terminal action is DELETE,
        // not flag, and a linked hotel loses its CS-Cart product first, its
        // row second (only once the product is confirmed gone, so a refused
        // delete never strands an orphan product).
        $gate = self::src('src/Services/HotelAvailabilityGate.php');
        self::assertStringContainsString('deleteUnlinkedBatch($toDelete)', $gate);
        self::assertStringContainsString('fn_delete_product($productId)', $gate);
        self::assertStringContainsString('deleteBatch($rowsToDelete)', $gate);
        self::assertStringNotContainsString('markSkippedBatch', $gate);

        // Candidates include LINKED hotels — the old unlinked-only predicate
        // is exactly what kept unbookable hotels sellable in CS-Cart.
        $repo = self::src('src/Repository/HotelSkipRepository.php');
        self::assertStringContainsString(
            'SELECT hotel_id, destination_id, product_id, product_skip_reason',
            $repo,
        );

        // The setting tooltip must not promise the old exception.
        self::assertStringNotContainsString('are never deleted', self::src('addon.xml'));
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
