<?php
/**
 * Novoton PriceInfo Synchronization Class
 * Path: app/addons/novoton_holidays/src/PriceInfoSync.php
 *
 * V3 Architecture: Syncs priceinfo data from API to novoton_hotel_packages table
 * Stores seasons, prices, and early booking discounts as JSON.
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class PriceInfoSync
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

        return db_get_array(
            "SELECT p.product_id, pd.product, p.product_code, p.status
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE (" . $condition . ")
             AND p.status = 'A'",
            CART_LANGUAGE
        );
    }

    /**
     * Extract hotel ID from product
     */
    private function getHotelIdFromProduct($product)
    {
        // Try to get from novoton_hotels table
        $hotelId = db_get_field(
            "SELECT hotel_id FROM ?:novoton_hotels WHERE product_id = ?i LIMIT 1",
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
     * Sync priceinfo for a single product
     * V3: Writes priceinfo to novoton_hotel_packages table
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

            $packagesUpdated = 0;

            // V3: Process each package and store priceinfo
            foreach ($hotelData['packages'] as $pkg) {
                $packageId = $pkg['IdCont'] ?? '';
                $packageName = $pkg['PackageName'] ?? '';

                if (empty($packageId) || empty($packageName)) {
                    continue;
                }

                // Get price info for this package
                $priceInfo = $this->api->getPriceInfo($hotelId, $packageName);

                if (empty($priceInfo)) {
                    continue;
                }

                // Convert to array
                $priceData = json_decode(json_encode($priceInfo), true);

                // Calculate metadata
                $hasEarlyBooking = !empty($priceData['early_booking']) ? 'Y' : 'N';
                $seasonsCount = 0;
                $minPrice = null;

                // Count seasons
                if (isset($priceData['seasons']['season'])) {
                    $seasons = $priceData['seasons']['season'];
                    $seasonsCount = isset($seasons['IdSeason']) ? 1 : count($seasons);
                }

                // Find minimum price
                if (isset($priceData['season_price'])) {
                    $seasonPrices = $priceData['season_price'];
                    if (isset($seasonPrices['Code'])) {
                        $seasonPrices = [$seasonPrices];
                    }

                    foreach ($seasonPrices as $sp) {
                        for ($i = 1; $i <= 20; $i++) {
                            $priceKey = 'Price' . $i;
                            if (isset($sp[$priceKey]) && floatval($sp[$priceKey]) > 0) {
                                $price = floatval($sp[$priceKey]);
                                if ($minPrice === null || $price < $minPrice) {
                                    $minPrice = $price;
                                }
                            }
                        }
                    }
                }

                // V3: Save to novoton_hotel_packages table
                $packageData = [
                    'hotel_id' => $hotelId,
                    'package_id' => $packageId,
                    'package_name' => $packageName,
                    'priceinfo_data' => json_encode($priceData),
                    'seasons_count' => $seasonsCount,
                    'has_early_booking' => $hasEarlyBooking,
                    'min_price' => $minPrice,
                    'synced_at' => date('Y-m-d H:i:s')
                ];

                // Check if package exists
                $existingId = db_get_field(
                    "SELECT id FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
                    $hotelId,
                    $packageId
                );

                if ($existingId) {
                    db_query("UPDATE ?:novoton_hotel_packages SET ?u WHERE id = ?i", $packageData, $existingId);
                } else {
                    db_query("INSERT INTO ?:novoton_hotel_packages ?e", $packageData);
                }

                $packagesUpdated++;
            }

            // Update hotel's has_prices flag and packages_count
            if ($packagesUpdated > 0) {
                db_query(
                    "UPDATE ?:novoton_hotels SET has_prices = 'Y', packages_count = ?i WHERE hotel_id = ?s",
                    $packagesUpdated,
                    $hotelId
                );

                $stats['updated'][] = $product['product_code'] . ' - ' . $product['product'];
                $this->clearHotelCache($hotelId);

                return true;
            } else {
                $stats['no_data'][] = $product['product_code'] . ' - ' . $product['product'];
                return false;
            }

        } catch (ApiException $e) {
            $stats['failed'][] = $product['product_code'] . ' - ' . $product['product'] . ' (API error HTTP ' . $e->getHttpCode() . ': ' . $e->getMessage() . ')';
            return false;
        } catch (XmlParsingException $e) {
            $stats['failed'][] = $product['product_code'] . ' - ' . $product['product'] . ' (XML error: ' . $e->getMessage() . ')';
            return false;
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
            "INSERT INTO ?:novoton_sync_log SET sync_date = NOW(), status = 'running'"
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
             WHERE object_id = 0 AND name = 'last_sync_date' AND object_type = 'A'",
            date('Y-m-d H:i:s')
        );

        // Send email report
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
     * Optimized: Fetches all matching products once instead of querying per hotel
     */
    private function checkMissingProducts(&$stats)
    {
        try {
            $apiHotels = $this->api->getHotelList($this->defaultCountry);

            if ($apiHotels && isset($apiHotels->hotelinfo)) {
                // Build LIKE conditions for all prefixes and fetch all matching products at once
                $likeConditions = [];
                foreach ($this->productPrefixes as $prefix) {
                    $likeConditions[] = db_quote("product_code LIKE ?l", $prefix . '%');
                }

                // Single query to get all product codes matching any prefix
                $existingProducts = [];
                if (!empty($likeConditions)) {
                    $productCodes = db_get_fields(
                        "SELECT product_code FROM ?:products WHERE " . implode(' OR ', $likeConditions)
                    );
                    $existingProducts = array_flip($productCodes);
                }

                // Check each API hotel against the pre-fetched list
                foreach ($apiHotels->hotelinfo as $hotelInfo) {
                    $hotelId = (string)$hotelInfo->IdHotel;

                    $exists = false;
                    foreach ($this->productPrefixes as $prefix) {
                        if (isset($existingProducts[$prefix . $hotelId])) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $stats['missing'][] = $hotelId . ' - ' . (string)$hotelInfo->Hotel;
                    }
                }
            }
        } catch (ApiException $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'API error checking missing products (HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage()
            ]);
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

        $filename = 'priceinfo_sync_' . date('Y-m-d_H-i-s') . '.txt';
        $filepath = $logDir . $filename;

        $content = "Novoton PriceInfo Sync Report (V3)\n";
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

    /**
     * Clear cached data for a specific hotel
     */
    private function clearHotelCache($hotelId)
    {
        // Clear live API cache from database
        db_query("DELETE FROM ?:novoton_cache WHERE cache_key LIKE ?l", '%' . $hotelId . '%');

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
     * Clear all Novoton API cache
     */
    public static function clearAllCache()
    {
        db_query("DELETE FROM ?:novoton_cache WHERE cache_key LIKE 'nvt_api_%'");

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
