<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Booking Form Mode
 *
 * Gets a quote for a specific circuit + departure date + occupancy,
 * then displays the guest entry form with optional services.
 *
 * Flow: user selects circuit from rates → this controller gets quote → shows form
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

$view = Tygh::$app['view'];

$circuit_id = (int)($_REQUEST['circuit_id'] ?? 0);
$departure_date = trim($_REQUEST['departure_date'] ?? '');
$departure_id = (int)($_REQUEST['departure_id'] ?? 0);
$adults = max(1, (int)($_REQUEST['adults'] ?? 2));
$children = max(0, (int)($_REQUEST['children'] ?? 0));
$children_ages_str = trim($_REQUEST['children_ages'] ?? '');

if (empty($circuit_id) || empty($departure_date)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_circuit', ['[default]' => 'Invalid circuit selection.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
}

$children_ages = [];
if (!empty($children_ages_str)) {
    $children_ages = array_map('intval', array_filter(explode(',', $children_ages_str), function($v) { return $v !== ''; }));
}

try {
    $api = Container::getApi();

    $quoteParams = [
        'circuit_id' => $circuit_id,
        'departure_date' => $departure_date,
        'occupancy' => [['adults' => $adults, 'children_ages' => $children_ages]],
    ];
    if ($departure_id > 0) {
        $quoteParams['departure_id'] = $departure_id;
    }

    $quoteResponse = $api->getCircuitQuote($quoteParams);
    $quotes = $quoteResponse['data'] ?? [];

    if (empty($quotes)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.no_circuit_quotes', ['[default]' => 'No quotes available for this circuit. Please try different dates.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Take the first quote (or let user select if multiple)
    $quote = $quotes[0];
    $offer_id = $quote['offer_id'] ?? '';

    // Apply commission
    $commission = ConfigProvider::getCommission();
    $roundPrices = ConfigProvider::shouldRoundPrices() ? 'Y' : 'N';
    $sellingPrice = (float)($quote['pricing']['selling_price'] ?? 0);
    $basePrice = $sellingPrice;

    if ($commission > 0 && $sellingPrice > 0) {
        $calculator = new CommissionCalculator($commission, $roundPrices);
        $sellingPrice = $calculator->apply($sellingPrice);
    }

    // Parse rooms from quote
    $rooms = $quote['rooms'] ?? [];

    // Parse additional services (optional/mandatory)
    $additionalServices = $quote['additional_services'] ?? [];

    $view->assign('sphinx_circuit_booking', [
        'offer_id' => $offer_id,
        'circuit_id' => $circuit_id,
        'title' => $quote['title'] ?? '',
        'departure_date' => $departure_date,
        'departure_id' => $departure_id,
        'departure_name' => $quote['departure']['name'] ?? '',
        'transport_type' => $quote['transport_type'] ?? '',
        'duration_days' => $quote['duration']['days'] ?? 0,
        'duration_nights' => $quote['duration']['nights'] ?? 0,
        'summary' => $quote['summary'] ?? '',
        'image' => $quote['image'] ?? '',
        'rooms' => $rooms,
        'meal_type' => $quote['meal_type_name'] ?? '',
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'total_price' => $sellingPrice,
        'base_price' => $basePrice,
        'currency' => $quote['pricing']['currency'] ?? ConfigProvider::getDefaultCurrency(),
        'additional_services' => $additionalServices,
        'payment_terms' => $quote['payment_terms'] ?? [],
        'cancellation_fees' => $quote['cancellation_fees'] ?? [],
        'flight' => $quote['flight'] ?? null,
        'verified' => true,
    ]);

    $view->assign('sphinx_provider', 'sphinx');

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Circuit Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
}
