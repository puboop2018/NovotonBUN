<?php

declare(strict_types=1);
/**
 * Sphinx Booking Controller — AJAX Offer Terms
 *
 * Loads an offer's payment terms + cancellation fees ON DEMAND for the search
 * card's "Condiții de Anulare și Plată" modal. The search/results API does not
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

    echo json_encode([
        'status' => 'ok',
        'payment_terms' => $paymentTerms,
        'cancellation_fees' => $cancellationFees,
        'free_until' => $freeUntil,
        'is_free' => $isFree,
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx offer_terms error: ' . $e->getMessage()]);
    echo json_encode(['status' => 'error', 'message' => 'Terms temporarily unavailable.']);
}

exit;
