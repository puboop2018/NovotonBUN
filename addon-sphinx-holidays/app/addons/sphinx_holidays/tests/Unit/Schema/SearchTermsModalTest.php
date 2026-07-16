<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "Condiții de Plată și Anulare" terms link + on-demand modal on the
 * sphinx hotel search results page. The offer card is rendered TWICE — once
 * server-side (Smarty foreach) and once client-side (renderCard during
 * polling) — so the link must exist on BOTH paths, and the modal must fetch
 * terms from the sphinx_booking.offer_terms endpoint (search results carry no
 * terms; they load on demand via verify). The modal renders a date-sorted
 * timeline from the endpoint's structured rules, falling back to the plain
 * lists for prose/legacy payloads.
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

    /**
     * RO word order matches the English source ("Payment and cancellation"):
     * every template fallback must read "Plată și Anulare", never the old
     * reversed "Anulare și Plată".
     */
    public function testRomanianLabelReadsPaymentFirst(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('Condiții de Plată și Anulare', $tpl);
        self::assertStringNotContainsString('Condiții de Anulare și Plată', $tpl);
    }

    /**
     * The modal decides PER TRACK on the API's binary shape: a track with
     * structured rules renders as a timeline, a track without renders the
     * API's parsed text lines — a prose payment track must never be dropped
     * because the cancellation track is structured (and vice versa).
     */
    public function testTimelineRendererAndListFallbackCoexist(): void
    {
        $tpl = self::searchTpl();

        // One track per schedule — the per-schedule renderer is the split.
        self::assertStringContainsString('function renderTrack(', $tpl);
        self::assertStringContainsString('schedule_total', $tpl);
        self::assertStringContainsString('travel-terms-timeline__node', $tpl);
        self::assertStringContainsString('travel-terms-timeline__tag--nonref', $tpl);
        // PER-TRACK gates: each schedule branches on ITS OWN rules, with the
        // section() list as that track's text fallback.
        self::assertStringContainsString('data.payment_rules && data.payment_rules.length', $tpl);
        self::assertStringContainsString('data.cancellation_rules && data.cancellation_rules.length', $tpl);
        self::assertStringContainsString('section(payHeading, data.payment_terms)', $tpl);
        self::assertStringContainsString('section(cancelHeading, data.cancellation_fees)', $tpl);
        // The old all-or-nothing wrapper must not come back.
        self::assertStringNotContainsString('function renderTimeline(', $tpl);
        // Fallback list renderer intact.
        self::assertStringContainsString('function section(', $tpl);
        self::assertStringContainsString('travel-terms-modal__section', $tpl);
        // Timeline labels wired through the Smarty labels map: terse row
        // labels + the date-gutter prepositions (until/from).
        foreach (['termsDueBy', 'termsPenalty', 'termsNonRefundable', 'termsUntil', 'termsFrom'] as $label) {
            self::assertStringContainsString($label . ':', $tpl);
        }
        // Dates render in the gutter beside the rail (tracker style).
        self::assertStringContainsString('travel-terms-timeline__date', $tpl);
        // Missing-lang-var guard: CS-Cart returns "_key" (truthy) for missing
        // vars, so labels go through lbl() — a raw key can never render.
        self::assertStringContainsString('function lbl(', $tpl);
        self::assertStringContainsString("charAt(0) === '_'", $tpl);
    }

    /**
     * The terms-modal language keys MUST live in lang_keys.php: the self-heal
     * seeder (fn_sphinx_holidays_seed_language_keys) reads lang_keys.php +
     * addon.xml and re-seeds INSTALLED stores when their hash changes — .po
     * files are only imported at install, so .po-only keys render as raw
     * "_sphinx_holidays.*" on existing stores (the exact bug this pins).
     */
    public function testTermsLangKeysSeededViaLangKeysPhp(): void
    {
        $path = dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertFileExists($path);
        /** @var array<string, array<string, string>> $keys */
        $keys = require $path;

        $expectedRo = [
            'sphinx_holidays.cancellation_and_payment_terms' => 'Condiții de Plată și Anulare',
            'sphinx_holidays.free_cancellation_until' => 'Anulare gratuită înainte de',
            'sphinx_holidays.terms_due_by' => 'De achitat',
            'sphinx_holidays.terms_penalty' => 'Penalizare',
            'sphinx_holidays.terms_non_refundable' => 'Nerambursabil',
            'sphinx_holidays.terms_until' => 'până la',
            'sphinx_holidays.terms_from' => 'de la',
        ];
        foreach ($expectedRo as $key => $ro) {
            self::assertArrayHasKey($key, $keys, $key);
            self::assertSame($ro, $keys[$key]['ro'] ?? null, $key);
            self::assertNotSame('', trim($keys[$key]['en'] ?? ''), $key . ' needs an EN value');
        }
    }

    /** The endpoint feeds the timeline: structured rules ride NEXT TO the legacy lines. */
    public function testOfferTermsEndpointEmitsStructuredRules(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/frontend/sphinx_booking/offer_terms.php',
        );

        self::assertStringContainsString('TermsFormatter::rules', $src);
        // Payment rows are per-installment increments (they must sum to 100%);
        // cancellation rows stay cumulative scenarios.
        self::assertStringContainsString('TermsFormatter::increments', $src);
        self::assertStringContainsString("'payment_rules'", $src);
        self::assertStringContainsString("'cancellation_rules'", $src);
        self::assertStringContainsString("'schedule_total'", $src);
        // Legacy keys stay — old cached pages read them.
        self::assertStringContainsString("'payment_terms'", $src);
        self::assertStringContainsString("'cancellation_fees'", $src);
    }
}
