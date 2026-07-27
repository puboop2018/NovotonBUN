<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the inline-results contract: searching from a hotel product page
 * swaps the provider's results INTO the page instead of navigating to the
 * standalone results page.
 *
 * Load-bearing pieces:
 *  - the PDP mount ships inside the SAME shell the results pages use
 *    (.travel-search-results-page > .travel-search-form-wrapper), because
 *    performAjaxSearch replaces everything after the form wrapper inside
 *    that shell;
 *  - booking_config tells the engine to search inline (inlineResults),
 *    while mode stays 'product' so the PDP keeps its Availability header;
 *  - the engine announces every swap via the travel:results-swapped event
 *    (sphinx re-arms its result polling on it) and rewrites history onto
 *    the product URL so reload restores the inline view.
 */
final class InlineResultsOnPdpTest extends TestCase
{
    private const MOUNT_REL = 'templates/addons/travel_core/components/booking_form_mount.tpl';

    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function repoAddonRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    public function testMountShipsInsideTheSwapShellInBothThemes(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $tpl = (string) file_get_contents(
                self::repoAddonRoot() . '/design/themes/' . $theme . '/' . self::MOUNT_REL,
            );

            $shell = strpos($tpl, '<div class="travel-search-results-page travel-pdp-results">');
            $form = strpos($tpl, '<div class="travel-search-form-wrapper">');
            $mount = strpos($tpl, 'id="travel-booking-root"');
            self::assertIsInt($shell, $theme . ': swap shell present');
            self::assertIsInt($form, $theme . ': form wrapper present');
            self::assertIsInt($mount, $theme . ': mount present');
            self::assertLessThan($form, $shell, $theme . ': shell wraps the form wrapper');
            self::assertLessThan($mount, $form, $theme . ': form wrapper wraps the mount');
        }
    }

    public function testBookingConfigRequestsInlineResultsKeepingProductMode(): void
    {
        $controller = (string) file_get_contents(
            self::addonRoot() . '/controllers/frontend/travel_booking.php',
        );
        self::assertStringContainsString("'inlineResults' => true", $controller);
        self::assertStringContainsString("'mode' => 'product'", $controller);
    }

    public function testEngineSourceCarriesTheInlineContract(): void
    {
        $src = self::addonRoot() . '/../../../react-src/src';

        $index = (string) file_get_contents($src . '/index.jsx');
        self::assertStringContainsString('inlineResults:       !!serverConfig.inlineResults', $index);

        $engine = (string) file_get_contents($src . '/BookingEngine.jsx');
        self::assertStringContainsString("if (mode === 'search' || inlineResults) {", $engine);
        self::assertStringContainsString("new CustomEvent('travel:results-swapped')", $engine);
        // History lands on the product URL (params merged), not the results
        // dispatch, so reload + auto-restore keep the visitor on the PDP.
        self::assertStringContainsString('let historyUrl = url;', $engine);
        self::assertStringContainsString('didAutoSearchRef', $engine);
        // The hotel-header refresh died with the results-page header strip.
        self::assertStringNotContainsString('travel-hotel-header', $engine);
    }

    public function testExpiredOfferLoopReturnsToTheProductPage(): void
    {
        $sphinxAddon = self::repoAddonRoot() . '/../addon-sphinx-holidays';

        // The sphinx terms modal + offer cards only enter a product page WITH
        // the first swap, AFTER search-results.js has run — so the modal and
        // the labels must be looked up lazily, never captured at load time.
        $js = (string) file_get_contents(
            $sphinxAddon . '/js/addons/sphinx_holidays/search-results.js',
        );
        self::assertStringContainsString('function modalEl()', $js);
        self::assertStringContainsString('function bodyEl()', $js);
        // The regression was a LOAD-TIME capture (var modal = …) whose early
        // return skipped binding the delegated handlers entirely.
        self::assertStringNotContainsString(
            "var modal = document.getElementById('sphinx-terms-modal')",
            $js,
        );
        // Poll-rendered cards fall back to the searched product for their
        // Book-now URL — result rows may omit product_id.
        self::assertStringContainsString('result.product_id || searchParams.product_id', $js);

        $tpl = (string) file_get_contents(
            $sphinxAddon . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl',
        );
        self::assertStringContainsString('product_id: "{$sphinx_search_params.product_id', $tpl);

        // An expired offer sends the customer BACK to the product page (live
        // inline re-search via refresh=1); the standalone results page is the
        // fallback only when no product is known.
        $bookingForm = (string) file_get_contents(
            $sphinxAddon . '/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/booking_form.php',
        );
        self::assertStringContainsString("'products.view?' . http_build_query", $bookingForm);
        self::assertStringContainsString("'refresh' => 1", $bookingForm);

        // The engine forwards refresh=1 once on auto-restore and never writes
        // it into history (or every reload would force a live re-search).
        $engine = (string) file_get_contents(
            self::addonRoot() . '/../../../react-src/src/BookingEngine.jsx',
        );
        self::assertStringContainsString("cur.searchParams.delete('refresh')", $engine);
        self::assertStringContainsString('wantsRefresh', $engine);
    }

    public function testCommittedBundleWasRebuiltWithTheContract(): void
    {
        // CI never rebuilds the bundles — a stale committed artifact would
        // silently ship the old navigate-away behavior.
        $bundle = (string) file_get_contents(
            self::repoAddonRoot() . '/js/addons/travel_core/react19-bundle.js',
        );
        self::assertStringContainsString('travel:results-swapped', $bundle);
        self::assertStringContainsString('inlineResults', $bundle);

        // Browsers must fetch the new bundle: the cache-buster moved past 6.
        $init = (string) file_get_contents(self::addonRoot() . '/init.php');
        self::assertMatchesRegularExpression(
            "/define\\('TRAVEL_CACHE_VER', '([7-9]|\\d{2,})'\\)/",
            $init,
        );
    }
}
