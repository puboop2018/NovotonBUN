<?php
/**
 * Novoton Holidays - Public Cron Controller
 *
 * Access via: index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo
 *
 * All modes are handled by Command classes in Cron/Commands/.
 * See CronDispatcher::getAvailableModes() for the full list.
 */

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PathResolver;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLogger;
use Tygh\Addons\NovotonHolidays\Helpers\CronHelper;
use Tygh\Addons\NovotonHolidays\Cron\CronDispatcher;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Authentication
$provided_access_key = $_REQUEST['access_key'] ?? '';
if (!CronHelper::validateAccessKey($provided_access_key)) {
    $storedKey = ConfigProvider::getCronAccessKey();
    if (empty($storedKey)) {
        CronHelper::sendAuthError('Cron Access Key not configured in addon settings.');
    } else {
        CronHelper::sendAuthError('Invalid or missing API key.');
    }
}

$mode = $_REQUEST['mode'] ?? 'resinfo';

header('Content-Type: text/plain; charset=utf-8');

// Initialize logger
$logger = new SyncLogger($mode);
$logger->outputHeader($mode);

// Load API
$src_dir = PathResolver::getPath('src');
if (file_exists($src_dir . 'NovotonApi.php')) {
    require_once($src_dir . 'NovotonApi.php');
}

try {
    $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
    $dispatcher = new CronDispatcher($api, $logger);

    if (!$dispatcher->hasMode($mode)) {
        $logger->output("Unknown mode: {$mode}");
        $logger->output("");
        $logger->output("Available modes:");
        foreach (CronDispatcher::getAvailableModes() as $m => $desc) {
            $logger->output("  {$m} - {$desc}");
        }
    } else {
        $result = $dispatcher->dispatch($mode, $_REQUEST);

        if (method_exists($logger, 'complete')) {
            $logger->complete($result['success'] ?? true);
        }
    }

} catch (\Exception $e) {
    $logger->output("ERROR: " . $e->getMessage());
    if (method_exists($logger, 'logEvent')) {
        $logger->logEvent('cron_error', ['error' => $e->getMessage()]);
    }
}

$logger->outputFooter();
exit;
