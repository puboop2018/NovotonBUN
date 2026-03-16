<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — AJAX Price Recalculation
 *
 * Re-verifies the offer price via SphinxApi::verifyHotelOffer()
 * and returns JSON with the current price.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

$_sphinx_prev_handler = set_error_handler(function($errno, $errstr) { return true; });

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) { $input = $_REQUEST; }

    $offer_id = trim($input['offer_id'] ?? '');
    $original_price = (float)($input['original_price'] ?? 0);

    if (empty($offer_id)) {
        echo json_encode(['success' => false, 'message' => 'Missing offer_id']);
        restore_error_handler();
        exit;
    }

    $api = Container::getApi();
    $verifyResult = $api->verifyHotelOffer($offer_id);

    if (empty($verifyResult) || !($verifyResult['available'] ?? false)) {
        echo json_encode(['success' => false, 'message' => 'Offer no longer available. Please search again.']);
        restore_error_handler();
        exit;
    }

    $newPrice = (float)($verifyResult['price'] ?? 0);
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices();

    if ($commission > 0 && $newPrice > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $newPrice = $calculator->apply($newPrice);
    }

    $priceDiff = $newPrice - $original_price;
    $currency = ConfigProvider::getDefaultCurrency();
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'RON' => 'lei', 'BGN' => 'лв'];
    $symbol = $symbols[$currency] ?? $currency;
    $formattedPrice = number_format($newPrice, 2, ',', '.') . ' ' . $symbol;

    echo json_encode([
        'success' => true,
        'new_price' => $newPrice,
        'formatted_price' => $formattedPrice,
        'price_difference' => round($priceDiff, 2),
        'room_changed' => false,
        'available' => true,
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx ajax_recalculate_price error: ' . $e->getMessage()]);
    echo json_encode(['success' => false, 'message' => 'Price verification temporarily unavailable.']);
}

restore_error_handler();
exit;
