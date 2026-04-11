<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Experience Booking Form Mode
 *
 * Gets a quote for a specific experience + departure date + occupancy,
 * then displays the participant entry form.
 *
 * Flow: user selects experience from rates → this controller gets quote → shows form
 * No customize step for experiences (unlike circuits).
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

$experience_id = (int)($_REQUEST['experience_id'] ?? 0);
$departure_date = trim($_REQUEST['departure_date'] ?? '');
$adults = max(1, (int)($_REQUEST['adults'] ?? 1));
$children = max(0, (int)($_REQUEST['children'] ?? 0));
$children_ages_str = trim($_REQUEST['children_ages'] ?? '');
$pickup_point_code = trim($_REQUEST['pickup_point_code'] ?? '');
$pickup_point_time = trim($_REQUEST['pickup_point_time'] ?? '');

if (empty($experience_id) || empty($departure_date)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_experience', ['[default]' => 'Invalid experience selection.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
}

$children_ages = [];
if (!empty($children_ages_str)) {
    $children_ages = array_map('intval', array_filter(explode(',', $children_ages_str), function($v) { return $v !== ''; }));
}

try {
    $api = Container::getApi();

    $quoteParams = [
        'experience_id' => $experience_id,
        'departure_date' => $departure_date,
        'occupancy' => ['adults' => $adults, 'children_ages' => $children_ages],
    ];
    if (!empty($pickup_point_code)) {
        $quoteParams['pickup_point_code'] = $pickup_point_code;
    }
    if (!empty($pickup_point_time)) {
        $quoteParams['pickup_point_time'] = $pickup_point_time;
    }

    $quoteResponse = $api->getExperienceQuote($quoteParams);
    $quotes = $quoteResponse['data'] ?? [];

    if (empty($quotes)) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.no_experience_quotes', ['[default]' => 'No quotes available for this experience. Please try a different date.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

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

    $view->assign('sphinx_experience_booking', [
        'offer_id' => $offer_id,
        'experience_id' => $experience_id,
        'title' => $quote['title'] ?? '',
        'departure_date' => $departure_date,
        'duration_days' => $quote['duration']['days'] ?? 0,
        'duration_minutes' => $quote['duration']['minutes'] ?? 0,
        'duration_description' => $quote['duration']['description'] ?? '',
        'summary' => $quote['summary'] ?? '',
        'image' => $quote['image'] ?? '',
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'pickup_point_code' => $pickup_point_code,
        'pickup_point_time' => $pickup_point_time,
        'total_price' => $sellingPrice,
        'base_price' => $basePrice,
        'currency' => $quote['pricing']['currency'] ?? ConfigProvider::getDefaultCurrency(),
        'payment_terms' => $quote['payment_terms'] ?? [],
        'cancellation_fees' => $quote['cancellation_fees'] ?? [],
        'verified' => true,
    ]);

    $view->assign('sphinx_provider', 'sphinx');

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Experience Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
}
