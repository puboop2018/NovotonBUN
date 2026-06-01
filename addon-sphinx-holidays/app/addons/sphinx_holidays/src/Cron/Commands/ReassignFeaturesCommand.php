<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Cron command: re-assign product features to all linked hotel products.
 *
 * Processes every hotel that already has a CS-Cart product linked, calls
 * SphinxFeatureAssigner::assignAll() for each one, and reports results.
 *
 * Use this after:
 *   - Configuring feature_id_property_rating (Stele) or other feature IDs in Travel Core
 *   - Running feature alias seeding for the first time
 *   - Any time features are empty on existing products
 *
 * Unlike update_products (which only runs on hotels with product_needs_update='Y'),
 * this command processes ALL linked hotels regardless of change flags.
 *
 * Scope filters:
 *   &country=HR            — only hotels with that country_code
 *   &limit=N               — cap total hotels processed
 *   &batch_size=N          — batch size (default 200)
 *
 * Usage:
 *   cron_mode=reassign_features
 *   cron_mode=reassign_features&country=HR
 *   cron_mode=reassign_features&country=HR&limit=100
 */
class ReassignFeaturesCommand extends AbstractSyncCommand
{
    private const int BATCH_SIZE = 200;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Re-assign CS-Cart product features (stars, property type, region…) to all linked hotel products';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int) (microtime(true) * 1000);

        $countryCode = TypeCoerce::toString($params['country'] ?? '');
        $limit = TypeCoerce::toInt($params['limit'] ?? 0);
        $batchSize = TypeCoerce::toInt($params['batch_size'] ?? self::BATCH_SIZE);
        if ($batchSize <= 0) {
            $batchSize = self::BATCH_SIZE;
        }

        $scope = $countryCode !== '' ? "country={$countryCode}" : 'all countries';
        $this->output('Re-assigning product features — scope: ' . $scope);
        $this->output('Seeding feature mappings...');

        $this->seedFeatureMappings();

        $this->output('Seeding done. Processing hotels...');
        $this->output('');

        $featureAssigner = Container::getFeatureAssigner();

        $stats = [
            'processed' => 0,
            'errors' => 0,
            'total' => 0,
        ];

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;
        $lastHotelId = null;

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $this->findLinkedHotels($countryCode, min($remaining, $effectiveBatch), $lastHotelId);
            if (empty($hotels)) {
                break;
            }

            $stats['total'] += count($hotels);

            foreach ($hotels as $hotel) {
                $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
                $hotelName = TypeCoerce::toString($hotel['name'] ?? '');

                if ($productId <= 0) {
                    continue;
                }

                try {
                    $featureAssigner->assignAll($productId, $hotel);
                    $stats['processed']++;
                    $this->output("[{$hotelId}] {$hotelName} (product {$productId}) ... OK");
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->output("[{$hotelId}] {$hotelName} ... ERROR: " . $e->getMessage());
                    fn_log_event('general', 'runtime', [
                        'message' => "Sphinx reassign_features: failed for hotel {$hotelId}: " . $e->getMessage(),
                    ]);
                }
            }

            $processed += count($hotels);

            $lastHotel = end($hotels);
            $lastHotelId = TypeCoerce::toString($lastHotel['hotel_id'] ?? '');
            if ($lastHotelId === '') {
                $lastHotelId = null;
            }
        }

        FeatureMapper::clearCache();

        $durationMs = (int) (microtime(true) * 1000) - $startMs;
        $duration = round($durationMs / 1000, 1);

        $this->output('');
        $this->output("Done: {$stats['processed']} products updated, {$stats['errors']} errors ({$duration}s)");

        return [
            'success' => $stats['errors'] === 0,
            'stats' => [
                'total' => $stats['total'],
                'processed' => $stats['processed'],
                'errors' => $stats['errors'],
                'duration_ms' => $durationMs,
            ],
        ];
    }

    /**
     * Find hotels with linked CS-Cart products, paginated by hotel_id cursor.
     *
     * @param string|null $afterHotelId Cursor: only return rows with hotel_id > this value
     * @return array<string, mixed>[]
     */
    private function findLinkedHotels(string $countryCode, int $limit, ?string $afterHotelId = null): array
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }
        if ($afterHotelId !== null && $afterHotelId !== '') {
            $condition .= db_quote(' AND h.hotel_id > ?s', $afterHotelId);
        }

        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        $rows = db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.classification, h.property_type,
                    h.destination_name, h.country_code, h.region_name, h.region_id,
                    h.facilities_json, h.boards_json, h.is_adults_only
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND h.product_id IS NOT NULL AND h.product_id > 0
               ?p
             ORDER BY h.hotel_id ASC ?p",
            $condition,
            $limitClause,
        );

        /** @var array<string, mixed>[] $result */
        $result = is_array($rows) ? $rows : [];
        return $result;
    }
}
