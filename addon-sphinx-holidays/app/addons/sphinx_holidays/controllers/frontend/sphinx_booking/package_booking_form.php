<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Booking Form Mode
 *
 * Verifies the selected package offer, retrieves payment terms and
 * cancellation fees, then displays the guest entry form with optional
 * services selection.
 *
 * Flow: search results → verify → display booking form
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

$view = Tygh::$app['view'];

$offer_id = trim($_REQUEST['offer_id'] ?? '');

if (empty($offer_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
}

// Carry forward search params for back navigation
$adults = max(1, (int)($_REQUEST['adults'] ?? 2));
$children = max(0, (int)($_REQUEST['children'] ?? 0));
$children_ages_str = trim($_REQUEST['children_ages'] ?? '');
$rooms = max(1, (int)($_REQUEST['rooms'] ?? 1));

try {
    $api = Container::getApi();

    // Verify the offer — this returns full pricing, payment terms, cancellation fees
    $verifyResult = $api->verifyPackageOffer($offer_id);

    if (empty($verifyResult) || empty($verifyResult['data'])) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    $offer = $verifyResult['data'];

    // Apply commission
    $sellingPrice = (float)($offer['pricing']['selling_price'] ?? 0);
    $basePrice = $sellingPrice;
    $sellingPrice = Container::getCartService()->applyCommission($sellingPrice);

    // Parse hotel info
    $hotel = $offer['hotel'] ?? [];
    $hotelRooms = $hotel['rooms'] ?? [];

    // Parse transport info
    $flight = $offer['flight'] ?? null;
    $bus = $offer['bus'] ?? [];
    $transfers = $offer['transfers'] ?? [];

    // Determine transport type
    $transportType = 'hotel-only';
    if (!empty($flight['outbound'])) {
        $transportType = 'flight';
    } elseif (!empty($bus)) {
        $transportType = 'bus';
    }

    $view->assign('sphinx_package_booking', [
        'offer_id' => $offer['offer_id'] ?? $offer_id,
        'destination_name' => $offer['destination_name'] ?? '',
        'hotel_id' => $hotel['id'] ?? '',
        'hotel_name' => $hotel['name'] ?? '',
        'check_in' => $hotel['check_in'] ?? '',
        'check_out' => $hotel['check_out'] ?? '',
        'rooms' => $hotelRooms,
        'meal_type' => $hotel['meal_type_name'] ?? '',
        'transport_type' => $transportType,
        'flight' => $flight,
        'bus' => $bus,
        'transfers' => $transfers,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'num_rooms' => $rooms,
        'total_price' => $sellingPrice,
        'base_price' => $basePrice,
        'currency' => $offer['pricing']['currency'] ?? ConfigProvider::getDefaultCurrency(),
        'additional_services' => $offer['additional_services'] ?? [],
        'payment_terms' => $offer['payment_terms'] ?? [],
        'cancellation_fees' => $offer['cancellation_fees'] ?? [],
        'confirmation' => $offer['confirmation'] ?? '',
        'labels' => $offer['labels'] ?? [],
        'included_services' => $offer['included_services'] ?? [],
        'not_included_services' => $offer['not_included_services'] ?? [],
        'verified' => true,
    ]);

    $view->assign('sphinx_provider', 'sphinx');

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Package Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
}
