<?php
/**
 * Novoton Cron Job Handler
 * Path: app/addons/novoton_holidays/cron.php
 * 
 * Usage: php /path/to/cscart/app/addons/novoton_holidays/cron.php access_key=YOUR_ACCESS_KEY
 * 
 * Or via URL: http://yourdomain.com/app/addons/novoton_holidays/cron.php?access_key=YOUR_ACCESS_KEY
 * 
 * Note: For better integration, use index.php?dispatch=novoton_cron.run&access_key=YOUR_ACCESS_KEY instead.
 */

// Bootstrap CS-Cart
if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\PriceSync;

// Verify API key
$settings = Registry::get('addons.novoton_holidays') ?? [];
$storedApiKey = $settings['cron_access_key'] ?? '';

// Accept access_key from URL or CLI
$providedKey = $_GET['access_key'] ?? '';

// Check CLI arguments
if (empty($providedKey) && isset($argv[1])) {
    $arg = $argv[1];
    if (strpos($arg, 'access_key=') === 0) {
        $providedKey = str_replace('access_key=', '', $arg);
    }
}

if (empty($storedApiKey)) {
    die("ERROR: Cron Access Key not set in addon settings.\n");
}

if (empty($providedKey) || $providedKey !== $storedApiKey) {
    die("ERROR: Invalid or missing API key.\n");
}

// Get mode from request
$mode = isset($_GET['mode']) ? $_GET['mode'] : '';
if (empty($mode) && isset($argv[2])) {
    $mode = str_replace('mode=', '', $argv[2]);
}

// Log start
$logMessage = "[" . date('Y-m-d H:i:s') . "] Novoton Cron Started - Mode: " . ($mode ?: 'full') . "\n";
echo $logMessage;

fn_log_event('novoton_holidays', 'cron_start', [
    'timestamp' => time(),
    'mode' => $mode ?: 'full',
    'message' => 'Cron job started'
]);

try {
    
    // Mode: resinfo - Only check ASK booking statuses
    if ($mode === 'resinfo') {
        echo "Checking booking statuses...\n";
        $statusResults = fn_novoton_cron_resinfo();
        echo "\n=== STATUS CHECK COMPLETED ===\n";
        echo "Checked: " . ($statusResults['checked'] ?? 0) . " bookings\n";
        echo "Changed: " . ($statusResults['changed'] ?? 0) . " status changes\n";
        echo "==============================\n\n";
        
        fn_log_event('novoton_holidays', 'cron_complete', [
            'timestamp' => time(),
            'mode' => 'resinfo',
            'results' => $statusResults
        ]);
        
        echo "[" . date('Y-m-d H:i:s') . "] Status check completed.\n";
        exit(0);
    }
    
    // Mode: cleanup - Only cleanup orphan bookings
    if ($mode === 'cleanup') {
        echo "=== NOVOTON CLEANUP STARTED ===\n";
        echo "[" . date('Y-m-d H:i:s') . "]\n\n";
        
        // 1. Clean orphan bookings (no order_id, older than 48h)
        echo "1. Cleaning orphan bookings...\n";
        $orphan_count = db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_bookings 
             WHERE order_id = 0 
             AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );
        
        $orphans_deleted = db_query(
            "DELETE FROM ?:novoton_bookings 
             WHERE order_id = 0 
             AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );
        echo "   Orphan bookings deleted: " . $orphan_count . "\n\n";
        
        // 2. Clean old sync logs (keep last 100)
        echo "2. Cleaning old sync logs...\n";
        $total_logs = db_get_field("SELECT COUNT(*) FROM ?:novoton_sync_log");
        $logs_to_keep = 100;
        $logs_deleted = 0;
        
        if ($total_logs > $logs_to_keep) {
            // Get the log_id threshold (keep newest 100)
            $threshold_id = db_get_field(
                "SELECT log_id FROM ?:novoton_sync_log 
                 ORDER BY sync_date DESC 
                 LIMIT 1 OFFSET ?i",
                $logs_to_keep - 1
            );
            
            if ($threshold_id) {
                $logs_deleted = db_query(
                    "DELETE FROM ?:novoton_sync_log WHERE log_id < ?i",
                    $threshold_id
                );
            }
        }
        echo "   Total logs: " . $total_logs . ", Kept: " . $logs_to_keep . ", Deleted: " . $logs_deleted . "\n\n";
        
        // 3. Clean expired cache entries
        echo "3. Cleaning expired cache...\n";
        $expired_count = db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_cache WHERE expires_at < NOW()"
        );
        
        $cache_deleted = db_query(
            "DELETE FROM ?:novoton_cache WHERE expires_at < NOW()"
        );
        echo "   Expired cache entries deleted: " . $expired_count . "\n\n";
        
        // Summary
        echo "=== CLEANUP COMPLETED ===\n";
        echo "Orphan bookings: " . $orphan_count . " deleted\n";
        echo "Sync logs: " . $logs_deleted . " deleted (kept " . $logs_to_keep . ")\n";
        echo "Expired cache: " . $expired_count . " deleted\n";
        echo "=========================\n\n";
        
        fn_log_event('novoton_holidays', 'cron_complete', [
            'timestamp' => time(),
            'mode' => 'cleanup',
            'orphans_deleted' => $orphan_count,
            'logs_deleted' => $logs_deleted,
            'cache_deleted' => $expired_count
        ]);
        
        echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed.\n";
        exit(0);
    }
    
    // Mode: hotel_list - Only sync hotel information
    if ($mode === 'hotel_list') {
        echo "Syncing hotel information...\n";
        
        // Load API
        $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
        if (file_exists($src_dir . 'NovotonApi.php')) {
            require_once($src_dir . 'NovotonApi.php');
        }
        
        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();
        $hotels_updated = 0;
        
        // Get all Novoton products
        $products = db_get_array(
            "SELECT product_id, product_code FROM ?:products WHERE product_code LIKE 'NVT%'"
        );
        
        foreach ($products as $product) {
            preg_match('/\d+/', $product['product_code'], $matches);
            $hotel_id = $matches[0] ?? null;
            
            if ($hotel_id) {
                echo "  Syncing hotel {$hotel_id}...\n";
                try {
                    $hotelInfo = $api->getHotelInfo($hotel_id);
                    if ($hotelInfo) {
                        // Update hotel cache
                        db_query(
                            "INSERT INTO ?:novoton_hotels (hotel_id, product_id, hotel_name, city, country, hotel_data, updated_at) 
                             VALUES (?s, ?i, ?s, ?s, ?s, ?s, NOW())
                             ON DUPLICATE KEY UPDATE 
                             product_id = ?i, hotel_name = ?s, city = ?s, country = ?s, hotel_data = ?s, updated_at = NOW()",
                            $hotel_id,
                            $product['product_id'],
                            (string)($hotelInfo->Hotel ?? ''),
                            (string)($hotelInfo->City ?? ''),
                            (string)($hotelInfo->Country ?? 'BULGARIA'),
                            json_encode($hotelInfo),
                            $product['product_id'],
                            (string)($hotelInfo->Hotel ?? ''),
                            (string)($hotelInfo->City ?? ''),
                            (string)($hotelInfo->Country ?? 'BULGARIA'),
                            json_encode($hotelInfo)
                        );
                        $hotels_updated++;
                    }
                } catch (Exception $e) {
                    echo "    Error: " . $e->getMessage() . "\n";
                }
            }
        }
        
        echo "\n=== HOTEL LIST SYNC COMPLETED ===\n";
        echo "Hotels updated: {$hotels_updated}\n";
        echo "=================================\n\n";
        
        fn_log_event('novoton_holidays', 'cron_complete', [
            'timestamp' => time(),
            'mode' => 'hotel_list',
            'hotels_updated' => $hotels_updated
        ]);
        
        echo "[" . date('Y-m-d H:i:s') . "] Hotel list sync completed.\n";
        exit(0);
    }
    
    // Default mode: Full sync (prices + status check)
    // Initialize price sync
    $sync = new PriceSync();
    
    // Run sync
    echo "Syncing prices...\n";
    $stats = $sync->syncAllProducts();
    
    // Output results
    echo "\n=== SYNC COMPLETED ===\n";
    echo "Total products: " . $stats['total'] . "\n";
    echo "Updated: " . count($stats['updated']) . "\n";
    echo "Failed: " . count($stats['failed']) . "\n";
    echo "No data: " . count($stats['no_data']) . "\n";
    echo "Missing in CS-Cart: " . count($stats['missing']) . "\n";
    echo "======================\n\n";
    
    // Check booking statuses (ASK bookings)
    echo "Checking booking statuses...\n";
    $statusResults = fn_novoton_cron_resinfo();
    echo "Checked: " . $statusResults['checked'] . " bookings\n";
    echo "Changed: " . $statusResults['changed'] . " status changes\n";
    echo "======================\n\n";
    
    // === A73: Enhanced Cleanup ===
    echo "Running cleanup tasks...\n";
    
    // 1. Cleanup orphan bookings (no order_id, older than 48 hours)
    $orphans_deleted = db_query(
        "DELETE FROM ?:novoton_bookings 
         WHERE order_id = 0 
         AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
    );
    echo "  - Orphan bookings: " . $orphans_deleted . " deleted\n";
    
    // 2. Clean old sync logs (keep last 100)
    $total_logs = db_get_field("SELECT COUNT(*) FROM ?:novoton_sync_log");
    $logs_deleted = 0;
    if ($total_logs > 100) {
        $threshold_id = db_get_field(
            "SELECT log_id FROM ?:novoton_sync_log ORDER BY sync_date DESC LIMIT 1 OFFSET 99"
        );
        if ($threshold_id) {
            $logs_deleted = db_query("DELETE FROM ?:novoton_sync_log WHERE log_id < ?i", $threshold_id);
        }
    }
    echo "  - Sync logs: " . $logs_deleted . " deleted (kept 100)\n";
    
    // 3. Clean expired cache
    $cache_deleted = db_query("DELETE FROM ?:novoton_cache WHERE expires_at < NOW()");
    echo "  - Expired cache: " . $cache_deleted . " deleted\n";
    
    echo "======================\n\n";
    
    // Show detailed results
    if (!empty($stats['updated'])) {
        echo "UPDATED PRODUCTS:\n";
        foreach ($stats['updated'] as $item) {
            echo "  - " . $item . "\n";
        }
        echo "\n";
    }
    
    if (!empty($stats['failed'])) {
        echo "FAILED PRODUCTS:\n";
        foreach ($stats['failed'] as $item) {
            echo "  - " . $item . "\n";
        }
        echo "\n";
    }
    
    if (!empty($stats['no_data'])) {
        echo "NO DATA PRODUCTS:\n";
        foreach ($stats['no_data'] as $item) {
            echo "  - " . $item . "\n";
        }
        echo "\n";
    }
    
    if (!empty($stats['missing'])) {
        echo "MISSING IN CS-CART:\n";
        foreach ($stats['missing'] as $item) {
            echo "  - " . $item . "\n";
        }
        echo "\n";
    }
    
    fn_log_event('novoton_holidays', 'cron_complete', [
        'timestamp' => time(),
        'stats' => $stats
    ]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Cron job completed successfully.\n";
    
} catch (Exception $e) {
    $errorMessage = "ERROR: " . $e->getMessage() . "\n";
    echo $errorMessage;
    
    fn_log_event('novoton_holidays', 'cron_error', [
        'timestamp' => time(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Send error email
    $companyData = fn_get_company_data(0);
    $adminEmail = $companyData['company_users_department'] ?? '';
    
    if (!empty($adminEmail)) {
        $subject = 'Novoton Cron Error - ' . date('Y-m-d H:i:s');
        $message = "Novoton price sync cron job failed:\n\n";
        $message .= "Error: " . $e->getMessage() . "\n\n";
        $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
        
        fn_send_mail($adminEmail, $adminEmail, $subject, $message);
    }
    
    exit(1);
}

// Example crontab entries:
// 
// Runs every day at 3 AM:
// 0 3 * * * php /path/to/cscart/app/addons/novoton_holidays/cron.php access_key=YOUR_ACCESS_KEY >> /var/log/novoton_cron.log 2>&1
// 
// Runs every 6 hours:
// 0 0,6,12,18 * * * php /path/to/cscart/app/addons/novoton_holidays/cron.php access_key=YOUR_ACCESS_KEY >> /var/log/novoton_cron.log 2>&1
// 
// Or use wget/curl:
// 0 3 * * * wget -O - -q "http://yourdomain.com/app/addons/novoton_holidays/cron.php?access_key=YOUR_ACCESS_KEY" >> /var/log/novoton_cron.log 2>&1