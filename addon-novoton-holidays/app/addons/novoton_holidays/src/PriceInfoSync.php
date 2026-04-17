<?php

declare(strict_types=1);

/**
 * Novoton PriceInfo Synchronization Class
 * Path: app/addons/novoton_holidays/src/PriceInfoSync.php
 *
 * V3 Architecture: Syncs priceinfo data from API to novoton_hotel_packages table
 * Stores seasons, prices, and early booking discounts as JSON.
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;

class PriceInfoSync
{
    private NovotonApiKitInterface $api;
    private string $defaultCountry;
    /** @var list<string> */
    private array $productPrefixes;

    /**
     * Inject the kit interface so business calls can only reach the
     * five domain sub-clients. Defaults to a fresh NovotonApi instance
     * to preserve the legacy self-construction behaviour.
     */
    public function __construct(?NovotonApiKitInterface $api = null)
    {
        $this->api = $api ?? new NovotonApi();
        $this->defaultCountry = ConfigProvider::getDefaultCountry();
        $this->productPrefixes = array_values(ConfigProvider::getProductCodePrefixes());
    }

    /**
     * Get products to sync based on prefix
     * @return array<string, mixed>
     */
    private function getProductsToSync(): array
    {
        $prefixConditions = [];
        foreach ($this->productPrefixes as $prefix) {
            $prefixConditions[] = db_quote('product_code LIKE ?l', $prefix . '%');
        }

        if (empty($prefixConditions)) {
            return [];
        }

        $condition = implode(' OR ', $prefixConditions);

        return db_get_array(
            'SELECT p.product_id, pd.product, p.product_code, p.status
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE (' . $condition . ")
             AND p.status = 'A'",
            CART_LANGUAGE,
        );
    }

    /**
     * Extract hotel ID from product
     * @param array<string, mixed> $product
     */
    private function getHotelIdFromProduct(array $product): ?string
    {
        // Try to get from novoton_hotels table
        $hotelId = PriceInfoFormatter::toScalar(db_get_field(
            'SELECT hotel_id FROM ?:novoton_hotels WHERE product_id = ?i LIMIT 1',
            PriceInfoFormatter::toInt($product['product_id'] ?? 0),
        ));

        if (empty($hotelId)) {
            // Try to extract from product code (e.g., NVT442)
            // Strip known prefixes first, then take trailing digits
            $code = PriceInfoFormatter::toScalar($product['product_code'] ?? '');
            foreach ($this->productPrefixes as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    $code = substr($code, strlen($prefix));
                    break;
                }
            }
            preg_match('/^(\d+)/', $code, $matches);
            $hotelId = $matches[1] ?? null;
        }

        return $hotelId ?: null;
    }

    /**
     * Sync priceinfo for a single product
     * V3: Writes priceinfo to novoton_hotel_packages table
     * @param array{total: int, updated: list<string>, failed: list<string>, no_data: list<string>, missing: list<string>, errors?: list<string>, duration?: string} $stats
     */
    public function syncProductPrices(int $productId, array &$stats): bool
    {
        /** @var array<string, mixed>|null $product */
        $product = db_get_row(
            'SELECT p.product_id, pd.product, p.product_code
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE p.product_id = ?i',
            CART_LANGUAGE,
            $productId,
        );

        if (empty($product) || !is_array($product)) {
            $stats['errors'][] = "Product ID $productId not found";
            return false;
        }

        $hotelId = $this->getHotelIdFromProduct($product);
        $productCode = PriceInfoFormatter::toScalar($product['product_code'] ?? '');
        $productName = PriceInfoFormatter::toScalar($product['product'] ?? '');

        if (empty($hotelId)) {
            $stats['no_data'][] = $productCode . ' - ' . $productName;
            return false;
        }

        try {
            // Get hotel info (for packages)
            $hotelInfo = $this->api->hotels()->getHotelInfo($hotelId);

            if (!$hotelInfo || !isset($hotelInfo->packages)) {
                $stats['no_data'][] = $productCode . ' - ' . $productName;
                return false;
            }

            // Convert to array
            /** @var array<string, mixed>|null $hotelData */
            $hotelData = json_decode((string) json_encode($hotelInfo), true);
            if (!is_array($hotelData)) {
                $hotelData = [];
            }

            // Normalize packages array
            if (isset($hotelData['packages']) && is_array($hotelData['packages']) && isset($hotelData['packages']['IdCont'])) {
                $hotelData['packages'] = [$hotelData['packages']];
            }

            if (empty($hotelData['packages']) || !is_array($hotelData['packages'])) {
                $stats['no_data'][] = $productCode . ' - ' . $productName;
                return false;
            }

            $packagesUpdated = 0;

            // V3: Process each package and store priceinfo
            foreach ($hotelData['packages'] as $pkg) {
                if (!is_array($pkg)) {
                    continue;
                }
                $packageId = PriceInfoFormatter::toScalar($pkg['IdCont'] ?? '');
                $packageName = PriceInfoFormatter::toScalar($pkg['PackageName'] ?? '');

                if (empty($packageId) || empty($packageName)) {
                    continue;
                }

                // Get price info for this package
                $priceInfo = $this->api->pricing()->getPriceInfo($hotelId, $packageName);

                if (empty($priceInfo)) {
                    continue;
                }

                // Convert to array for JSON storage
                $priceData = json_decode((string) json_encode($priceInfo), true);

                // V3: Save raw data to novoton_hotel_packages table
                // Flag for recomputation by compute_prices cron
                $priceinfoJson = json_encode($priceData);
                $now = date('Y-m-d H:i:s');

                // Atomic upsert — avoids SELECT-then-INSERT/UPDATE race condition
                db_query(
                    "INSERT INTO ?:novoton_hotel_packages
                     (hotel_id, package_id, package_name, priceinfo_data, needs_price_compute, synced_at)
                     VALUES (?s, ?s, ?s, ?s, 'Y', ?s) AS new_row
                     ON DUPLICATE KEY UPDATE
                     package_name = new_row.package_name,
                     priceinfo_data = new_row.priceinfo_data,
                     needs_price_compute = 'Y',
                     synced_at = new_row.synced_at",
                    $hotelId,
                    $packageId,
                    $packageName,
                    $priceinfoJson,
                    $now,
                );

                $packagesUpdated++;
            }

            // Update hotel's packages_count (has_room_price is set exclusively by room_price check)
            if ($packagesUpdated > 0) {
                db_query(
                    'UPDATE ?:novoton_hotels SET packages_count = ?i WHERE hotel_id = ?s',
                    $packagesUpdated,
                    $hotelId,
                );

                $stats['updated'][] = $productCode . ' - ' . $productName;
                $this->clearHotelCache($hotelId);

                return true;
            } else {
                $stats['no_data'][] = $productCode . ' - ' . $productName;
                return false;
            }
        } catch (ApiException $e) {
            $stats['failed'][] = $productCode . ' - ' . $productName . ' (API error HTTP ' . $e->getHttpCode() . ': ' . $e->getMessage() . ')';
            return false;
        } catch (XmlParsingException $e) {
            $stats['failed'][] = $productCode . ' - ' . $productName . ' (XML error: ' . $e->getMessage() . ')';
            return false;
        } catch (\Throwable $e) {
            $stats['failed'][] = $productCode . ' - ' . $productName . ' (Error: ' . $e->getMessage() . ')';
            return false;
        }
    }

    /**
     * Sync all products
     * @return array<string, mixed>
     */
    public function syncAllProducts(): array
    {
        $products = $this->getProductsToSync();
        $totalProducts = count($products);

        $stats = [
            'total' => $totalProducts,
            'updated' => [],
            'failed' => [],
            'no_data' => [],
            'missing' => [],
        ];

        // Create log entry
        $logId = db_query(
            "INSERT INTO ?:novoton_sync_log SET sync_date = NOW(), status = 'running'",
        );

        $currentIndex = 0;

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $currentIndex++;

            $pCode = PriceInfoFormatter::toScalar($product['product_code'] ?? '');
            $pName = PriceInfoFormatter::toScalar($product['product'] ?? '');
            // Update progress
            fn_set_progress('sync_novoton_prices', [
                'current' => $currentIndex,
                'total' => $totalProducts,
                'message' => "Updating $currentIndex of $totalProducts ({$pCode}) - {$pName}",
            ]);

            $this->syncProductPrices(PriceInfoFormatter::toInt($product['product_id'] ?? 0), $stats);

            // Small delay to avoid overwhelming the API
            usleep(Constants::API_DELAY_BACKOFF);
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
            $logId,
        );

        // Send email report
        fn_novoton_holidays_send_import_report_email([], 'room_price', [
            'added' => 0,
            'updated' => count($stats['updated']),
            'skipped' => count($stats['no_data']),
            'errors' => count($stats['failed']),
            'duration' => $stats['duration'] ?? 'N/A',
        ], $this->defaultCountry);

        return $stats;
    }

    /**
     * Check for products in API but not in CS-Cart
     * Optimized: Fetches all matching products once instead of querying per hotel
     * @param array{total: int, updated: list<string>, failed: list<string>, no_data: list<string>, missing: list<string>, errors?: list<string>, duration?: string} $stats
     */
    private function checkMissingProducts(array &$stats): void
    {
        try {
            $apiHotels = $this->api->hotels()->getHotelList($this->defaultCountry);

            if ($apiHotels && isset($apiHotels->hotelinfo)) {
                // Build LIKE conditions for all prefixes and fetch all matching products at once
                $likeConditions = [];
                foreach ($this->productPrefixes as $prefix) {
                    $likeConditions[] = db_quote('product_code LIKE ?l', $prefix . '%');
                }

                // Single query to get all product codes matching any prefix
                $existingProducts = [];
                if (!empty($likeConditions)) {
                    $productCodes = db_get_fields(
                        'SELECT product_code FROM ?:products WHERE ' . implode(' OR ', $likeConditions),
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
                'message' => 'API error checking missing products (HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Error checking missing products: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Save log file
     * @param array<string, mixed> $stats
     */
    private function saveLogFile(array $stats): string
    {
        $logDir = fn_get_files_dir_path() . 'novoton_logs/';

        if (!is_dir($logDir)) {
            fn_mkdir($logDir);
        }

        $filename = 'priceinfo_sync_' . date('Y-m-d_H-i-s') . '.txt';
        $filepath = $logDir . $filename;

        $content = "Novoton PriceInfo Sync Report (V3)\n";
        $content .= 'Date: ' . date('Y-m-d H:i:s') . "\n";
        $content .= str_repeat('=', 50) . "\n\n";

        $content .= "SUMMARY\n";
        $content .= 'Total products: ' . $stats['total'] . "\n";
        $content .= 'Updated: ' . count($stats['updated']) . "\n";
        $content .= 'Failed: ' . count($stats['failed']) . "\n";
        $content .= 'No data: ' . count($stats['no_data']) . "\n";
        $content .= 'Missing in CS-Cart: ' . count($stats['missing']) . "\n\n";

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

        if (file_put_contents($filepath, $content) === false) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton: Failed to write sync log file',
                'filepath' => $filepath,
            ]);
        }

        return $filename;
    }

    /**
     * Clear cached data for a specific hotel.
     *
     * Uses index-friendly prefix matching (no leading wildcards) by leveraging
     * the cache key format: nvt_api_{function}_{hotelId}_{hash}
     */
    private function clearHotelCache(string $hotelId): void
    {
        // Hotel-related API functions whose cache should be invalidated
        $functions = [
            Constants::API_FUNCTION_ROOM_PRICE,      // room_price
            Constants::API_FUNCTION_HOTEL_QUOTA,      // hotel_quota
            Constants::API_FUNCTION_HOTEL_QUOTA_ADD,  // hotel_quota_add
            Constants::API_FUNCTION_HOTEL_INFO,       // hotelinfo
            Constants::API_FUNCTION_HOTEL_DESCRIPTION,// hotel_description
            Constants::API_FUNCTION_HOTEL_IMAGES,     // hotel_images
            Constants::API_FUNCTION_PRICE_INFO,       // priceinfo
            Constants::API_FUNCTION_SPECIAL_OFFERS,   // spo
            Constants::API_FUNCTION_HOTEL_FACILITIES, // hotel_facilities
        ];

        // Index-friendly prefix matching: nvt_api_{function}_{hotelId}_%
        foreach ($functions as $fn) {
            db_query(
                'DELETE FROM ?:novoton_cache WHERE cache_key LIKE ?l',
                'nvt_api_' . $fn . '_' . $hotelId . '_%',
            );
        }

        // Clear from sharded file cache
        $cacheDir = DIR_ROOT . '/var/cache/novoton/';
        if (is_dir($cacheDir)) {
            foreach ($functions as $fn) {
                $safeHotelId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $hotelId);
                $prefix = 'nvt_api_' . $fn . '_' . $safeHotelId . '_';
                $shard = substr($prefix, 0, 2);
                foreach (glob($cacheDir . $shard . '/' . $prefix . '*.cache') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }

        // Clear live API cache via NovotonApi. clearCache() is an
        // infrastructure method not exposed on the kit interface, so
        // we re-query the concrete facade singleton for this call.
        // The cache backend is shared, so the effect is identical.
        $concrete = fn_novoton_holidays_get_api();
        if ($concrete) {
            $concrete->clearCache('room_price');
            $concrete->clearCache('hotel_quota');
        }
    }

    /**
     * Clear all Novoton API cache
     */
    public static function clearAllCache(): bool
    {
        db_query("DELETE FROM ?:novoton_cache WHERE cache_key LIKE 'nvt_api_%'");

        $cacheDir = DIR_ROOT . '/var/cache/novoton/';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*/nvt_api_*.cache') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        return true;
    }
}
