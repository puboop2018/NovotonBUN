<?php

declare(strict_types=1);
/**
 * eurosite_booking.booking_form — the guest booking form (novoton pattern,
 * eurosite data): everything about the offer comes from the server-side
 * snapshot written at search time (OfferContextStore) — the URL carries only
 * the offer_key, so prices and occupancy cannot be tampered with.
 *
 * Eurosite pax specifics (AddBookingRequest): per guest name, PaxType
 * adult/child, TGender B/F (C for children), DOB, ChildAge — hence the
 * eurosite-local guest cards (same POST contract as the shared
 * booking_guest_room_body.tpl, plus a [gender] select the shared component
 * doesn't render).
 */

use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;

if ($snapshot === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.search'];
}

$adults = max(1, TypeCoerce::toInt($snapshot['adults'] ?? 2));
$childrenAges = TypeCoerce::toIntList($snapshot['children_ages'] ?? []);

// Cancellation fees, server-rendered into the conditions section (best
// effort — the modal on the results page uses the same data via AJAX).
$cancellationFees = [];
try {
    $hotelRow = Container::hotels()->findByProductCode(TypeCoerce::toString($snapshot['product_code']));
    $tourop = $hotelRow !== null ? TypeCoerce::toString($hotelRow['tourop_code'] ?? '') : '';
    $rooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
    $fees = Container::getApi()->getItemFees([
        'currency'     => TypeCoerce::toString($snapshot['currency']),
        'country_code' => TypeCoerce::toString($snapshot['country_code']),
        'city_code'    => TypeCoerce::toString($snapshot['city_code']),
        'product_code' => TypeCoerce::toString($snapshot['product_code']),
        'variant_id'   => TypeCoerce::toString($snapshot['variant_id']),
        'check_in'     => TypeCoerce::toString($snapshot['check_in']),
        'check_out'    => TypeCoerce::toString($snapshot['check_out']),
        'tourop_code'  => $tourop,
        'rooms'        => [[
            'code'     => $rooms !== [] ? TypeCoerce::toString($rooms[0]['code'] ?? '') : '',
            'adults'   => $adults,
            'children' => $childrenAges,
        ]],
    ]);
    foreach ($fees as $fee) {
        $cancellationFees[] = [
            'from'  => $fee['from_date'],
            'to'    => $fee['to_date'],
            'value' => $fee['is_percent']
                ? rtrim(rtrim(number_format($fee['value'], 2), '0'), '.') . '%'
                : number_format($fee['value'], 2) . ' ' . TypeCoerce::toString($snapshot['currency']),
        ];
    }
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Eurosite booking_form fees unavailable: ' . $e->getMessage()]);
}

$paymentLines = array_values(array_filter(array_map(
    'trim',
    explode("\n", ConfigProvider::getPaymentTermsText()),
), static fn (string $line): bool => $line !== ''));

$view->assign('eurosite_offer_key', $offerKey);
$view->assign('eurosite_offer', [
    'product_name' => TypeCoerce::toString($snapshot['product_name']),
    'city_name'    => TypeCoerce::toString($snapshot['city_name']),
    'check_in'     => TypeCoerce::toString($snapshot['check_in']),
    'check_out'    => TypeCoerce::toString($snapshot['check_out']),
    'price'        => number_format(TypeCoerce::toFloat($snapshot['price']), 2),
    'currency'     => TypeCoerce::toString($snapshot['currency']),
    'rooms'        => is_array($snapshot['rooms'] ?? null) ? $snapshot['rooms'] : [],
    'meals'        => is_array($snapshot['meals'] ?? null) ? $snapshot['meals'] : [],
    'availability' => TypeCoerce::toString($snapshot['availability'] ?? ''),
]);
$view->assign('eurosite_adults', $adults);
$view->assign('eurosite_children_ages', $childrenAges);
$view->assign('eurosite_cancellation_fees', $cancellationFees);
$view->assign('eurosite_payment_lines', $paymentLines);
