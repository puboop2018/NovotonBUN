<?php
/**
 * Novoton Holidays - Cron Helper
 *
 * Consolidates common cron operations:
 * - Authentication
 * - Header/footer output
 * - API initialization
 * - Product creation from hotel data
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\NovotonApi;

class CronHelper
{
    /**
     * Validate cron access key
     *
     * @param string $providedKey Key from request
     * @return bool
     */
    public static function validateAccessKey(string $providedKey): bool
    {
        $storedKey = Config::getCronAccessKey();

        if (empty($storedKey)) {
            return false;
        }

        return !empty($providedKey) && $providedKey === $storedKey;
    }

    /**
     * Send authentication error response
     *
     * @param string $message Error message
     */
    public static function sendAuthError(string $message): void
    {
        header('Content-Type: text/plain');
        http_response_code(403);
        echo "ERROR: {$message}\n";
        exit;
    }

    /**
     * Initialize cron environment
     *
     * @return array ['api' => NovotonApi, 'logger' => SyncLogger, 'mode' => string]
     */
    public static function initialize(): array
    {
        $mode = $_REQUEST['mode'] ?? 'resinfo';

        header('Content-Type: text/plain; charset=utf-8');

        // Load API
        $srcDir = Config::getPath('src');
        if (file_exists($srcDir . 'NovotonApi.php')) {
            require_once($srcDir . 'NovotonApi.php');
        }

        $api = new NovotonApi();
        $logger = new SyncLogger($mode);

        return [
            'api' => $api,
            'logger' => $logger,
            'mode' => $mode,
        ];
    }

    /**
     * Create CS-Cart product from hotel data
     *
     * @param array $hotel Hotel data array
     * @param NovotonApi $api API instance for fetching additional data
     * @param int $categoryId Category to assign product to
     * @return int|null Product ID or null on failure
     */
    public static function createProductFromHotel(array $hotel, NovotonApi $api, int $categoryId): ?int
    {
        $hotelId = $hotel['hotel_id'];
        $hotelName = $hotel['hotel_name'] ?? '';
        $city = $hotel['city'] ?? '';
        $country = $hotel['country'] ?? '';

        $productCode = DatabaseHelper::getProductCode($hotelId);

        // Check if product already exists
        $existingProductId = db_get_field(
            "SELECT product_id FROM ?:products WHERE product_code = ?s",
            $productCode
        );

        if ($existingProductId) {
            // Link existing product to hotel
            db_query(
                "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
                $existingProductId,
                $hotelId
            );
            return (int)$existingProductId;
        }

        // Build page title
        $currentYear = date('Y');
        $pageTitle = self::buildHotelTitle($hotelName, $city, $country, $currentYear);

        // Fetch description
        $description = '';
        try {
            $descResponse = $api->getHotelDescription($hotelId, 'UK');
            if ($descResponse && isset($descResponse->Description)) {
                $description = (string)$descResponse->Description;
            }
        } catch (\Exception $e) {
            // Ignore description fetch errors
        }

        // Create product
        $productData = [
            'product' => $hotelName,
            'product_code' => $productCode,
            'price' => 0,
            'status' => 'D', // Disabled until prices are synced
            'company_id' => Config::getCompanyId(),
            'main_category' => $categoryId,
            'category_ids' => [$categoryId],
            'full_description' => $description,
            'page_title' => $pageTitle,
            'meta_description' => $pageTitle,
        ];

        $productId = fn_update_product($productData, 0, CART_LANGUAGE);

        if (!$productId) {
            return null;
        }

        // Link product to hotel
        db_query(
            "UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s",
            $productId,
            $hotelId
        );

        // Fetch and attach images
        self::attachHotelImages($productId, $hotelId, $api);

        // Sync facilities
        try {
            if (function_exists('fn_novoton_sync_hotel_facilities')) {
                fn_novoton_sync_hotel_facilities($hotelId);
            }
        } catch (\Exception $e) {
            // Ignore facility sync errors
        }

        return $productId;
    }

    /**
     * Attach images to product from API
     *
     * @param int $productId Product ID
     * @param string $hotelId Hotel ID
     * @param NovotonApi $api API instance
     * @return int Number of images attached
     */
    public static function attachHotelImages(int $productId, string $hotelId, NovotonApi $api): int
    {
        try {
            $imagesResponse = $api->getHotelImages($hotelId);

            if (!$imagesResponse || !isset($imagesResponse->url)) {
                return 0;
            }

            $imgCount = 0;
            $maxImages = Config::MAX_IMAGES_PER_HOTEL;

            foreach ($imagesResponse->url as $url) {
                $imageUrl = Config::IMAGE_BASE_URL . str_replace(' ', '%20', (string)$url);

                if (function_exists('fn_novoton_add_product_image')) {
                    fn_novoton_add_product_image($productId, $imageUrl, $imgCount === 0);
                }

                $imgCount++;
                if ($imgCount >= $maxImages) {
                    break;
                }
            }

            return $imgCount;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Build hotel title for SEO
     *
     * @param string $hotelName
     * @param string $city
     * @param string $country
     * @param string $year
     * @return string
     */
    public static function buildHotelTitle(string $hotelName, string $city, string $country, string $year): string
    {
        if (function_exists('fn_novoton_build_hotel_title')) {
            return fn_novoton_build_hotel_title($hotelName, $city, $country, $year);
        }

        $parts = array_filter([$hotelName, $city, $country, $year]);
        return implode(' - ', $parts);
    }

    /**
     * Get or create category by path
     *
     * @param string $categoryPath Path like "BULGARIA///Litoral BULGARIA"
     * @return int Category ID
     */
    public static function getOrCreateCategory(string $categoryPath): int
    {
        if (function_exists('fn_novoton_get_or_create_category')) {
            return fn_novoton_get_or_create_category($categoryPath);
        }

        // Fallback: just return default category
        return 1;
    }

    /**
     * Parse excluded resorts from request or settings
     *
     * @return array
     */
    public static function getExcludedResorts(): array
    {
        // Check request first
        if (!empty($_REQUEST['exclude_resorts'])) {
            if (is_array($_REQUEST['exclude_resorts'])) {
                return array_filter($_REQUEST['exclude_resorts']);
            }
            return array_filter(array_map('trim', explode(',', $_REQUEST['exclude_resorts'])));
        }

        // Fall back to settings
        return Config::getExcludedResorts();
    }

    /**
     * Print available modes help
     *
     * @param SyncLogger $logger
     */
    public static function printAvailableModes(SyncLogger $logger): void
    {
        $logger->output("Available modes:");
        $logger->output("- hotel_info_batched: [RECOMMENDED] Batched hotel info sync with resume");
        $logger->output("    &status=1          - Check progress");
        $logger->output("    &force_full=1      - Force full sync (all hotels)");
        $logger->output("    &reset=1           - Reset/cancel in-progress sync");
        $logger->output("    &batch_size=100    - Hotels per batch (default: 100)");
        $logger->output("    &max_time=300      - Max seconds per run (default: 300)");
        $logger->output("    &unlimited=1       - No time limit (for CLI PHP usage)");
        $logger->output("- sync_priceinfo_batched: [RECOMMENDED] Batched priceinfo sync with resume");
        $logger->output("    &status=1          - Check progress");
        $logger->output("    &force_full=1      - Force full sync (all packages)");
        $logger->output("    &reset=1           - Reset/cancel in-progress sync");
        $logger->output("    &batch_size=50     - Packages per batch (default: 50)");
        $logger->output("    &max_time=300      - Max seconds per run (default: 300)");
        $logger->output("    &stale_hours=24    - Re-sync packages older than N hours");
        $logger->output("    &unlimited=1       - No time limit (for CLI PHP usage)");
        $logger->output("- hotel_list: Hotel list sync from API");
        $logger->output("- resinfo: Check ASK bookings status");
        $logger->output("- offers_update: Check for new/updated offers (&country=BULGARIA)");
        $logger->output("- add_hotels_as_products: Add hotels as products");
        $logger->output("- list_facilities: Sync facilities list from API");
        $logger->output("- exchange_rates: Update currency rates from BNR (daily)");
        $logger->output("");
        $logger->output("Recommended workflow:");
        $logger->output("  1. hotel_info_batched (every 5 min) - Smart hotel info sync with resume");
        $logger->output("     - First run: syncs all hotels (batched)");
        $logger->output("     - Daily: only new/changed hotels from offers_update");
        $logger->output("     - Every 6 months: automatic full re-sync");
        $logger->output("  2. sync_priceinfo_batched (every 5 min) - Smart priceinfo sync with resume");
        $logger->output("     - First run: syncs all packages (batched)");
        $logger->output("     - Daily: only stale packages (older than 24h)");
        $logger->output("     - Every 7 days: automatic full re-sync");
        $logger->output("  3. list_facilities (weekly) - Sync facilities list");
        $logger->output("  4. exchange_rates (daily) - Update BNR exchange rates");
    }
}
