<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Cache Deals AJAX Mode
 *
 * Returns cached hotel/package deals as JSON for frontend widgets.
 * Used by the best_deals block template to load deals via AJAX.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\CacheEndpointService;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

header('Content-Type: application/json; charset=utf-8');

try {
    $type = RequestCoerce::string($_REQUEST, 'type', 'hotels');
    $limit = max(1, min(50, RequestCoerce::int($_REQUEST, 'limit', 6)));
    $destination_id = RequestCoerce::int($_REQUEST, 'destination_id');

    $filters = ['limit' => $limit];
    if ($destination_id > 0) {
        $filters['destination_id'] = $destination_id;
    }

    $api = Container::getApi();
    $commission = ConfigProvider::getCommission();
    $service = new CacheEndpointService($api, $commission);

    $deals = ($type === 'packages')
        ? $service->getPackageDeals($filters)
        : $service->getHotelDeals($filters);

    // Limit results
    $deals = array_slice($deals, 0, $limit);

    echo json_encode([
        'success' => true,
        'deals' => $deals,
        'count' => count($deals),
        'currency' => ConfigProvider::getDefaultCurrency(),
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx cache_deals error: ' . $e->getMessage()]);
    echo json_encode(['success' => false, 'message' => 'Unable to load deals.']);
}

exit;
