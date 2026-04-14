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
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

$view = Tygh::$app['view'];

$offer_id = trim(ValidationHelpers::toString($_REQUEST['offer_id'] ?? ''));

if (empty($offer_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
}

// Carry forward search params for back navigation
$adults = max(1, ValidationHelpers::toInt($_REQUEST['adults'] ?? 2));
$children = max(0, ValidationHelpers::toInt($_REQUEST['children'] ?? 0));
$children_ages_str = trim(ValidationHelpers::toString($_REQUEST['children_ages'] ?? ''));
$rooms = max(1, ValidationHelpers::toInt($_REQUEST['rooms'] ?? 1));

try {
    $api = Container::getApi();

    // Verify the offer — this returns full pricing, payment terms, cancellation fees
    $verifyResult = $api->verifyPackageOffer($offer_id);

    if (empty($verifyResult) || !is_array($verifyResult) || empty($verifyResult['data']) || !is_array($verifyResult['data'])) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    /** @var array<string, mixed> $offer */
    $offer = $verifyResult['data'];

    // Apply commission
    /** @var array<string, mixed> $pricing */
    $pricing = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : [];
    $sellingPrice = ValidationHelpers::toFloat($pricing['selling_price'] ?? 0);
    $basePrice = $sellingPrice;
    $sellingPrice = Container::getCartService()->applyCommission($sellingPrice);

    // Parse hotel info
    /** @var array<string, mixed> $hotel */
    $hotel = is_array($offer['hotel'] ?? null) ? $offer['hotel'] : [];
    $hotelRooms = is_array($hotel['rooms'] ?? null) ? $hotel['rooms'] : [];

    // Parse transport info
    /** @var array<string, mixed>|null $flight */
    $flight = is_array($offer['flight'] ?? null) ? $offer['flight'] : null;
    $bus = is_array($offer['bus'] ?? null) ? $offer['bus'] : [];
    $transfers = is_array($offer['transfers'] ?? null) ? $offer['transfers'] : [];

    // Determine transport type
    $transportType = 'hotel-only';
    if (is_array($flight) && !empty($flight['outbound'])) {
        $transportType = 'flight';
    } elseif (!empty($bus)) {
        $transportType = 'bus';
    }

    $view->assign('sphinx_package_booking', [
        'offer_id' => ValidationHelpers::toString($offer['offer_id'] ?? $offer_id),
        'destination_name' => ValidationHelpers::toString($offer['destination_name'] ?? ''),
        'hotel_id' => ValidationHelpers::toString($hotel['id'] ?? ''),
        'hotel_name' => ValidationHelpers::toString($hotel['name'] ?? ''),
        'check_in' => ValidationHelpers::toString($hotel['check_in'] ?? ''),
        'check_out' => ValidationHelpers::toString($hotel['check_out'] ?? ''),
        'rooms' => $hotelRooms,
        'meal_type' => ValidationHelpers::toString($hotel['meal_type_name'] ?? ''),
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
        'currency' => ValidationHelpers::toString($pricing['currency'] ?? ConfigProvider::getDefaultCurrency()),
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
