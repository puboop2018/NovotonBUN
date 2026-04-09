<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   Frontend Controller for Novoton Holidays Cron                         *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/controllers/frontend/novoton_holidays.php
 *                                                                          *
 *   URL: index.php?dispatch=novoton_holidays.cron_update&access_key=YOUR_ACCESS_KEY
 *                                                                          *
 ****************************************************************************/

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Cron update
if ($mode == 'cron_update') {

    // Get addon settings with null safety
    $addon_settings = ConfigProvider::all();

    // Verify API key
    $provided_key = $_REQUEST['access_key'] ?? '';
    $stored_key = ConfigProvider::getCronAccessKey();
    
    if (empty($stored_key)) {
        exit('ERROR: Cron Access Key not configured in addon settings.');
    }
    
    if (empty($provided_key) || !hash_equals($stored_key, $provided_key)) {
        exit('ERROR: Invalid or missing API key.');
    }
    
    // Start output
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "========================================\n";
    echo "NOVOTON HOLIDAYS - CRON PRICE UPDATE\n";
    echo "========================================\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Get all products with Novoton prefix
    $prefixes = !empty($addon_settings['product_code_prefixes']) 
        ? explode(',', $addon_settings['product_code_prefixes']) 
        : array('NVT');
    
    $prefix_conditions = array();
    foreach ($prefixes as $prefix) {
        $prefix = trim($prefix);
        if (!empty($prefix)) {
            $prefix_conditions[] = db_quote("product_code LIKE ?l", $prefix . '%');
        }
    }
    
    if (empty($prefix_conditions)) {
        echo "ERROR: No product code prefixes configured.\n";
        exit;
    }
    
    $condition = '(' . implode(' OR ', $prefix_conditions) . ')';
    
    $products = db_get_array(
        "SELECT p.product_id, p.product_code, pd.product 
         FROM ?:products AS p
         LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
         WHERE " . $condition . " 
         AND p.status = 'A' 
         ORDER BY p.product_id",
        CART_LANGUAGE
    );
    
    if (empty($products)) {
        echo "No products found.\n";
        exit;
    }
    
    echo "Found " . count($products) . " products to update.\n\n";
    
    $stats = array(
        'updated' => 0,
        'failed' => 0,
        'no_data' => 0,
        'missing' => 0
    );
    
    // Load func.php if needed
    if (!function_exists('fn_novoton_holidays_update_product_prices')) {
        $func_file = Registry::get('config.dir.addons') . 'novoton_holidays/func.php';
        if (file_exists($func_file)) {
            require_once($func_file);
        }
    }
    
    // Update each product
    foreach ($products as $index => $product) {
        $num = $index + 1;
        echo "[$num/" . count($products) . "] {$product['product_code']}... ";
        
        if (function_exists('fn_novoton_holidays_update_product_prices')) {
            $result = fn_novoton_holidays_update_product_prices($product['product_id']);
            
            if ($result === true) {
                echo "Good\n";
                $stats['updated']++;
            } elseif ($result === 'no_data') {
                echo "NO_DATA\n";
                $stats['no_data']++;
            } elseif ($result === 'missing') {
                echo "MISSING\n";
                $stats['missing']++;
            } else {
                echo "FAILED\n";
                $stats['failed']++;
            }
        } else {
            echo "ERROR\n";
            $stats['failed']++;
        }
        
        // Small delay
        usleep(\Tygh\Addons\NovotonHolidays\Constants::API_DELAY_NORMAL);
    }
    
    echo "\n========================================\n";
    echo "SUMMARY\n";
    echo "========================================\n";
    echo "Updated:     " . $stats['updated'] . "\n";
    echo "Failed:      " . $stats['failed'] . "\n";
    echo "No Data:     " . $stats['no_data'] . "\n";
    echo "Missing:     " . $stats['missing'] . "\n";
    echo "========================================\n";
    echo "Completed: " . date('Y-m-d H:i:s') . "\n";
    
    // Log to database
    $syncLogRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->syncLogRepository();
    $syncLogRepo->create('cron_price_update', [
        'total' => count($products),
        'updated' => $stats['updated'],
        'failed' => $stats['failed'],
        'status' => 'completed',
    ]);
    
    exit;
}

/**
 * Mode: cron_export_hotel_features
 * Generate hotel features CSV via cron (frontend)
 * URL: index.php?dispatch=novoton_holidays.cron_export_hotel_features&access_key=YOUR_ACCESS_KEY
 */
if ($mode == 'cron_export_hotel_features') {
    $expected_key = ConfigProvider::getCronAccessKey();
    $provided_key = $_REQUEST['access_key'] ?? '';

    header('Content-Type: text/plain; charset=utf-8');

    if (empty($expected_key)) {
        echo "[ERROR] Cron API key not configured.\n";
        exit;
    }
    
    if (!hash_equals($expected_key, $provided_key)) {
        echo "[ERROR] Invalid API key.\n";
        exit;
    }

    echo "=== NOVOTON Hotel Features CSV Export - " . date('Y-m-d H:i:s') . " ===\n";
    
    $result = fn_novoton_holidays_generate_hotel_features_csv();
    
    if ($result['success']) {
        echo "Status: SUCCESS\n";
        echo "File: {$result['file_path']}\n";
        echo "Hotels: {$result['count']}\n";
        echo "Languages: RO, EN\n";
        echo "[Good] CSV ready for CS-Cart import.\n";
    } else {
        echo "Status: FAILED\n";
        echo "Error: {$result['error']}\n";
    }
    
    exit;
}

/**
 * Mode: get_hotel_features_csv
 * Direct download of hotel features CSV (frontend)
 * URL: index.php?dispatch=novoton_holidays.get_hotel_features_csv&access_key=YOUR_ACCESS_KEY
 */
if ($mode == 'get_hotel_features_csv') {
    $expected_key = ConfigProvider::getCronAccessKey();
    $provided_key = $_REQUEST['access_key'] ?? '';

    // Verify API key
    if (empty($expected_key) || !hash_equals($expected_key, $provided_key)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
        echo "Access denied. Invalid or missing API key.";
        exit;
    }

    $export_dir = fn_get_files_dir_path() . 'novoton_exports/';
    $file_path = $export_dir . 'hotel_features_import.csv';
    
    if (!file_exists($file_path)) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain');
        echo "CSV file not found. Please generate it first using:\n";
        echo "index.php?dispatch=novoton_holidays.cron_export_hotel_features&access_key=YOUR_ACCESS_KEY";
        exit;
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel_features_import.csv"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($file_path);
    exit;
}