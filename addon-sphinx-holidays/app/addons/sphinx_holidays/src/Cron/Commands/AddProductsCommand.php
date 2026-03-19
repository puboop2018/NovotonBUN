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

        // Parse params: country=GR, limit=100
        $countryCode = $params['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);

        // Country code validation: load valid CS-Cart country codes (read-only from ?:countries)
        $validCountryCodes = db_get_hash_single_array(
            "SELECT code, code FROM ?:countries WHERE status = 'A'",
            ['code', 'code']
        );

        $hotels = $hotelRepo->findUnlinked($countryCode, $limit);
        $this->output("Found " . count($hotels) . " unlinked hotels.");

        $stats = [
            'added'           => 0,
            'skipped'         => 0,
            'failed'          => 0,
            'invalid_country' => 0,
            'total'           => count($hotels),
        ];

        foreach ($hotels as $hotel) {
            $hotelId = $hotel['hotel_id'];
            $productCode = 'SPX' . $hotelId;

            // Country code validation — skip hotels with unrecognized codes
            $cc = $hotel['country_code'] ?? '';
            if (!empty($cc) && !isset($validCountryCodes[$cc])) {
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
            $categoryId = fn_travel_core_get_or_create_category($path);
            if (!$categoryId) {
                $this->output("[{$hotelId}] {$hotel['name']} ... FAILED (category)");
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
                $this->output("[{$hotelId}] {$hotel['name']} ... FAILED (product creation)");
                $stats['failed']++;
                continue;
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
