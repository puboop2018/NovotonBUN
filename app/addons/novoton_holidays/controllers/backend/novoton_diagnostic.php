<?php
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

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
        $status = $exists ? "[OK]" : "[MISSING]";
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
        'fn_novoton_holidays_place_order',
        'fn_novoton_holidays_get_orders_post'
    );
    
    foreach ($functions as $func) {
        $exists = function_exists($func);
        $status = $exists ? "[OK]" : "[MISSING]";
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
        echo ($has_require ? "[OK]" : "[WARNING]") . " Includes hooks.php: " . ($has_require ? "YES" : "NO") . "\n";
        
        // Check registered hooks
        preg_match_all("/'([^']+)'/", $init_content, $matches);
        if (!empty($matches[1])) {
            echo "[OK] Registered hooks:\n";
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
        echo "[OK] hooks.php exists\n";
        
        $hooks_content = file_get_contents($hooks_file);
        echo "     Size: " . strlen($hooks_content) . " bytes\n";
        
        // Check for each required function
        foreach ($functions as $func) {
            $found = strpos($hooks_content, "function $func") !== false;
            echo "     " . ($found ? "[OK]" : "[MISSING]") . " $func defined\n";
        }
        
        // Check for syntax errors
        exec("php -l " . escapeshellarg($hooks_file) . " 2>&1", $output, $return);
        $syntax_ok = strpos(implode('', $output), 'No syntax errors') !== false;
        echo "     " . ($syntax_ok ? "[OK]" : "[ERROR]") . " Syntax check\n";
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
    
    $addon = db_get_row("SELECT * FROM ?:addons WHERE addon = 'novoton_holidays'");
    if ($addon) {
        echo "[OK] Addon in database\n";
        echo "     Status: {$addon['status']} " . ($addon['status'] == 'A' ? '(Active)' : '(Disabled)') . "\n";
        echo "     Version: {$addon['version']}\n";
    } else {
        echo "[ERROR] Addon not found in database\n";
    }
    
    // Check tables
    $tables = array('novoton_hotel_prices', 'novoton_bookings', 'novoton_sync_log');
    foreach ($tables as $table) {
        $exists = db_get_field("SHOW TABLES LIKE ?s", 'cscart_' . $table);
        echo ($exists ? "[OK]" : "[MISSING]") . " Table: $table\n";
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
    $mode = 'check';
    
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
        $status = $exists ? "[OK]" : "[MISSING]";
        echo "$status $file ($size bytes)\n";
    }
    echo "\n";
    
    // 2. SETTINGS CHECK
    echo "2. ADDON SETTINGS\n";
    echo "-------------------\n";
    $settings = Registry::get('addons.novoton_holidays') ?? [];
    echo "API URL: " . ($settings['api_url'] ?? 'NOT SET') . "\n";
    echo "API User: " . ($settings['api_user'] ?? 'NOT SET') . "\n";
    echo "API Password: " . (!empty($settings['api_password']) ? '****' : 'NOT SET') . "\n";
    echo "Commission: " . ($settings['commission'] ?? 'NOT SET') . "%\n";
    
    // Parse countries properly
    $countries = $settings['selected_countries'] ?? 'NOT SET';
    if (is_array($countries)) {
        $country_list = [];
        foreach ($countries as $key => $value) {
            if ($value === 'Y' || $value === '1' || $value === 1) {
                $country_list[] = $key;
            } elseif (is_string($value) && strlen($value) > 2) {
                $country_list[] = $value;
            }
        }
        $countries = !empty($country_list) ? implode(', ', $country_list) : 'NOT SET';
    }
    echo "Selected Countries: " . $countries . "\n";
    echo "\n";
    
    // 3. DATABASE CHECK
    echo "3. DATABASE TABLES\n";
    echo "-------------------\n";
    $tables = array('novoton_hotel_prices', 'novoton_seasons', 'novoton_early_booking', 'novoton_hotels', 'novoton_bookings', 'novoton_sync_log');
    foreach ($tables as $table) {
        $count = db_get_field("SELECT COUNT(*) FROM ?:$table");
        echo "$table: $count records\n";
    }
    echo "\n";
    
    // 4. API CONNECTION TEST
    echo "4. API CONNECTION\n";
    echo "-------------------\n";
    $api_url = 'http://' . ($settings['api_url'] ?? '');
    echo "Testing: $api_url\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Code: $http_code\n";
    echo "Status: " . ($http_code == 200 ? "[OK] Connected" : "[ERROR] " . $error) . "\n";
    echo "\n";
    
    echo "========================================\n";
    echo "DIAGNOSTIC COMPLETE\n";
    echo "========================================\n";
    
    exit;
}
