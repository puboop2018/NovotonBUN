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

    $verifyResult = Container::getApi()->verifyHotelOffer($offer_id);

    // Offer expired / no longer verifiable → tell the modal to show a
    // re-search message rather than an empty terms panel.
    if (!OfferAvailability::isVerifiedAvailable(
        $verifyResult,
        ConfigProvider::shouldRequireImmediateAvailability(),
    )) {
        echo json_encode(['status' => 'unavailable']);
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
