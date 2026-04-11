<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Booking Form Mode
 *
 * Verifies the selected offer via SphinxApi::verifyHotelOffer(),
 * then displays the guest entry form with verified pricing.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

$view = Tygh::$app['view'];

$offer_id = trim($_REQUEST['offer_id'] ?? '');
$hotel_id = trim($_REQUEST['hotel_id'] ?? '');
$product_id = (int)($_REQUEST['product_id'] ?? 0);

if (empty($offer_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer selected.']));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

try {
    $api = Container::getApi();
    $verifyResult = $api->verifyHotelOffer($offer_id);

    if (empty($verifyResult) || !($verifyResult['available'] ?? false)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available. Please search again.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.search?' . http_build_query([
            'hotel_id' => $hotel_id,
            'check_in' => $_REQUEST['check_in'] ?? '',
            'check_out' => $_REQUEST['check_out'] ?? '',
            'adults' => $_REQUEST['adults'] ?? 2,
            'children' => $_REQUEST['children'] ?? 0,
        ])];
    }

    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices() ? 'Y' : 'N';
    $verifiedPrice = (float)($verifyResult['price'] ?? 0);
    $basePrice = $verifiedPrice;

    if ($commission > 0 && $verifiedPrice > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $verifiedPrice = $calculator->apply($verifiedPrice);
    }

    $hotelName = $verifyResult['hotel_name'] ?? '';
    $roomName = $verifyResult['room_name'] ?? $verifyResult['room_type'] ?? '';
    $boardName = $verifyResult['board_name'] ?? $verifyResult['board_type'] ?? '';
    $checkIn = $verifyResult['check_in'] ?? $_REQUEST['check_in'] ?? '';
    $checkOut = $verifyResult['check_out'] ?? $_REQUEST['check_out'] ?? '';
    $nights = 0;
    if (!empty($checkIn) && !empty($checkOut)) {
        $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    }
    $adults = (int)($verifyResult['adults'] ?? $_REQUEST['adults'] ?? 2);
    $children = (int)($verifyResult['children'] ?? $_REQUEST['children'] ?? 0);
    $childrenAges = $verifyResult['children_ages'] ?? $_REQUEST['children_ages'] ?? '';

    if (empty($product_id) && !empty($hotel_id)) {
        $product_id = (int)db_get_field(
            "SELECT product_id FROM ?:products WHERE product_code = ?s",
            \Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getProductCodePrefix() . $hotel_id
        );
    }

    $view->assign('sphinx_booking_data', [
        'offer_id' => $offer_id,
        'hotel_id' => $hotel_id,
        'product_id' => $product_id,
        'hotel_name' => $hotelName,
        'room_name' => $roomName,
        'board_name' => $boardName,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $childrenAges,
        'total_price' => $verifiedPrice,
        'base_price' => $basePrice,
        'currency' => ConfigProvider::getDefaultCurrency(),
        'verified' => true,
    ]);

    $view->assign('sphinx_provider', 'sphinx');

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}
