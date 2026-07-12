<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the sphinx "contact me when available" flow as INTERNAL-ONLY:
 * the no-results panel carries the request form (with honeypot), and the
 * request_alternatives mode stores into the SHARED travel registry without
 * ever calling the Sphinx provider API — that is the deliberate difference
 * from novoton's flow.
 */
final class AlternativeRequestModeTest extends TestCase
{
    public function testModeStoresInternallyWithoutProviderApi(): void
    {
        $path = dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/request_alternatives.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('AlternativeRequestRepository', $src);
        self::assertStringContainsString('AlternativeRequestNotifier', $src);
        self::assertStringContainsString("'pending_manual'", $src, 'internal requests start in the manual-handling status');
        self::assertStringContainsString("'website'", $src, 'honeypot check present');

        // The whole point: NO provider API involvement.
        self::assertStringNotContainsString('Container::getApi', $src);
        self::assertStringNotContainsString('SphinxApi', $src);
    }

    public function testNoResultsPanelCarriesTheRequestForm(): void
    {
        $tpl = dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl';
        $src = (string) file_get_contents($tpl);

        self::assertStringContainsString('travel-request-box', $src);
        self::assertStringContainsString('sphinx_booking.request_alternatives', $src);
        self::assertStringContainsString('name="website"', $src, 'honeypot input present');
        self::assertStringContainsString('name="contact_email"', $src);

        // The form lives inside the no-results panel so it shows exactly when
        // there is no availability (server-rendered or poll-detected).
        $noResultsPos = strpos($src, 'sphinx-no-results');
        $formPos = strpos($src, 'travel-request-box');
        self::assertIsInt($noResultsPos);
        self::assertIsInt($formPos);
        self::assertGreaterThan($noResultsPos, $formPos);
    }
}
