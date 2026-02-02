<?php
/**
 * Novoton Price Synchronization Class
 * Path: app/addons/novoton_holidays/src/PriceSync.php
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Registry;

class PriceSync
{
    private $api;
    private $defaultCountry;
    private $productPrefixes;
    
    public function __construct()
    {
        $this->api = new NovotonApi();
        
        $settings = Registry::get('addons.novoton_holidays') ?? [];
        $this->defaultCountry = $settings['default_country'] ?? 'BULGARIA';
        $this->productPrefixes = explode(',', $settings['product_code_prefixes'] ?? 'NVT');
        $this->productPrefixes = array_map('trim', $this->productPrefixes);
    }

    /**
     * Get products to sync based on prefix
     */
    private function getProductsToSync()
    {
        $prefixConditions = [];
        foreach ($this->productPrefixes as $prefix) {
            $prefixConditions[] = "product_code LIKE '" . db_quote($prefix) . "%'";
        }
        
        $condition = implode(' OR ', $prefixConditions);
        
        $products = db_get_array(
            "SELECT p.product_id, pd.product, p.product_code, p.status 
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE (" . $condition . ") 
             AND p.status = 'A'",
            CART_LANGUAGE
        );
        
        return $products;
    }

    /**
     * Extract hotel ID from product
     */
    private function getHotelIdFromProduct($product)
    {
        // Try to get from product features
        $hotelId = db_get_field(
            "SELECT variant_id 
             FROM ?:product_features_values 
             WHERE product_id = ?i 
             AND feature_id = (SELECT feature_id FROM ?:product_features WHERE feature_code = 'novoton_hotel_id' LIMIT 1)
             LIMIT 1",
            $product['product_id']
        );
        
        if (empty($hotelId)) {
            // Try to extract from product code (e.g., NVT442)
            preg_match('/\d+/', $product['product_code'], $matches);
            $hotelId = $matches[0] ?? null;
        }
        
        return $hotelId;
    }

    /**
     * Sync prices for a single product
     */
    public function syncProductPrices($productId, &$stats)
    {
        $product = db_get_row(
            "SELECT p.product_id, pd.product, p.product_code 
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE p.product_id = ?i",
            CART_LANGUAGE,
            $productId
        );
        
        if (empty($product)) {
            $stats['errors'][] = "Product ID $productId not found";
            return false;
        }
        
        $hotelId = $this->getHotelIdFromProduct($product);
        
        if (empty($hotelId)) {
            $stats['no_data'][] = $product['product_code'] . ' - ' . $product['product'];
            return false;
        }
        
        try {
            // Get hotel info (for packages)
            $hotelInfo = $this->api->getHotelInfo($hotelId);
            
            if (!$hotelInfo || !isset($hotelInfo->packages)) {
                $stats['no_data'][] = $product['product_code'] . ' - ' . $product['product'];
                return false;
            }
            
            // Convert to array
            $hotelData = json_decode(json_encode($hotelInfo), true);
            
            // Normalize packages array
            if (isset($hotelData['packages']['IdCont'])) {
                $hotelData['packages'] = [$hotelData['packages']];
            }
            
            if (empty($hotelData['packages'])) {
                $stats['no_data'][] = $product['product_code'] . ' - ' . $product['product'];
                return false;
            }
            
            // Select package (matching working code logic)
            $productPackage = db_get_field(
                'SELECT hotel_package_id FROM ?:products WHERE product_code = ?s',
                $product['product_code']
            );
            
            $selectedPackage = null;
            
            // Try to use product's assigned package
            if (!empty($productPackage)) {
                foreach ($hotelData['packages'] as $pkg) {
                    if ($pkg['IdCont'] == $productPackage) {
                        $selectedPackage = $pkg;
                        break;
                    }
                }
            }
            
            // If no assigned package, pick first non-bracket package
            if (empty($selectedPackage)) {
                foreach ($hotelData['packages'] as $pkg) {
                    if (substr($pkg['PackageName'], -1) != ']') {
                        $selectedPackage = $pkg;
                        break;
                    }
                }
            }
            
            // If still nothing, just use first package
            if (empty($selectedPackage)) {
                $selectedPackage = $hotelData['packages'][0];
            }
            
            // Get price info for selected package
            $hotelPrices = $this->api->getPriceInfo($hotelId, $selectedPackage['PackageName']);
            
            if (empty($hotelPrices) || !isset($hotelPrices->season_price)) {
                $stats['no_data'][] = $product['product_code'] . ' - ' . $product['product'];
                return false;
            }
            
            // Convert to array
            $priceData = json_decode(json_encode($hotelPrices), true);
            
            // Build rooms array from hotel info
            $rooms = [];
            if (isset($hotelData['rooms'])) {
                $roomList = isset($hotelData['rooms']['IdRoom']) ? [$hotelData['rooms']] : $hotelData['rooms'];
                foreach ($roomList as $room) {
                    $rooms[trim($room['IdRoom'])] = $room;
                }
            }
            
            // Handle extras
            if (isset($priceData['extras_single']) && !empty($priceData['extras_single'])) {
                if (isset($priceData['extras_single']['IdAge'])) {
                    $priceData['extras_single'] = [$priceData['extras_single']];
                }
                $extrasSingle = [];
                foreach ($priceData['extras_single'] as $extra) {
                    $extrasSingle[trim($extra['IdAge'])] = $extra;
                }
                $priceData['extras_single'] = $extrasSingle;
            }
            
            if (isset($priceData['extras_daily']) && !empty($priceData['extras_daily'])) {
                if (isset($priceData['extras_daily']['IdAge'])) {
                    $priceData['extras_daily'] = [$priceData['extras_daily']];
                }
                $extrasDaily = [];
                foreach ($priceData['extras_daily'] as $extra) {
                    $extrasDaily[trim($extra['IdAge'])] = $extra;
                }
                $priceData['extras_daily'] = $extrasDaily;
            }
            
            $priceData['rooms'] = $rooms;
            
            // Save to database using existing novoton_hotel_prices table
            // Clear old prices for this product
            db_query('DELETE FROM ?:novoton_hotel_prices WHERE product_id = ?i', $productId);
            
            // Normalize season_price array
            $seasonPrices = $priceData['season_price'] ?? [];
            if (isset($seasonPrices['Code'])) {
                // Single season entry
                $seasonPrices = [$seasonPrices];
            }
            
            $pricesAdded = 0;
            
            // The API returns Price1-Price20 for different periods
            // Each season_price entry has: IdRoom, IdBoard, IdAge, Price1-Price20, etc.
            foreach ($seasonPrices as $season) {
                if (!is_array($season)) {
                    continue;
                }
                
                $roomId = $season['IdRoom'] ?? '';
                $boardId = $season['IdBoard'] ?? '';
                $idAge = $season['IdAge'] ?? '';
                
                // Find the first non-zero price from Price1-Price20
                $basePrice = 0;
                for ($i = 1; $i <= 20; $i++) {
                    $priceKey = 'Price' . $i;
                    if (isset($season[$priceKey]) && floatval($season[$priceKey]) > 0) {
                        $basePrice = floatval($season[$priceKey]);
                        break;
                    }
                }
                
                if ($basePrice <= 0) {
                    continue;
                }
                
                // Get room type from rooms array
                $roomType = '';
                if (isset($rooms[$roomId])) {
                    $roomType = $rooms[$roomId]['Type'] ?? $roomId;
                } else {
                    $roomType = $roomId;
                }
                
                $priceRecord = [
                    'product_id' => $productId,
                    'hotel_id' => $hotelId,
                    'room_id' => $roomId,
                    'room_type' => $roomType,
                    'board_id' => $boardId,
                    'board_name' => $boardId,
                    'star_rating' => $season['IdStar'] ?? '4*',
                    'check_in' => date('Y-m-d'),
                    'check_out' => date('Y-m-d', strtotime('+7 days')),
                    'adults' => ($idAge == 'ADULT') ? 2 : 1,
                    'children' => null,
                    'price' => $basePrice,
                    'currency' => 'EUR',
                    'early_booking_discount' => null,
                    'early_booking_date' => null,
                    'extras' => json_encode([
                        'IdAge' => $idAge,
                        'IdAcc' => $season['IdAcc'] ?? '',
                        'FromDays' => $season['FromDays'] ?? '',
                        'ToDays' => $season['ToDays'] ?? '',
                    ]),
                    'terms_of_payment' => null,
                    'terms_of_cancellation' => null,
                    'remarks' => null,
                    'important_info' => null,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                db_query('INSERT INTO ?:novoton_hotel_prices ?e', $priceRecord);
                $pricesAdded++;
            }
            
            if ($pricesAdded > 0) {
                $stats['updated'][] = $product['product_code'] . ' - ' . $product['product'];
                
                // Clear cache for this hotel to ensure fresh data is served
                $this->clearHotelCache($hotelId);
                
                return true;
            } else {
                $stats['no_data'][] = $product['product_code'] . ' - ' . $product['product'];
                return false;
            }
            
        } catch (\Exception $e) {
            $stats['failed'][] = $product['product_code'] . ' - ' . $product['product'] . ' (Error: ' . $e->getMessage() . ')';
            return false;
        }
    }

    /**
     * Sync all products
     */
    public function syncAllProducts()
    {
        $products = $this->getProductsToSync();
        $totalProducts = count($products);
        
        $stats = [
            'total' => $totalProducts,
            'updated' => [],
            'failed' => [],
            'no_data' => [],
            'missing' => []
        ];
        
        // Create log entry
        $logId = db_query(
            "INSERT INTO ?:novoton_sync_log SET 
             sync_date = NOW(), 
             status = 'running'"
        );
        
        $currentIndex = 0;
        
        foreach ($products as $product) {
            $currentIndex++;
            
            // Update progress
            fn_set_progress('sync_novoton_prices', [
                'current' => $currentIndex,
                'total' => $totalProducts,
                'message' => "Updating $currentIndex of $totalProducts ({$product['product_code']}) - {$product['product']}"
            ]);
            
            $this->syncProductPrices($product['product_id'], $stats);
            
            // Small delay to avoid overwhelming the API
            usleep(500000); // 0.5 seconds
        }
        
        // Check for missing products (in API but not in CS-Cart)
        $this->checkMissingProducts($stats);
        
        // Save log file
        $logFile = $this->saveLogFile($stats);
        
        // Update log entry
        db_query(
            "UPDATE ?:novoton_sync_log SET 
             products_updated = ?i,
             products_failed = ?i,
             products_no_data = ?i,
             products_missing = ?i,
             log_file = ?s,
             status = 'completed'
             WHERE log_id = ?i",
            count($stats['updated']),
            count($stats['failed']),
            count($stats['no_data']),
            count($stats['missing']),
            $logFile,
            $logId
        );
        
        // Update last sync date in settings
        db_query(
            "UPDATE ?:settings_vendor_values SET value = ?s 
             WHERE object_id = 0 
             AND name = 'last_sync_date' 
             AND object_type = 'A'",
            date('Y-m-d H:i:s')
        );
        
        // Send email report via CS-Cart Mailer
        if (function_exists('fn_novoton_send_import_report_email')) {
            fn_novoton_send_import_report_email([], 'room_price', [
                'added'    => 0,
                'updated'  => count($stats['updated']),
                'skipped'  => count($stats['no_data']),
                'errors'   => count($stats['failed']),
                'duration' => $stats['duration'] ?? 'N/A',
            ], $this->defaultCountry);
        }
        
        return $stats;
    }

    /**
     * Check for products in API but not in CS-Cart
     */
    private function checkMissingProducts(&$stats)
    {
        try {
            $apiHotels = $this->api->getHotelList($this->defaultCountry);
            
            if ($apiHotels && isset($apiHotels->hotelinfo)) {
                foreach ($apiHotels->hotelinfo as $hotelInfo) {
                    $hotelId = (string)$hotelInfo->IdHotel;
                    
                    // Check if product exists
                    $exists = false;
                    foreach ($this->productPrefixes as $prefix) {
                        $productCode = $prefix . $hotelId;
                        $product = db_get_field(
                            "SELECT product_id FROM ?:products WHERE product_code = ?s",
                            $productCode
                        );
                        
                        if ($product) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $stats['missing'][] = $hotelId . ' - ' . (string)$hotelInfo->Hotel;
                    }
                }
            }
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Error checking missing products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Save log file
     */
    private function saveLogFile($stats)
    {
        $logDir = fn_get_files_dir_path() . 'novoton_logs/';
        
        if (!is_dir($logDir)) {
            fn_mkdir($logDir);
        }
        
        $filename = 'sync_log_' . date('Y-m-d_H-i-s') . '.txt';
        $filepath = $logDir . $filename;
        
        $content = "Novoton Price Sync Report\n";
        $content .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $content .= str_repeat('=', 50) . "\n\n";
        
        $content .= "SUMMARY\n";
        $content .= "Total products: " . $stats['total'] . "\n";
        $content .= "Updated: " . count($stats['updated']) . "\n";
        $content .= "Failed: " . count($stats['failed']) . "\n";
        $content .= "No data: " . count($stats['no_data']) . "\n";
        $content .= "Missing in CS-Cart: " . count($stats['missing']) . "\n\n";
        
        if (!empty($stats['updated'])) {
            $content .= str_repeat('=', 50) . "\n";
            $content .= "UPDATED PRODUCTS\n";
            $content .= str_repeat('=', 50) . "\n";
            foreach ($stats['updated'] as $item) {
                $content .= $item . "\n";
            }
            $content .= "\n";
        }
        
        if (!empty($stats['failed'])) {
            $content .= str_repeat('=', 50) . "\n";
            $content .= "FAILED PRODUCTS\n";
            $content .= str_repeat('=', 50) . "\n";
            foreach ($stats['failed'] as $item) {
                $content .= $item . "\n";
            }
            $content .= "\n";
        }
        
        if (!empty($stats['no_data'])) {
            $content .= str_repeat('=', 50) . "\n";
            $content .= "PRODUCTS WITH NO DATA\n";
            $content .= str_repeat('=', 50) . "\n";
            foreach ($stats['no_data'] as $item) {
                $content .= $item . "\n";
            }
            $content .= "\n";
        }
        
        if (!empty($stats['missing'])) {
            $content .= str_repeat('=', 50) . "\n";
            $content .= "MISSING IN CS-CART (Present in API)\n";
            $content .= str_repeat('=', 50) . "\n";
            foreach ($stats['missing'] as $item) {
                $content .= $item . "\n";
            }
            $content .= "\n";
        }
        
        file_put_contents($filepath, $content);
        
        return $filename;
    }

    // Legacy sendEmailReport() removed — now uses fn_novoton_send_import_report_email()
    // via CS-Cart Mailer with 'novoton_holidays_import_report' template.

    /**
     * Convert XML to JSON string
     */
    private function xmlToJson($xml)
    {
        return json_encode($xml);
    }

    /**
     * Get board name from code
     */
    private function getBoardName($boardCode)
    {
        $boards = [
            'BB' => 'Bed & Breakfast',
            'HB' => 'Half Board',
            'AI' => 'All Inclusive',
            'BO' => 'Bed Only',
            'FB' => 'Full Board',
            'UAI' => 'Ultra All Inclusive'
        ];
        
        return $boards[$boardCode] ?? $boardCode;
    }
    
    /**
     * Clear all cached data for a specific hotel
     * This ensures fresh data is served after manual price sync
     * Only clears live API cache (room_price, hotel_quota) for this hotel
     * Note: priceinfo is stored in database, not cached
     * 
     * @param string $hotelId Hotel ID
     */
    private function clearHotelCache($hotelId)
    {
        // Clear live API cache (room_price, hotel_quota) from database cache table
        db_query(
            "DELETE FROM ?:novoton_cache WHERE cache_key LIKE ?l",
            '%' . $hotelId . '%'
        );
        
        // Clear from file cache if exists
        $cacheDir = DIR_ROOT . '/var/cache/novoton/';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '*' . $hotelId . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        
        // Clear live API cache via NovotonApi
        if ($this->api && method_exists($this->api, 'clearCache')) {
            $this->api->clearCache('room_price');
            $this->api->clearCache('hotel_quota');
        }
    }
    
    /**
     * Clear all Novoton live API cache
     * Note: This only clears cached API responses, not database data
     */
    public static function clearAllCache()
    {
        // Clear database cache (live API responses only)
        db_query("DELETE FROM ?:novoton_cache WHERE cache_key LIKE 'nvt_api_%'");
        
        // Clear file cache
        $cacheDir = DIR_ROOT . '/var/cache/novoton/';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . 'nvt_api_*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        
        return true;
    }
}