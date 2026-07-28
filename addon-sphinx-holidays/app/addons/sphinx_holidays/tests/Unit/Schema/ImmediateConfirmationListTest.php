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
 * the sync's availability gate probes searches and flags hotels with no
 * confirmation=immediate offer (reversibly), product creation skips the
 * flagged ones, and the admin hotels grid hides them while reporting how
 * many are hidden. One setting drives all of it, default ON.
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
