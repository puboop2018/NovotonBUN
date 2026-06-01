<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: populate the image sync queue from hotel DB records.
 *
 * Reads images_json from the DB (single level — no API calls, no fallbacks).
 * Hotels with empty images_json are logged and skipped; run enrich_hotel_data first.
 *
 * Scope filters (mutually exclusive, evaluated in priority order):
 *   &destination_id=1234   — only hotels with that destination_id
 *   &region_id=5678        — only hotels with that region_id
 *   &country=GR            — only hotels with that country_code
 *   &whitelist=strict      — only hotels whose destination_id is in the whitelist table
 *   (none)                 — all hotels in whitelisted countries (existing default)
 *
 * Other flags:
 *   &force=Y               — re-queue all (including products with existing images)
 *   &limit=N               — cap total hotels processed
 */
class SyncImagesCommand extends AbstractSyncCommand
{
    private const int BATCH_SIZE = 50;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Populate image sync queue from hotel DB records (run process_image_queue to download)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        $countryCode = TypeCoerce::toString($params['country'] ?? '');
        $destinationId = TypeCoerce::toInt($params['destination_id'] ?? 0);
        $regionId = TypeCoerce::toInt($params['region_id'] ?? 0);
        $strictWhitelist = TypeCoerce::toString($params['whitelist'] ?? '') === 'strict';
        $limit = TypeCoerce::toInt($params['limit'] ?? 0);
        $batchSize = TypeCoerce::toInt($params['batch_size'] ?? self::BATCH_SIZE);
        $force = TypeCoerce::toString($params['force'] ?? '') === 'Y';

        // Resolve scope filter (priority: destination_id > region_id > country > whitelist=strict > default)
        $countryCodes = [];
        $whitelistDestIds = [];

        if ($destinationId > 0) {
            $scope = "destination_id={$destinationId}";
        } elseif ($regionId > 0) {
            $scope = "region_id={$regionId}";
        } elseif ($countryCode !== '') {
            $countryCodes = [$countryCode];
            $scope = "country={$countryCode}";
        } elseif ($strictWhitelist) {
            $entries = Container::getDestinationWhitelistRepository()->findAll();
            foreach ($entries as $entry) {
                $id = TypeCoerce::toInt($entry['destination_id'] ?? 0);
                if ($id > 0) {
                    $whitelistDestIds[] = $id;
                }
            }
            if (empty($whitelistDestIds)) {
                $this->output('ERROR: whitelist=strict requested but the destination whitelist is empty.');
                return ['success' => false, 'stats' => ['error' => 'empty_whitelist']];
            }
            $scope = 'whitelist=strict (' . count($whitelistDestIds) . ' destinations)';
        } else {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
            if (empty($countryCodes)) {
                $this->output('ERROR: No whitelisted countries configured. Configure destination whitelist or pass &country=XX.');
                return ['success' => false, 'stats' => ['error' => 'no_whitelisted_countries']];
            }
            $scope = 'whitelisted countries: ' . implode(', ', $countryCodes);
        }

        $stats = [
            'hotels_processed' => 0,
            'images_queued' => 0,
            'hotels_skipped' => 0,
            'total' => 0,
        ];

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;
        $afterHotelId = null;

        $this->output('Populating image queue — scope: ' . $scope
            . ($force ? ' | FORCE: re-queuing all (including products with existing images)' : ' | skipping products with existing images'));

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $this->findHotels(
                $countryCodes,
                $destinationId,
                $regionId,
                $whitelistDestIds,
                min($remaining, $effectiveBatch),
                !$force,
                $afterHotelId,
            );
            if (empty($hotels)) {
                break;
            }

            $stats['total'] += count($hotels);

            foreach ($hotels as $hotel) {
                if (!is_array($hotel)) {
                    continue;
                }
                $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
                $imagesJson = TypeCoerce::toString($hotel['images_json'] ?? '');

                /** @var mixed $decoded */
                $decoded = json_decode($imagesJson, true);
                $images = is_array($decoded) ? $decoded : [];

                if (empty($images)) {
                    $this->output("[{$hotelId}] no images in DB — run enrich_hotel_data first. SKIPPED");
                    $stats['hotels_skipped']++;
                    continue;
                }

                $queued = 0;
                foreach ($images as $imgIdx => $img) {
                    if (is_array($img)) {
                        $url = TypeCoerce::toString($img['url'] ?? '');
                    } elseif (is_string($img)) {
                        $url = $img;
                    } else {
                        $url = '';
                    }
                    if ($url === '') {
                        continue;
                    }

                    $isMain = ($imgIdx === 0) ? 1 : 0;
                    // Re-queue on conflict: a row left over from a prior run
                    // (e.g. 'failed', or 'processing' from a crashed worker) is
                    // reset back to 'pending' so it gets retried. Rows already
                    // 'completed' are left untouched. Plain INSERT IGNORE would
                    // silently skip these, stranding failed images forever.
                    db_query(
                        "INSERT INTO ?:sphinx_image_sync_queue
                         (hotel_id, product_id, image_url, is_main, status, created_at, updated_at)
                         VALUES (?s, ?i, ?s, ?i, 'pending', ?i, ?i)
                         ON DUPLICATE KEY UPDATE
                            product_id = VALUES(product_id),
                            is_main = VALUES(is_main),
                            status = IF(status = 'completed', 'completed', 'pending'),
                            error_message = IF(status = 'completed', error_message, NULL),
                            updated_at = VALUES(updated_at)",
                        $hotelId,
                        $productId,
                        $url,
                        $isMain,
                        time(),
                        time(),
                    );
                    $queued++;
                }

                $hotelName = TypeCoerce::toString($hotel['name'] ?? '');
                $this->output("[{$hotelId}] {$hotelName} ... {$queued} image(s) queued");
                $stats['images_queued'] += $queued;
                $stats['hotels_processed']++;
            }

            $processed += count($hotels);

            $lastHotel = end($hotels);
            $afterHotelId = TypeCoerce::toString($lastHotel['hotel_id'] ?? '');
            if ($afterHotelId === '') {
                $afterHotelId = null;
            }
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        $this->output("Done: {$stats['hotels_processed']} hotels, {$stats['images_queued']} images queued, {$stats['hotels_skipped']} skipped (" . round($durationMs / 1000, 1) . 's)');

        if ($stats['images_queued'] > 0) {
            $this->output('Next: run cron_mode=process_image_queue to download and attach these images to products.');
        }

        return [
            'success' => true,
            'stats' => [
                'total' => $stats['total'],
                'processed' => $stats['hotels_processed'],
                'queued' => $stats['images_queued'],
                'skipped' => $stats['hotels_skipped'],
                'duration_ms' => $durationMs,
            ],
        ];
    }

    /**
     * Find hotels with linked products that need image sync.
     *
     * Scope filters are mutually exclusive (priority order):
     *   1. $destinationId > 0  → filter by destination_id
     *   2. $regionId > 0       → filter by region_id
     *   3. $whitelistDestIds   → destination_id IN (whitelisted IDs)
     *   4. $countryCodes       → country_code IN (codes)
     *   5. (none)              → all active linked hotels
     *
     * @param string[] $countryCodes
     * @param int[] $whitelistDestIds
     * @param int $limit Max rows to return
     * @param bool $skipExisting If true, LEFT JOINs images_links to skip products with images
     * @param string|null $afterHotelId Cursor: only return rows with hotel_id > this value
     * @return array<string, mixed>[]
     */
    private function findHotels(
        array $countryCodes,
        int $destinationId,
        int $regionId,
        array $whitelistDestIds,
        int $limit,
        bool $skipExisting,
        ?string $afterHotelId = null,
    ): array {
        $join = '';
        $condition = '';

        if ($destinationId > 0) {
            $condition = db_quote(' AND h.destination_id = ?i', $destinationId);
        } elseif ($regionId > 0) {
            $condition = db_quote(' AND h.region_id = ?i', $regionId);
        } elseif (!empty($whitelistDestIds)) {
            $condition = db_quote(' AND h.destination_id IN (?a)', $whitelistDestIds);
        } elseif (!empty($countryCodes)) {
            $condition = db_quote(' AND h.country_code IN (?a)', $countryCodes);
        }
        // else: no scope restriction — all active linked hotels

        if ($skipExisting) {
            $join = " LEFT JOIN ?:images_links il ON il.object_id = h.product_id AND il.object_type = 'product'";
            $condition .= ' AND il.pair_id IS NULL';
        }

        if ($afterHotelId !== null && $afterHotelId !== '') {
            $condition .= db_quote(' AND h.hotel_id > ?s', $afterHotelId);
        }

        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        $rows = db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.images_json, h.image_url
             FROM ?:sphinx_hotels h
             {$join}
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
