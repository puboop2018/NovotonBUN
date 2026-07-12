<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "Condiții de Anulare și Plată" terms link + on-demand modal on the
 * sphinx hotel search results page. The offer card is rendered TWICE — once
 * server-side (Smarty foreach) and once client-side (renderCard during
 * polling) — so the link must exist on BOTH paths, and the modal must fetch
 * terms from the sphinx_booking.offer_terms endpoint (search results carry no
 * terms; they load on demand via verify).
 */
final class SearchTermsModalTest extends TestCase
{
    private static function searchTpl(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTermsLinkPresentOnBothCardRenderPaths(): void
    {
        $tpl = self::searchTpl();

        // Server card (Smarty) + JS renderCard() card both emit the trigger.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($tpl, 'sphinx-terms-link'),
            'the terms link must be on both the server-rendered and poll-rendered cards',
        );
        // Server card uses the lang key; the JS card uses the labels config.
        self::assertStringContainsString('sphinx_holidays.cancellation_and_payment_terms', $tpl);
        self::assertStringContainsString('labels.cancellationAndPaymentTerms', $tpl);
    }

    public function testModalShellAndOnDemandFetchPresent(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('id="sphinx-terms-modal"', $tpl);
        self::assertStringContainsString('id="sphinx-terms-modal-body"', $tpl);
        self::assertStringContainsString('role="dialog"', $tpl);
        // Terms are loaded on demand from the verify-backed endpoint.
        self::assertStringContainsString('sphinx_booking.offer_terms', $tpl);
        // The free-cancellation line is rendered in the modal.
        self::assertStringContainsString('travel-terms-modal__free', $tpl);
    }

    public function testOfferTermsEndpointExists(): void
    {
        $path = dirname(__DIR__, 3)
            . '/controllers/frontend/sphinx_booking/offer_terms.php';
        self::assertFileExists($path, 'the on-demand terms endpoint must exist');

        $src = (string) file_get_contents($path);
        self::assertStringContainsString('verifyHotelOffer', $src);
        self::assertStringContainsString('TermsFormatter::lines', $src);
        self::assertStringContainsString('freeCancellationUntil', $src);
    }
}
