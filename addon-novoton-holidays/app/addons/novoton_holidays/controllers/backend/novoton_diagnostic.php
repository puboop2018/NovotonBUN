<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   Simple Diagnostic Controller                                          *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/controllers/backend/novoton_diagnostic.php
 *                                                                          *
 *   Access: admin.php?dispatch=novoton_diagnostic.check                   *
 *                                                                          *
 ****************************************************************************/

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Health Check Endpoint - JSON response for automated monitoring
 * Access: admin.php?dispatch=novoton_diagnostic.health
 *
 * Returns:
 * - status: "healthy", "degraded", or "unhealthy"
 * - components: status of each subsystem
 * - metrics: key performance indicators
 */
if ($mode == 'health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $health = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'version' => ConfigProvider::getVersion(),
        'components' => [],
        'metrics' => []
    ];

    $issues = [];

    // 1. Database connectivity
    try {
        $hotelRepo = _nvt_hotel_repo();
        $bookingRepo = _nvt_booking_repo();
        $syncLogRepo = _nvt_sync_log_repo();

        $db_start = microtime(true);
        $hotels_count = $hotelRepo->count();
        $bookings_count = $bookingRepo->count();
        $db_time = round((microtime(true) - $db_start) * 1000, 2);

        $health['components']['database'] = [
            'status' => 'healthy',
            'response_time_ms' => $db_time,
            'hotels_count' => $hotels_count,
            'bookings_count' => $bookings_count
        ];
    } catch (Exception $e) {
        $health['components']['database'] = [
            'status' => 'unhealthy',
            'error' => $e->getMessage()
        ];
        $issues[] = 'database';
    }

    // 2. API connectivity (circuit breaker status)
    try {
        $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
        if (file_exists($src_dir . 'NovotonApi.php')) {
            require_once($src_dir . 'NovotonApi.php');
            $api = _nvt_api();
            $circuit_status = $api->getCircuitStatus();

            $api_status = 'healthy';
            if ($circuit_status['is_open']) {
                $api_status = 'unhealthy';
                $issues[] = 'api_circuit_open';
            } elseif ($circuit_status['failure_count'] > 0) {
                $api_status = 'degraded';
            }

            $health['components']['api'] = [
                'status' => $api_status,
                'circuit_breaker' => $circuit_status
            ];
        } else {
            $health['components']['api'] = [
                'status' => 'unhealthy',
                'error' => 'NovotonApi.php not found'
            ];
            $issues[] = 'api_missing';
        }
    } catch (Exception $e) {
        $health['components']['api'] = [
            'status' => 'unhealthy',
            'error' => $e->getMessage()
        ];
        $issues[] = 'api_error';
    }

    // 3. Cache status
    try {
        $cache_service_file = Registry::get('config.dir.addons') . 'novoton_holidays/src/Services/CacheService.php';
        if (file_exists($cache_service_file)) {
            require_once($cache_service_file);
            $cache = _nvt_cache_service();
            $cache_stats = $cache->getStats();

            $health['components']['cache'] = [
                'status' => 'healthy',
                'storage' => $cache_stats['storage'] ?? 'file',
                'memory_items' => $cache_stats['memory_items'] ?? 0,
                'persistent_items' => $cache_stats['persistent_items'] ?? 0
            ];
        } else {
            $health['components']['cache'] = [
                'status' => 'degraded',
                'error' => 'CacheService not available'
            ];
        }
    } catch (Exception $e) {
        $health['components']['cache'] = [
            'status' => 'degraded',
            'error' => $e->getMessage()
        ];
    }

    // 4. Recent sync status
    try {
        $recent = $syncLogRepo->findRecent(1);
        $last_sync = !empty($recent) ? $recent[0] : null;

        if ($last_sync) {
            $sync_age_hours = (time() - strtotime($last_sync['sync_date'])) / 3600;

            $sync_status = 'healthy';
            if ($sync_age_hours > 48) {
                $sync_status = 'degraded';
            }
            if ($last_sync['status'] === 'failed') {
                $sync_status = 'unhealthy';
                $issues[] = 'last_sync_failed';
            }

            $health['components']['sync'] = [
                'status' => $sync_status,
                'last_sync_type' => $last_sync['sync_type'],
                'last_sync_date' => $last_sync['sync_date'],
                'last_sync_status' => $last_sync['status'],
                'hours_since_sync' => round($sync_age_hours, 1)
            ];
        } else {
            $health['components']['sync'] = [
                'status' => 'degraded',
                'message' => 'No sync history found'
            ];
        }
    } catch (Exception $e) {
        $health['components']['sync'] = [
            'status' => 'unhealthy',
            'error' => $e->getMessage()
        ];
        $issues[] = 'sync';
    }

    // 5. Key metrics
    try {
        $recent_bookings = $bookingRepo->count(['check_in_from' => date('Y-m-d H:i:s', strtotime('-24 hours'))]);
        $pending_bookings = $bookingRepo->count(['status' => 'pending']);
        $failed_bookings = $bookingRepo->count(['status' => 'failed']);
        $hotels_with_prices = $hotelRepo->count(['has_room_price' => 'Y']);

        $health['metrics'] = [
            'bookings_24h' => $recent_bookings,
            'pending_bookings' => $pending_bookings,
            'failed_bookings_24h' => $failed_bookings,
            'hotels_with_prices' => $hotels_with_prices,
            'failure_rate_24h' => $recent_bookings > 0
                ? round(($failed_bookings / $recent_bookings) * 100, 1) . '%'
                : '0%'
        ];
    } catch (Exception $e) {
        $health['metrics'] = ['error' => $e->getMessage()];
    }

    // Determine overall status
    if (!empty($issues)) {
        $critical_issues = array_intersect($issues, ['database', 'api_circuit_open', 'api_missing']);
        if (!empty($critical_issues)) {
            $health['status'] = 'unhealthy';
        } else {
            $health['status'] = 'degraded';
        }
        $health['issues'] = $issues;
    }

    echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($mode == 'check') {
    
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "========================================\n";
    echo "NOVOTON HOLIDAYS DIAGNOSTIC\n";
    echo "========================================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    $addon_dir = Registry::get('config.dir.addons') . 'novoton_holidays/';
    
    // 1. FILE CHECK
    echo "1. FILE CHECK\n";
    echo "-------------------\n";
    
    $files = array('addon.xml', 'init.php', 'hooks.php', 'func.php', 'cron.php');
    foreach ($files as $file) {
        $path = $addon_dir . $file;
        $exists = file_exists($path);
        $size = $exists ? filesize($path) : 0;
        $status = $exists ? "[Good]" : "[MISSING]";
        echo "$status $file ($size bytes)\n";
        if (!$exists) {
            echo "       Path: $path\n";
        }
    }
    echo "\n";
    
    // 2. FUNCTION CHECK
    echo "2. HOOK FUNCTIONS\n";
    echo "-------------------\n";
    
    $functions = array(
        'fn_novoton_holidays_get_product_tabs_post',
        'fn_novoton_holidays_get_product_data_post',
        'fn_novoton_holidays_delete_product_post',
        'fn_novoton_holidays_place_order_post',
        'fn_novoton_holidays_get_orders_post'
    );
    
    foreach ($functions as $func) {
        $exists = function_exists($func);
        $status = $exists ? "[Good]" : "[MISSING]";
        echo "$status $func\n";
        
        if ($exists) {
            $reflection = new ReflectionFunction($func);
            echo "       Parameters: " . count($reflection->getParameters()) . "\n";
            echo "       File: " . basename($reflection->getFileName()) . "\n";
        }
    }
    echo "\n";
    
    // 3. INIT.PHP CHECK
    echo "3. INIT.PHP ANALYSIS\n";
    echo "-------------------\n";
    
    $init_file = $addon_dir . 'init.php';
    if (file_exists($init_file)) {
        $init_content = file_get_contents($init_file);
        
        // Check for require
        $has_require = (strpos($init_content, 'require') !== false || strpos($init_content, 'include') !== false);
        echo ($has_require ? "[Good]" : "[WARNING]") . " Includes hooks.php: " . ($has_require ? "YES" : "NO") . "\n";
        
        // Check registered hooks
        preg_match_all("/'([^']+)'/", $init_content, $matches);
        if (!empty($matches[1])) {
            echo "[Good] Registered hooks:\n";
            foreach ($matches[1] as $hook) {
                echo "     - $hook\n";
            }
        }
        
        // Check for view access
        if (strpos($init_content, "['view']") !== false || strpos($init_content, "->view") !== false) {
            echo "[WARNING] init.php accesses view - this can cause errors!\n";
        }
    } else {
        echo "[ERROR] init.php not found!\n";
    }
    echo "\n";
    
    // 4. HOOKS.PHP CHECK
    echo "4. HOOKS.PHP ANALYSIS\n";
    echo "-------------------\n";
    
    $hooks_file = $addon_dir . 'hooks.php';
    if (file_exists($hooks_file)) {
        echo "[Good] hooks.php exists\n";
        
        $hooks_content = file_get_contents($hooks_file);
        echo "     Size: " . strlen($hooks_content) . " bytes\n";
        
        // Check for each required function
        foreach ($functions as $func) {
            $found = strpos($hooks_content, "function $func") !== false;
            echo "     " . ($found ? "[Good]" : "[MISSING]") . " $func defined\n";
        }
        
        // Check for syntax errors
        exec("php -l " . escapeshellarg($hooks_file) . " 2>&1", $output, $return);
        $syntax_ok = strpos(implode('', $output), 'No syntax errors') !== false;
        echo "     " . ($syntax_ok ? "[Good]" : "[ERROR]") . " Syntax check\n";
        if (!$syntax_ok) {
            echo "     Error: " . implode("\n     ", $output) . "\n";
        }
    } else {
        echo "[ERROR] hooks.php NOT FOUND\n";
        echo "     Expected at: $hooks_file\n";
    }
    echo "\n";
    
    // 5. DATABASE CHECK
    echo "5. DATABASE STATUS\n";
    echo "-------------------\n";
    
    $addon = db_get_row("SELECT * FROM ?:addons WHERE addon = ?s", \Tygh\Addons\NovotonHolidays\Constants::ADDON_ID);
    if ($addon) {
        echo "[Good] Addon in database\n";
        echo "     Status: {$addon['status']} " . ($addon['status'] == 'A' ? '(Active)' : '(Disabled)') . "\n";
        echo "     Version: {$addon['version']}\n";
    } else {
        echo "[ERROR] Addon not found in database\n";
    }
    
    // Check tables (V3 architecture)
    $tables = array('novoton_hotels', 'novoton_hotel_packages', 'novoton_bookings', 'novoton_sync_log');
    foreach ($tables as $table) {
        $exists = db_get_field("SHOW TABLES LIKE ?s", Registry::get('config.table_prefix') . $table);
        echo ($exists ? "[Good]" : "[MISSING]") . " Table: $table\n";
    }
    echo "\n";
    
    // 6. RECOMMENDATIONS
    echo "6. RECOMMENDATIONS\n";
    echo "-------------------\n";
    
    $issues = 0;
    
    if (!file_exists($hooks_file)) {
        $issues++;
        echo "??  HIGH: Upload hooks.php file\n";
        echo "   ? Download hooks_complete.php and rename to hooks.php\n\n";
    }
    
    if (file_exists($hooks_file) && !function_exists('fn_novoton_holidays_get_product_tabs_post')) {
        $issues++;
        echo "??  HIGH: Hook functions not loaded\n";
        echo "   ? Add this to init.php (after BOOTSTRAP check):\n";
        echo "   require_once(__DIR__ . '/hooks.php');\n\n";
    }
    
    if (file_exists($init_file)) {
        $init_content = file_get_contents($init_file);
        if (strpos($init_content, "['view']") !== false) {
            $issues++;
            echo "??  MEDIUM: init.php accesses view directly\n";
            echo "   ? Wrap view access in: if (Tygh\Tygh::\$app->has('view')) { ... }\n\n";
        }
    }
    
    if ($addon && $addon['status'] !== 'A') {
        $issues++;
        echo "??  MEDIUM: Addon is disabled\n";
        echo "   ? Enable it in Add-ons ? Manage add-ons\n\n";
    }
    
    if ($issues == 0) {
        echo "? No critical issues found!\n";
        echo "   If you're still having problems:\n";
        echo "   1. Clear cache: rm -rf var/cache/*\n";
        echo "   2. Reinstall addon (disable then enable)\n";
        echo "   3. Check var/logs/errors.log\n";
    } else {
        echo "\nFound $issues issue(s). Fix them in order shown above.\n";
    }
    
    echo "\n========================================\n";
    echo "END OF DIAGNOSTIC\n";
    echo "========================================\n";
    
    exit;
}
// Alias 'test' to 'check' for backward compatibility
if ($mode == 'test') {
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_diagnostic.check'];
}
