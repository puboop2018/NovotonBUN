<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
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
class UpdateProductsCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['update_products'];
    }

    private const int BATCH_SIZE = 200;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Update CS-Cart products when Sphinx hotel data changes';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $this->seedFeatureMappings();

        $featureAssigner = Container::getFeatureAssigner();
        $countryCode = TypeCoerce::toString($params['country'] ?? '');
        $limit = TypeCoerce::toInt($params['limit'] ?? 0);
        $batchSize = TypeCoerce::toInt($params['batch_size'] ?? self::BATCH_SIZE);

        $stats = [
            'updated' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        $this->output('Updating CS-Cart products with changed hotel data...');

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
                $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
                $hotelName = TypeCoerce::toString($hotel['name'] ?? '');

                // Use configured languages (addon setting) instead of all active
                $configuredLanguages = array_map(strval(...), ConfigProvider::getProductLanguages());
                $primaryLang = $configuredLanguages !== []
                    ? $configuredLanguages[0]
                    : TypeCoerce::toString(CART_LANGUAGE);
                $otherLanguages = array_values(array_diff($configuredLanguages, [$primaryLang]));
                $shortDescription = TypeCoerce::toString($hotel['short_description'] ?? '');

                // Render PER LANGUAGE: each configured language resolves its own
                // template set (fill-if-empty checks its own current values) —
                // the old path replicated the primary render verbatim.
                $placeholders = \Tygh\Addons\SphinxHolidays\Helpers\SphinxProductFactory::buildPlaceholders($hotel);
                $seoFields = fn_travel_core_apply_seo_fields('sphinx_holidays', $placeholders, $productId, $hotelId, $primaryLang);
                $wroteOthers = fn_travel_core_seo_localize(
                    'sphinx_holidays',
                    $placeholders,
                    $productId,
                    $hotelId,
                    $otherLanguages,
                    ['short_description' => $shortDescription],
                );

                if (empty($seoFields) && !$wroteOthers) {
                    // All fields skipped (fill_if_empty and all already filled, or all toggles off)
                    db_query("UPDATE ?:sphinx_hotels SET product_needs_update = 'N' WHERE hotel_id = ?s", $hotelId);
                    $stats['skipped'] = ($stats['skipped'] ?? 0) + 1;
                    continue;
                }

                $result = true;
                if ($seoFields !== []) {
                    $result = fn_update_product(
                        array_merge(['short_description' => $shortDescription], $seoFields),
                        $productId,
                        $primaryLang,
                    );
                }
                if (!$result) {
                    $this->output("[{$hotelId}] {$hotelName} ... FAILED (product update)");
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
                    $hotelId,
                );

                $this->output("[{$hotelId}] {$hotelName} ... UPDATED");
                $stats['updated']++;
            }

            $processed += count($hotels);
        }

        FeatureMapper::clearCache();

        $this->output("Done: {$stats['updated']} updated, {$stats['failed']} failed out of {$stats['total']} total.");

        return [
            'success' => true,
            'stats' => [
                'total' => $stats['total'],
                'synced' => $stats['updated'],
                'failed' => $stats['failed'],
            ],
        ];
    }

    /**
     * Find hotels that have product_needs_update = 'Y' and a linked product.
     * @return list<array<string, mixed>>
     */
    private function findHotelsNeedingUpdate(string $countryCode, int $limit): array
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        return TypeCoerce::toRowList(db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.classification, h.property_type,
                    h.description, h.short_description, h.destination_name,
                    h.country_name, h.region_name, h.rating, h.latitude, h.longitude,
                    h.facilities_json, h.boards_json
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND h.product_id IS NOT NULL AND h.product_id > 0
               AND h.product_needs_update = 'Y' ?p
             ORDER BY h.country_code ASC, h.hotel_id ASC ?p",
            $condition,
            $limitClause,
        ));
    }
}
