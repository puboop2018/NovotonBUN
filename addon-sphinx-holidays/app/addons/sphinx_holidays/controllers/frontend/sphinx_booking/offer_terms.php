<?php

declare(strict_types=1);
/**
 * Sphinx Booking Controller — AJAX Offer Terms
 *
 * Loads an offer's payment terms + cancellation fees ON DEMAND for the search
 * card's "Condiții de Plată și Anulare" modal. The search/results API does not
 * carry terms (must_verify=true); they exist only on the verify response, so
 * this re-verifies the offer and returns friendly terms lines + a free-until
 * date. Mirrors ajax_recalculate_price.php's verify → unwrap chain.
 *
 * @package SphinxHolidays
 * @since   1.4.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\TermsFormatter;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

header('Content-Type: application/json; charset=utf-8');

try {
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    if ($offer_id === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing offer_id']);
        exit;
    }

    $api = Container::getApi();
    $verifyResult = $api->verifyHotelOffer($offer_id);

    // Offer expired / no longer verifiable → tell the modal to show a
    // re-search message rather than an empty terms panel. A 5xx / transport
    // failure is the verify SERVICE being down, not the offer being gone —
    // report it as an outage so the modal doesn't send the guest on a
    // pointless re-search.
    if (!OfferAvailability::isVerifiedAvailable(
        $verifyResult,
        ConfigProvider::shouldRequireImmediateAvailability(),
    )) {
        $http = $api->getHttpClient();
        $verifyHttpCode = $http->getLastHttpCode();
        $outage = $verifyHttpCode === 0 || $verifyHttpCode >= 500;

        // This branch used to be SILENT. The booking button's identical
        // rejection logged the HTTP code, but the terms modal logged nothing —
        // so a guest reporting "conditions can't be shown" left no trace at
        // all, and the two symptoms of one outage looked unrelated. Both now
        // carry the RAW upstream body: a 5xx decodes to null, so logging the
        // decoded result alone recorded the word "null" and threw away the
        // provider's actual error — the one thing worth forwarding to them.
        fn_log_event('general', 'runtime', [
            'message' => $outage
                ? 'Sphinx offer_terms: verify OUTAGE (HTTP ' . $verifyHttpCode . ') — terms unavailable, offer not rejected on merit'
                : 'Sphinx offer_terms: offer rejected by verify (HTTP ' . $verifyHttpCode . ')',
            'offer_id' => $offer_id,
            'transport_error' => $http->getLastError(),
            'raw_response' => substr((string) $http->getLastResponseRaw(), 0, 1000),
        ]);

        // The detailed schedule genuinely only exists on the verify response
        // (search sends payment_terms.is_loaded=false on every offer — 0 of
        // 17,336 measured). But search DOES carry cancellation_fees.is_free,
        // and during a verify outage that one fact is the difference between
        // "we can tell you nothing" and "your cancellation is free". Serve it
        // from the server-side snapshot rather than showing a bare error.
        $snapshot = \Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore::get($offer_id);
        $snapshotFees = TypeCoerce::toStringMap(($snapshot ?? [])['cancellation_fees'] ?? []);
        if ($outage && $snapshotFees !== [] && array_key_exists('is_free', $snapshotFees)) {
            echo json_encode([
                'status' => 'partial',
                'is_free' => TypeCoerce::toBool($snapshotFees['is_free']),
                'payment_terms' => [],
                'cancellation_fees' => [],
                'payment_rules' => [],
                'cancellation_rules' => [],
                'free_until' => null,
                'notice' => TypeCoerce::toString(__('sphinx_holidays.terms_partial', [
                    '[default]' => 'The detailed payment and cancellation schedule cannot be loaded right now. The cancellation status below comes from the search result and is accurate.',
                ])),
            ]);
            exit;
        }

        echo json_encode(['status' => $outage ? 'outage' : 'unavailable']);
        exit;
    }

    $offer = TypeCoerce::toStringMap(OfferAvailability::unwrapOffer($verifyResult));

    // Value currency: the offer's own pricing currency, else the store default.
    $pricing = TypeCoerce::toStringMap($offer['pricing'] ?? []);
    $currency = TypeCoerce::toString($pricing['currency'] ?? $offer['currency'] ?? '');
    if ($currency === '') {
        $currency = ConfigProvider::getDefaultCurrency();
    }

    // Localized connective words for the rule dates (RO storefront).
    $untilLabel = TypeCoerce::toString(__('sphinx_holidays.terms_until', ['[default]' => 'până la']));
    $sinceLabel = TypeCoerce::toString(__('sphinx_holidays.terms_from', ['[default]' => 'de la']));

    $paymentTerms = TermsFormatter::lines($offer['payment_terms'] ?? null, $currency, $untilLabel, $sinceLabel);
    $cancellationFees = TermsFormatter::lines($offer['cancellation_fees'] ?? null, $currency, $untilLabel, $sinceLabel);
    $freeUntil = TermsFormatter::freeCancellationUntil($offer['cancellation_fees'] ?? null);

    $cancellation = TypeCoerce::toStringMap($offer['cancellation_fees'] ?? []);
    $isFree = TypeCoerce::toBool($cancellation['is_free'] ?? false);

    // Structured schedule rows for the timeline UI, kept ALONGSIDE the
    // legacy string lines (older cached pages keep working; prose-text
    // offers yield empty rule lists and the modal falls back to the lists).
    // The API's rule values are CUMULATIVE; payment rows are converted to
    // PER-INSTALLMENT increments so displayed amounts/percentages sum to
    // exactly 100% (763+3050=3813 → 20%+80%). Cancellation rows STAY
    // cumulative — each is an alternative "cancel from this date" scenario,
    // not an installment. Percent is SELF-ANCHORED to the schedule's final
    // cumulative value: the API sends no percentages, and these are
    // supplier-schedule amounts that do not sum to the commissioned
    // storefront price.
    $cumulativePayment = TermsFormatter::rules($offer['payment_terms'] ?? null);
    $cancellationRules = TermsFormatter::rules($offer['cancellation_fees'] ?? null);

    $scheduleTotal = 0.0;
    foreach (array_merge($cumulativePayment, $cancellationRules) as $row) {
        $scheduleTotal = max($scheduleTotal, $row['amount']);
    }

    $paymentRules = TermsFormatter::increments($cumulativePayment);
    foreach ($paymentRules as $i => $row) {
        $paymentRules[$i]['percent'] = $scheduleTotal > 0.0 ? round($row['amount'] / $scheduleTotal * 100, 1) : null;
    }
    foreach ($cancellationRules as $i => $row) {
        $cancellationRules[$i]['percent'] = $scheduleTotal > 0.0 ? round($row['amount'] / $scheduleTotal * 100, 1) : null;
    }

    echo json_encode([
        'status' => 'ok',
        'payment_terms' => $paymentTerms,
        'cancellation_fees' => $cancellationFees,
        'free_until' => $freeUntil,
        'is_free' => $isFree,
        'payment_rules' => $paymentRules,
        'cancellation_rules' => $cancellationRules,
        'schedule_total' => $scheduleTotal > 0.0 ? $scheduleTotal : null,
        'currency' => $currency,
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx offer_terms error: ' . $e->getMessage()]);
    echo json_encode(['status' => 'error', 'message' => 'Terms temporarily unavailable.']);
}

exit;
