<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Cron command: update existing CS-Cart products when Sphinx API data changes.
 *
 * Finds hotels where product_needs_update = 'Y' (set automatically by upsertBatch
 * when name, description, classification, or images change) and pushes the new
 * data into the linked CS-Cart product via fn_update_product().
 *
 * Usage: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=update_products
 */
class UpdateProductsCommand
{
    private ?\Closure $outputCallback = null;

    private const BATCH_SIZE = 200;

    public static function getDescription(): string
    {
        return 'Update CS-Cart products when Sphinx hotel data changes';
    }

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $featureAssigner = Container::getFeatureAssigner();
        $countryCode = $params['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);
        $batchSize = (int) ($params['batch_size'] ?? self::BATCH_SIZE);

        $stats = [
            'updated' => 0,
            'failed'  => 0,
            'total'   => 0,
        ];

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        $this->output("Updating CS-Cart products with changed hotel data...");

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $this->findHotelsNeedingUpdate($countryCode, min($remaining, $effectiveBatch));
            if (empty($hotels)) {
                break;
            }

            $stats['total'] += count($hotels);

            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'];
                $productId = (int) $hotel['product_id'];

                // Build placeholder map for SEO templates
                $placeholders = self::buildPlaceholders($hotel);

                // Resolve full description: use template if configured, otherwise raw API description
                $descTemplate = ConfigProvider::getSeoFullDescription();
                $fullDescription = $descTemplate !== ''
                    ? fn_travel_core_render_seo_template($descTemplate, $placeholders)
                    : ($hotel['description'] ?? '');

                $product_data = [
                    'product'           => fn_travel_core_render_seo_template(ConfigProvider::getSeoProductName(), $placeholders) ?: ($hotel['name'] ?? 'Hotel'),
                    'full_description'  => $fullDescription,
                    'short_description' => $hotel['short_description'] ?? '',
                    'page_title'        => fn_travel_core_render_seo_template(ConfigProvider::getSeoPageTitle(), $placeholders),
                    'meta_description'  => fn_travel_core_render_seo_template(ConfigProvider::getSeoMetaDescription(), $placeholders),
                    'meta_keywords'     => fn_travel_core_render_seo_template(ConfigProvider::getSeoMetaKeywords(), $placeholders),
                    'seo_name'          => fn_travel_core_render_seo_slug(ConfigProvider::getSeoNameSlug(), $placeholders),
                ];

                // Use configured languages (addon setting) instead of all active
                $configuredLanguages = ConfigProvider::getProductLanguages();
                $primaryLang = !empty($configuredLanguages) ? $configuredLanguages[0] : CART_LANGUAGE;

                $result = fn_update_product($product_data, $productId, $primaryLang);

                // Replicate descriptions to other configured languages
                if ($result) {
                    $otherLanguages = array_diff($configuredLanguages, [$primaryLang]);
                    foreach ($otherLanguages as $lc) {
                        db_query(
                            "INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description, short_description, page_title, meta_description, meta_keywords)
                             VALUES (?i, ?s, ?s, ?s, ?s, ?s, ?s, ?s)
                             ON DUPLICATE KEY UPDATE product = ?s, full_description = ?s, short_description = ?s, page_title = ?s, meta_description = ?s, meta_keywords = ?s",
                            $productId, $lc,
                            $product_data['product'], $fullDescription, $hotel['short_description'] ?? '', $product_data['page_title'], $product_data['meta_description'], $product_data['meta_keywords'],
                            $product_data['product'], $fullDescription, $hotel['short_description'] ?? '', $product_data['page_title'], $product_data['meta_description'], $product_data['meta_keywords']
                        );
                    }
                }
                if (!$result) {
                    $this->output("[{$hotelId}] {$hotel['name']} ... FAILED (product update)");
                    $stats['failed']++;
                    continue;
                }

                // Re-assign features (star rating, property type, etc.)
                try {
                    $featureAssigner->assignAll($productId, $hotel);
                } catch (\Throwable $e) {
                    fn_log_event('general', 'runtime', [
                        'message' => "Sphinx: feature re-assignment failed for hotel {$hotelId}: " . $e->getMessage(),
                    ]);
                }

                // Clear the update flag
                db_query(
                    "UPDATE ?:sphinx_hotels SET product_needs_update = 'N' WHERE hotel_id = ?s",
                    $hotelId
                );

                $this->output("[{$hotelId}] {$hotel['name']} ... UPDATED");
                $stats['updated']++;
            }

            $processed += count($hotels);
        }

        FeatureMapper::clearCache();

        $this->output("Done: {$stats['updated']} updated, {$stats['failed']} failed out of {$stats['total']} total.");

        return [
            'success' => true,
            'stats'   => [
                'total'  => $stats['total'],
                'synced' => $stats['updated'],
                'failed' => $stats['failed'],
            ],
        ];
    }

    /**
     * Find hotels that have product_needs_update = 'Y' and a linked product.
     */
    private function findHotelsNeedingUpdate(string $countryCode, int $limit): array
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(" AND h.country_code = ?s", $countryCode);
        }

        $limitClause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';

        return db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.classification, h.property_type,
                    h.description, h.short_description, h.destination_name,
                    h.country_name, h.region_name, h.rating, h.latitude, h.longitude,
                    h.facilities_json, h.boards_json
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND h.product_id IS NOT NULL AND h.product_id > 0
               AND h.product_needs_update = 'Y' ?p
             ORDER BY h.country_code ASC, h.hotel_id ASC ?p",
            $condition, $limitClause
        );
    }

    /**
     * Build the placeholder map for SEO template rendering.
     */
    private static function buildPlaceholders(array $hotel): array
    {
        // Extract facility names from JSON
        $facilities = [];
        if (!empty($hotel['facilities_json'])) {
            $facilitiesData = is_string($hotel['facilities_json']) ? json_decode($hotel['facilities_json'], true) : $hotel['facilities_json'];
            if (is_array($facilitiesData)) {
                foreach ($facilitiesData as $f) {
                    $name = is_array($f) ? ($f['name'] ?? $f['title'] ?? '') : (string) $f;
                    if ($name !== '') {
                        $facilities[] = $name;
                    }
                }
            }
        }

        // Extract board names from JSON
        $boards = [];
        if (!empty($hotel['boards_json'])) {
            $boardsData = is_string($hotel['boards_json']) ? json_decode($hotel['boards_json'], true) : $hotel['boards_json'];
            if (is_array($boardsData)) {
                $boards = array_map('strval', $boardsData);
            }
        }

        return [
            'name'           => $hotel['name'] ?? '',
            'classification' => $hotel['classification'] ?? '',
            'city'           => $hotel['destination_name'] ?? '',
            'country'        => $hotel['country_name'] ?? '',
            'region'         => $hotel['region_name'] ?? '',
            'property_type'  => $hotel['property_type'] ?? 'hotel',
            'description'    => $hotel['description'] ?? '',
            'rating'         => $hotel['rating'] ?? '',
            'facilities'     => $facilities,
            'boards'         => $boards,
            'latitude'       => $hotel['latitude'] ?? '',
            'longitude'      => $hotel['longitude'] ?? '',
        ];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
