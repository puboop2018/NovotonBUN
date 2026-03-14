<?php
declare(strict_types=1);
/**
 * Sphinx Holidays - Backend Admin Controller
 *
 * Modes:
 *   - manage (default): Dashboard with destination stats + sync controls
 *   - destinations: List/filter destinations with pagination
 *   - sync_destinations (POST): Trigger destination sync from admin panel
 *
 * @package SphinxHolidays
 * @since 1.0.0
 */

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DestinationSyncService;
use Tygh\Addons\SphinxHolidays\Repository\DestinationRepository;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

$mode = $_REQUEST['mode'] ?? 'manage';

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'sync_destinations') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        $api = Container::getApi();
        $repository = new DestinationRepository();
        $service = new DestinationSyncService($api, $repository);

        $result = $service->sync();

        if ($result['success']) {
            fn_set_notification('N', __('notice'), __('sphinx_holidays.sync_completed') . ': ' . $result['synced'] . '/' . $result['total']);
        } else {
            fn_set_notification('E', __('error'), __('sphinx_holidays.sync_failed') . ': ' . ($result['error'] ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }
}

// ─── GET handlers ───

if ($mode === 'manage') {
    $repository = new DestinationRepository();

    // Destination stats
    $countsByType = $repository->getCountsByType();
    $totalDestinations = $repository->getTotal();
    $lastSynced = $repository->getLastSyncedAt();

    // API status
    $isConfigured = ConfigProvider::isConfigured();

    // Recent sync logs
    $syncLogs = db_get_array(
        "SELECT * FROM ?:sphinx_sync_log WHERE sync_type = 'destinations' ORDER BY started_at DESC LIMIT 5"
    );

    Tygh::$app['view']->assign('counts_by_type', $countsByType);
    Tygh::$app['view']->assign('total_destinations', $totalDestinations);
    Tygh::$app['view']->assign('last_synced', $lastSynced);
    Tygh::$app['view']->assign('is_configured', $isConfigured);
    Tygh::$app['view']->assign('sync_logs', $syncLogs);

} elseif ($mode === 'destinations') {
    $repository = new DestinationRepository();

    $params = [
        'type'      => $_REQUEST['type'] ?? '',
        'parent_id' => (int) ($_REQUEST['parent_id'] ?? 0),
        'q'         => $_REQUEST['q'] ?? '',
        'page'      => max(1, (int) ($_REQUEST['page'] ?? 1)),
        'items_per_page' => (int) ($_REQUEST['items_per_page'] ?? 50),
    ];

    if (!empty($params['q'])) {
        $items = $repository->search($params['q'], 200);
        $total = count($items);
    } else {
        $result = $repository->getFiltered($params['type'], $params['parent_id'], $params['page'], $params['items_per_page']);
        $items = $result['items'];
        $total = $result['total'];
    }

    Tygh::$app['view']->assign('destinations', $items);
    Tygh::$app['view']->assign('search', $params);
    Tygh::$app['view']->assign('total_items', $total);
}
