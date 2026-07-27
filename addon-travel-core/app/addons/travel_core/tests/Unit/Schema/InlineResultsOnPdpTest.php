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
