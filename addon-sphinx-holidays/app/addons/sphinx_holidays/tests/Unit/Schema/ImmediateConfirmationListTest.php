<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "Hotels with immediate confirmation" switch end-to-end.
 *
 * The confirmation field exists only on live search offers
 * (/api/v1/hotels/results), never in the static hotel catalog the sync
 * imports — so "only immediate hotels in the list" is implemented as:
 * the sync's availability gate probes searches and DELETES hotels with no
 * confirmation=immediate offer (hotels linked to CS-Cart products are kept),
 * and the admin hotels grid additionally hides any legacy-flagged rows until
 * the next sync purges them. One setting drives all of it, default ON.
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

    public function testGridListingIsWiredToTheSettingAndExcludesFlaggedHotels(): void
    {
        // func.php shell injects the setting (Registry access is banned in
        // src/ bodies, so the flag rides in through the constructor)…
        self::assertStringContainsString(
            'HotelAdminListingRepository($perPage > 0 ? $perPage : 50, '
            . '\Tygh\Addons\SphinxHolidays\Services\ConfigProvider::shouldRequireImmediateAvailability())',
            self::src('func.php'),
        );

        // …the repository hides gate-flagged rows and reports the count…
        $repo = self::src('src/Repository/HotelAdminListingRepository.php');
        self::assertStringContainsString('private readonly bool $onlyImmediateConfirmation', $repo);
        self::assertStringContainsString(
            'h.product_skip_reason IS NULL OR h.product_skip_reason != ?s',
            $repo,
        );
        self::assertStringContainsString('HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY', $repo);
        self::assertStringContainsString("\$params['hidden_no_availability']", $repo);

        // …and the sync gate that produces the flag reads the SAME setting,
        // so the one checkbox governs list + product creation + storefront.
        self::assertStringContainsString(
            'ConfigProvider::shouldRequireImmediateAvailability()',
            self::src('src/Services/HotelSyncService.php'),
        );
    }

    public function testGateDeletesUnavailableHotelsInsteadOfKeepingThem(): void
    {
        // "It is useless to have a huge database of hotels that might never
        // be available to be booked" — the gate's terminal action is DELETE,
        // not flag; the SQL re-checks the product link so linked hotels
        // survive even if they lose availability later.
        $gate = self::src('src/Services/HotelAvailabilityGate.php');
        self::assertStringContainsString('deleteUnlinkedBatch($toDelete)', $gate);
        self::assertStringNotContainsString('markSkippedBatch', $gate);

        $repo = self::src('src/Repository/HotelSkipRepository.php');
        self::assertStringContainsString('DELETE FROM ?:sphinx_hotels', $repo);
        self::assertStringContainsString(
            'hotel_id IN (?a) AND (product_id IS NULL OR product_id = 0)',
            $repo,
        );
    }

    public function testHotelsGridExplainsHiddenRows(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/backend/templates/addons/sphinx_holidays/views/sphinx_holidays/hotels.tpl',
        );
        self::assertStringContainsString('{if $search.hidden_no_availability}', $tpl);
        self::assertStringContainsString(
            '{__("sphinx_holidays.hotels_hidden_no_availability", ["[count]" => $search.hidden_no_availability])}',
            $tpl,
        );
    }
}
