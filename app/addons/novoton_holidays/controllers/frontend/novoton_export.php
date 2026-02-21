<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Public Export Controller
 *
 * Serves hotel features XML for CS-Cart Advanced Import via "Link to file".
 * Authenticated with the same access_key used by the cron system.
 *
 * Usage (paste into Advanced Import "Link to file"):
 *   https://yourstore.com/index.php?dispatch=novoton_export.hotel_features_xml&access_key=YOUR_KEY
 *
 * @package NovotonHolidays
 * @since 3.2.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Helpers\CronHelper;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Authentication via access_key
$provided_key = $_REQUEST['access_key'] ?? '';
if (!CronHelper::validateAccessKey($provided_key)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied. Invalid or missing access_key.';
    exit;
}

/**
 * Mode: hotel_features_xml
 * Generate and serve hotel features XML on-the-fly
 */
if ($mode == 'hotel_features_xml') {
    // Ensure the generation function is available
    $func_file = Registry::get('config.dir.addons') . 'novoton_holidays/functions/email.php';
    if (!function_exists('fn_novoton_holidays_generate_hotel_features_xml') && file_exists($func_file)) {
        require_once($func_file);
    }

    if (!function_exists('fn_novoton_holidays_generate_hotel_features_xml')) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Export function not available.';
        exit;
    }

    $result = fn_novoton_holidays_generate_hotel_features_xml();

    if (!$result['success'] || empty($result['file_path']) || !file_exists($result['file_path'])) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to generate XML: ' . ($result['error'] ?? 'Unknown error');
        exit;
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Length: ' . filesize($result['file_path']));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($result['file_path']);
    exit;
}

// Unknown mode
header('HTTP/1.1 404 Not Found');
header('Content-Type: text/plain; charset=utf-8');
echo 'Unknown mode. Available: hotel_features_xml';
exit;
