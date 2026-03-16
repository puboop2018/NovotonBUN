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
$num_rooms = max(1, (int)($_REQUEST['rooms'] ?? $_REQUEST['num_rooms'] ?? 1));

if (empty($offer_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer selected.']));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

// Parse rooms_data if provided (from multi-room search results)
$incoming_rooms_data = [];
if (!empty($_REQUEST['rooms_data'])) {
    $incoming_rooms_data = is_string($_REQUEST['rooms_data'])
        ? json_decode(rawurldecode($_REQUEST['rooms_data']), true)
        : $_REQUEST['rooms_data'];
    if (!is_array($incoming_rooms_data)) {
        $incoming_rooms_data = [];
    }
}

try {
    $api = Container::getApi();
    $verifyResult = $api->verifyHotelOffer($offer_id);

    // API verify returns {data: {must_verify, pricing: {selling_price, currency}, rooms: [...], ...}}
    $verifyData = $verifyResult['data'] ?? $verifyResult;

    if (empty($verifyResult) || ($verifyData['must_verify'] ?? true)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available. Please search again.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.search?' . http_build_query([
            'hotel_id' => $hotel_id,
            'check_in' => $_REQUEST['check_in'] ?? '',
            'check_out' => $_REQUEST['check_out'] ?? '',
            'adults' => $_REQUEST['adults'] ?? 2,
            'children' => $_REQUEST['children'] ?? 0,
            'rooms' => $num_rooms,
        ])];
    }

    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();
    $verifiedPrice = (float)($verifyData['pricing']['selling_price'] ?? 0);
    $basePrice = $verifiedPrice;

    if ($commission > 0 && $verifiedPrice > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $verifiedPrice = $calculator->apply($verifiedPrice);
    }

    $hotelName = $verifyData['hotel_name'] ?? '';
    $firstRoom = $verifyData['rooms'][0] ?? [];
    $roomName = $firstRoom['name'] ?? $_REQUEST['room_name'] ?? '';
    $boardName = $verifyData['meal_type_name'] ?? $_REQUEST['board_name'] ?? '';
    $checkIn = $verifyData['check_in'] ?? $_REQUEST['check_in'] ?? '';
    $checkOut = $verifyData['check_out'] ?? $_REQUEST['check_out'] ?? '';
    $nights = 0;
    if (!empty($checkIn) && !empty($checkOut)) {
        $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    }
    $adults = (int)($firstRoom['adults'] ?? $_REQUEST['adults'] ?? 2);
    $children = count($firstRoom['children_ages'] ?? []) ?: (int)($_REQUEST['children'] ?? 0);
    $childrenAges = $firstRoom['children_ages'] ?? $_REQUEST['children_ages'] ?? '';

    // Parse children ages into array
    $childrenAgesArray = [];
    if (!empty($childrenAges)) {
        $childrenAgesArray = is_string($childrenAges)
            ? array_map('intval', array_filter(explode(',', $childrenAges), function($v) { return $v !== ''; }))
            : (array)$childrenAges;
    }

    if (empty($product_id) && !empty($hotel_id)) {
        $product_id = (int)db_get_field(
            "SELECT product_id FROM ?:products WHERE product_code = ?s",
            'SPH_' . $hotel_id
        );
    }

    // Build rooms_data array
    $rooms_data = [];
    if (!empty($incoming_rooms_data)) {
        // Use rooms_data from search (multi-room)
        $rooms_data = $incoming_rooms_data;
        $num_rooms = count($rooms_data);
    } elseif (!empty($verifyData['rooms']) && is_array($verifyData['rooms'])) {
        // API verify returned per-room breakdown
        foreach ($verifyData['rooms'] as $apiRoom) {
            $rooms_data[] = [
                'room_name' => $apiRoom['name'] ?? $roomName,
                'room_code' => (string)($apiRoom['code'] ?? ''),
                'board_name' => $boardName,
                'adults' => (int)($apiRoom['adults'] ?? $adults),
                'children' => count($apiRoom['children_ages'] ?? []),
                'childrenAges' => $apiRoom['children_ages'] ?? [],
                'price' => $verifiedPrice,
            ];
        }
        $num_rooms = count($rooms_data);
    }

    // Fallback: create default single-room entry
    if (empty($rooms_data)) {
        $rooms_data = [[
            'room_name' => $roomName,
            'board_name' => $boardName,
            'adults' => $adults,
            'children' => $children,
            'childrenAges' => $childrenAgesArray,
            'price' => $verifiedPrice,
        ]];
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
        'num_rooms' => $num_rooms,
        'rooms_data' => $rooms_data,
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
