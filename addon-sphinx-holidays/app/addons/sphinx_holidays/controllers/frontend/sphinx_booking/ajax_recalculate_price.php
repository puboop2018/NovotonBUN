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
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
    $input = ($raw !== false && $raw !== '' && $raw !== '0') ? json_decode($raw, true) : null;
    if (!is_array($input)) { $input = $_REQUEST; }

    $inputMap = TypeCoerce::toStringMap($input);
    $offer_id = TypeCoerce::toString($inputMap['offer_id'] ?? '');
    $original_price = TypeCoerce::toFloat($inputMap['original_price'] ?? 0);

    if (empty($offer_id)) {
        echo json_encode(['success' => false, 'message' => 'Missing offer_id']);
        exit;
    }

    $api = Container::getApi();
    $verifyResult = $api->verifyHotelOffer($offer_id);

    if (empty($verifyResult) || !TypeCoerce::toBool($verifyResult['available'] ?? false)) {
        echo json_encode(['success' => false, 'message' => 'Offer no longer available. Please search again.']);
        exit;
    }

    $newPrice = Container::getCartService()->applyCommission(TypeCoerce::toFloat($verifyResult['price'] ?? 0));

    $priceDiff = $newPrice - $original_price;
    $currency = ConfigProvider::getDefaultCurrency();
    $symbols = ConfigProvider::getCurrencySymbols();
    $symbol = TypeCoerce::toString($symbols[$currency] ?? $currency);
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

exit;
