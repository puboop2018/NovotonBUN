<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the novoton search-results surface AFTER the inline-results change:
 * offers render inside the product page (the engine swaps everything after
 * .travel-search-form-wrapper into the PDP's shell), so the page carries no
 * hotel header, no availability badge and no below-list terms panels — and
 * everything interactive must live INSIDE the swap region or be
 * document-delegated to survive the swap.
 */
final class AvailabilityBadgeTest extends TestCase
{
    private const TPL_RELATIVE = '/templates/addons/novoton_holidays/views/novoton_booking/search.tpl';

    private static function themeTpl(string $theme): string
    {
        $path = dirname(__DIR__, 6) . '/design/themes/' . $theme . self::TPL_RELATIVE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testHotelHeaderAndBadgeAreGone(): void
    {
        $tpl = self::themeTpl('responsive');

        // The strip carried the shared hotel_header include, the season note
        // and the "✓ Available" badge — all redundant when the results sit on
        // the product page, all removed together.
        self::assertStringNotContainsString('travel-hotel-header', $tpl);
        self::assertStringNotContainsString('addons/travel_core/components/hotel_header.tpl', $tpl);
        self::assertStringNotContainsString('travel-availability-block', $tpl);
        self::assertStringNotContainsString('travel-availability-badge', $tpl);
        self::assertStringNotContainsString('$badge_room_keys', $tpl);
    }

    public function testBelowListTermsPanelsAreGone(): void
    {
        $tpl = self::themeTpl('responsive');

        // Every offer row links its own terms modal (novoton_holidays.
        // cancellation_and_payment_terms links + per-row modal sections STAY)
        // — only the page-level panels under the list were duplicates.
        // parsed_payment_terms and the "…disponibile" fallback existed only
        // in those panels.
        self::assertStringNotContainsString('travel-terms-panels', $tpl);
        self::assertStringNotContainsString('parsed_payment_terms', $tpl);
        self::assertStringNotContainsString('cancellation_terms_available', $tpl);
        self::assertStringContainsString('novoton_holidays.cancellation_and_payment_terms', $tpl);
    }

    public function testInfoModalTravelsWithTheSwappedResults(): void
    {
        $tpl = self::themeTpl('responsive');

        // The swap copies only nodes AFTER the form wrapper INSIDE the
        // results-page container — a modal outside it would never reach the
        // product page.
        $formPos = strpos($tpl, 'travel-search-form-wrapper');
        $modalPos = strpos($tpl, 'id="info-modal"');
        self::assertIsInt($formPos);
        self::assertIsInt($modalPos);
        self::assertGreaterThan($formPos, $modalPos, 'modal renders after the form, inside the swap region');
        self::assertStringContainsString('INSIDE .travel-search-results-page', $tpl);

        // Its behavior binds at the document level: an element-bound handler
        // would die when a re-search replaces the modal node.
        $js = (string) file_get_contents(dirname(__DIR__, 6) . '/js/addons/novoton_holidays/search-results.js');
        self::assertStringContainsString("document.addEventListener('click'", $js);
        self::assertStringNotContainsString('modal.addEventListener', $js);
    }

    public function testSearchResultsJsLoadsOnProductPagesToo(): void
    {
        $hook = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/novoton_holidays/hooks/index/scripts.post.tpl',
        );
        self::assertStringContainsString(
            '{script src="js/addons/novoton_holidays/search-results.js"}',
            $hook,
            'the PDP needs the modal globals for swapped-in offers',
        );
        self::assertStringContainsString("substr:0:9 == 'products.'", $hook);
    }

    public function testFormatterNoLongerBuildsHeaderVmOrTerms(): void
    {
        $php = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Services/SearchResultFormatter.php',
        );

        self::assertStringNotContainsString('HotelHeaderFactory', $php);
        self::assertStringNotContainsString("assign('travel_hotel_header'", $php);
        self::assertStringNotContainsString('assignTerms', $php);
        self::assertStringNotContainsString("assign('parsed_payment_terms'", $php);
        // The no-results box and the request-alternatives form still need the
        // plain identity/season assigns.
        self::assertStringContainsString("assign('hotel_name'", $php);
        self::assertStringContainsString("assign('hotel_season_from'", $php);
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        self::assertSame(
            self::themeTpl('responsive'),
            self::themeTpl('nova_theme'),
            'the responsive and nova_theme search.tpl copies must stay in sync',
        );
    }
}
