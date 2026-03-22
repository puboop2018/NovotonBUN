<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Create CS-Cart products from unlinked Sphinx hotels.
 *
 * For each hotel without a product_id:
 * 1. Validate country code against CS-Cart's ?:countries (Shadow Table approach)
 * 2. Create/reuse nested category tree from template path
 * 3. Create CS-Cart product via fn_update_product()
 * 4. Assign product features (star rating, property type, resort, board types)
 * 5. Link hotel to product
 */
class AddProductsCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Create CS-Cart products from unlinked Sphinx hotels';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $hotelRepo = new HotelRepository();
        $featureAssigner = Container::getFeatureAssigner();
        $template = ConfigProvider::getProductCategoryTemplate();

        // Parse params: country=GR, limit=100, batch_size=200
        $countryCode = $params['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);
        $batchSize = (int) ($params['batch_size'] ?? 200);

        // Country code validation: load valid CS-Cart country codes (read-only from ?:countries)
        $validCountryCodes = db_get_hash_single_array(
            "SELECT code, code FROM ?:countries WHERE status = 'A'",
            ['code', 'code']
        );

        $stats = [
            'added'           => 0,
            'skipped'         => 0,
            'failed'          => 0,
            'invalid_country' => 0,
            'total'           => 0,
        ];

        // Category path cache — avoid calling fn_travel_core_get_or_create_category()
        // multiple times for the same path within one run
        $categoryCache = [];

        // Process in batches to avoid OOM on large datasets
        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $hotelRepo->findUnlinked($countryCode, min($remaining, $effectiveBatch));
            if (empty($hotels)) {
                break;
            }

            if ($stats['total'] === 0) {
                $this->output("Processing unlinked hotels in batches of {$effectiveBatch}...");
            }

            $stats['total'] += count($hotels);

        foreach ($hotels as $hotel) {
            $hotelId = $hotel['hotel_id'];
            $productCode = 'SPX' . $hotelId;

            // Country code validation — skip hotels with unrecognized codes
            $cc = $hotel['country_code'] ?? '';
            if (!empty($cc) && !isset($validCountryCodes[$cc])) {
                $hotelRepo->markSkipped($hotelId, 'invalid_country');
                fn_log_event('general', 'runtime', [
                    'message' => "Sphinx: country code '{$cc}' not found in CS-Cart countries. Hotel {$hotelId} skipped.",
                ]);
                $this->output("[{$hotelId}] {$hotel['name']} ... SKIPPED (invalid country: {$cc})");
                $stats['invalid_country']++;
                continue;
            }

            // Check if product already exists (e.g. re-run after partial failure)
            $existingProductId = (int) db_get_field(
                "SELECT product_id FROM ?:products WHERE product_code = ?s",
                $productCode
            );
            if ($existingProductId > 0) {
                $hotelRepo->linkToProduct($hotelId, $existingProductId);
                $this->output("[{$hotelId}] {$hotel['name']} ... LINKED (existing)");
                $stats['skipped']++;
                continue;
            }

            // Build category path from template
            $path = str_replace(
                ['{country}', '{region}', '{city}'],
                [
                    $hotel['country_name'] ?: $hotel['country_code'] ?: 'Other',
                    $hotel['region_name'] ?: 'Other',
                    $hotel['destination_name'] ?: 'Other',
                ],
                $template
            );

            // Use cache to avoid repeated category lookups for the same path
            if (!isset($categoryCache[$path])) {
                $categoryCache[$path] = fn_travel_core_get_or_create_category($path);
            }
            $categoryId = $categoryCache[$path];

            if (!$categoryId) {
                $hotelRepo->markSkipped($hotelId, 'category_failed');
                $this->output("[{$hotelId}] {$hotel['name']} ... FAILED (category: {$path})");
                $stats['failed']++;
                continue;
            }

            // Create CS-Cart product
            $product_data = [
                'product'           => $hotel['name'],
                'product_code'      => $productCode,
                'price'             => 0,
                'status'            => 'A',
                'company_id'        => Registry::get('runtime.company_id') ?: 1,
                'main_category'     => $categoryId,
                'category_ids'      => [$categoryId],
                'full_description'  => $hotel['description'] ?? '',
                'short_description' => $hotel['short_description'] ?? '',
                'page_title'        => $hotel['name'] . ($hotel['destination_name'] ? ' - ' . $hotel['destination_name'] : ''),
            ];

            $productId = (int) fn_update_product($product_data, 0, CART_LANGUAGE);
            if (!$productId) {
                $hotelRepo->markSkipped($hotelId, 'product_creation_failed');
                $this->output("[{$hotelId}] {$hotel['name']} ... FAILED (product creation)");
                $stats['failed']++;
                continue;
            }

            // Ensure all active languages have descriptions
            // (Sphinx API provides one language; replicate to all CS-Cart languages)
            $otherLanguages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A' AND lang_code != ?s", CART_LANGUAGE);
            foreach ($otherLanguages as $lc) {
                db_query(
                    "INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description, short_description, page_title)
                     VALUES (?i, ?s, ?s, ?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE product = ?s, full_description = ?s, short_description = ?s, page_title = ?s",
                    $productId, $lc,
                    $hotel['name'], $hotel['description'] ?? '', $hotel['short_description'] ?? '', $product_data['page_title'],
                    $hotel['name'], $hotel['description'] ?? '', $hotel['short_description'] ?? '', $product_data['page_title']
                );
            }

            // Link hotel → product
            $hotelRepo->linkToProduct($hotelId, $productId);

            // Assign features (non-fatal — log and continue on error)
            try {
                $featureAssigner->assignAll($productId, $hotel);
            } catch (\Throwable $e) {
                fn_log_event('general', 'runtime', [
                    'message' => "Sphinx: feature assignment failed for hotel {$hotelId}: " . $e->getMessage(),
                ]);
            }

            $this->output("[{$hotelId}] {$hotel['name']} ... ADDED (ID: {$productId})");
            $stats['added']++;
        }

            $processed += count($hotels);
        }

        // Clear FeatureMapper cache to free memory
        FeatureMapper::clearCache();

        if ($stats['invalid_country'] > 0) {
            $this->output("WARNING: {$stats['invalid_country']} hotels skipped — country codes not in CS-Cart. Check sync health log.");
        }
        $this->output("Done: {$stats['added']} added, {$stats['skipped']} skipped, {$stats['failed']} failed, {$stats['invalid_country']} invalid country.");
        return ['success' => true, 'stats' => $stats];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
