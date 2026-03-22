<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Registry;
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
    /** @var callable|null */
    private $outputCallback = null;

    private const BATCH_SIZE = 200;

    public static function getDescription(): string
    {
        return 'Update CS-Cart products when Sphinx hotel data changes';
    }

    public function setOutputCallback(callable $callback): void
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

                $pageTitle = $hotel['name'] . ($hotel['destination_name'] ? ' - ' . $hotel['destination_name'] : '');

                $product_data = [
                    'product'           => $hotel['name'],
                    'full_description'  => $hotel['description'] ?? '',
                    'short_description' => $hotel['short_description'] ?? '',
                    'page_title'        => $pageTitle,
                ];

                $result = fn_update_product($product_data, $productId, CART_LANGUAGE);

                // Ensure all active languages have the same descriptions
                // (Sphinx API provides one language; replicate to all CS-Cart languages)
                if ($result) {
                    $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A' AND lang_code != ?s", CART_LANGUAGE);
                    foreach ($languages as $lc) {
                        db_query(
                            "INSERT INTO ?:product_descriptions (product_id, lang_code, product, full_description, short_description, page_title)
                             VALUES (?i, ?s, ?s, ?s, ?s, ?s)
                             ON DUPLICATE KEY UPDATE product = ?s, full_description = ?s, short_description = ?s, page_title = ?s",
                            $productId, $lc,
                            $hotel['name'], $hotel['description'] ?? '', $hotel['short_description'] ?? '', $pageTitle,
                            $hotel['name'], $hotel['description'] ?? '', $hotel['short_description'] ?? '', $pageTitle
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
                    h.facilities_json, h.boards_json
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND h.product_id IS NOT NULL AND h.product_id > 0
               AND h.product_needs_update = 'Y' ?p
             ORDER BY h.country_code ASC, h.hotel_id ASC ?p",
            $condition, $limitClause
        );
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
