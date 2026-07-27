<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the sphinx search-results surface AFTER the inline-results change:
 * the engine swaps everything after .travel-search-form-wrapper into the
 * product page's shell, so the page is headerless/badgeless and the async
 * poll handshake (search id/status), the params script and the terms modal
 * must all live INSIDE the swap region — with the poll re-armed via the
 * engine's travel:results-swapped event.
 */
final class SearchLayoutBadgeTest extends TestCase
{
    private static function searchTpl(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function searchJs(): string
    {
        $path = dirname(__DIR__, 6) . '/js/addons/sphinx_holidays/search-results.js';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testHotelHeaderAndBadgeAreGone(): void
    {
        $tpl = self::searchTpl();

        self::assertStringNotContainsString('travel-hotel-header', $tpl);
        self::assertStringNotContainsString('addons/travel_core/components/hotel_header.tpl', $tpl);
        self::assertStringNotContainsString('$sx_badge_room_keys', $tpl);
        self::assertStringNotContainsString('travel-availability-', $tpl);
        self::assertStringNotContainsString('data-party-suffix', $tpl);
    }

    public function testPollHandshakeLivesInsideTheSwapRegion(): void
    {
        $tpl = self::searchTpl();

        // The wrapper element itself is NEVER copied by the swap — only its
        // post-form children are. The id/status attrs therefore ride the
        // results container, which is inside the swap region.
        self::assertMatchesRegularExpression(
            '/id="sphinx-results-container"\s+data-search-id=/',
            $tpl,
            'search id/status ride #sphinx-results-container',
        );
        self::assertDoesNotMatchRegularExpression(
            '/travel-search-results-page sphinx-search-results"[^>]*data-search-id/s',
            $tpl,
            'the wrapper must not carry the handshake (it never reaches the PDP)',
        );

        // The terms modal must also sit AFTER the form wrapper (inside the
        // swap region). The poll params/labels ride data attributes on the
        // results container — an inline-script transport must not return
        // (its re-execution after the inline swap proved unreliable).
        $formPos = strpos($tpl, 'travel-search-form-wrapper');
        $modalPos = strpos($tpl, 'id="sphinx-terms-modal"');
        $paramsPos = strpos($tpl, 'data-client-params=');
        self::assertIsInt($formPos);
        self::assertIsInt($modalPos);
        self::assertIsInt($paramsPos);
        self::assertGreaterThan($formPos, $modalPos);
        self::assertGreaterThan($formPos, $paramsPos);
        self::assertStringNotContainsString('window.__sphinxSearchParams', $tpl);
        self::assertStringContainsString('INSIDE .travel-search-results-page', $tpl);
    }

    public function testPollReArmsOnResultsSwapped(): void
    {
        $js = self::searchJs();

        self::assertStringContainsString('function armPoll', $js);
        self::assertStringContainsString("document.addEventListener('travel:results-swapped', armPoll)", $js);
        self::assertStringContainsString("getElementById('sphinx-results-container')", $js);
        self::assertStringContainsString('armedSearchId', $js, 'arming twice for one search id must be a no-op');
        // The old wrapper-based lookup and the badge writer are gone.
        self::assertStringNotContainsString(".sphinx-search-results'", $js);
        self::assertStringNotContainsString('updateBadgeText', $js);
    }

    public function testControllerNoLongerBuildsTheHeaderViewModel(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/search.php',
        );
        self::assertStringNotContainsString('HotelHeaderFactory', $controller);
        self::assertStringNotContainsString("assign('travel_hotel_header'", $controller);
        // The no-results request form still posts the hotel name.
        self::assertStringContainsString("assign('sphinx_hotel_name'", $controller);
    }

    public function testSearchResultsJsLoadsOnProductPagesToo(): void
    {
        $hook = (string) file_get_contents(
            dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/hooks/index/scripts.post.tpl',
        );
        self::assertStringContainsString(
            '{script src="js/addons/sphinx_holidays/search-results.js"}',
            $hook,
            'the PDP needs the poll + terms-modal behavior for swapped-in offers',
        );
        self::assertStringContainsString("substr:0:9 == 'products.'", $hook);
    }

}
