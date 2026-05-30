<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: download and attach images from Sphinx API to CS-Cart products.
 *
 * Only processes hotels that:
 * - Are linked to a CS-Cart product (product_id > 0)
 * - Match the active scope filter (see below)
 * - Have no images yet (unless &force=Y is passed)
 *
 * When a hotel's images_json in the DB is empty, the command falls back to the
 * Sphinx API per-hotel detail endpoint to fetch fresh images before giving up.
 *
 * Scope filters (mutually exclusive, evaluated in priority order):
 *   &destination_id=1234   — only hotels with that destination_id
 *   &region_id=5678        — only hotels with that region_id
 *   &country=GR            — only hotels with that country_code
 *   &whitelist=strict      — only hotels whose destination_id is in the whitelist table
 *   (none)                 — all hotels in whitelisted countries (existing default)
 *
 * Other flags:
 *   &force=Y               — re-sync all images (even if product already has images)
 *   &limit=N               — cap total hotels processed
 */
class SyncImagesCommand extends AbstractSyncCommand
{
    private const int BATCH_SIZE = 50;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Download and attach hotel images to CS-Cart products';
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
            'images_added' => 0,
            'hotels_skipped' => 0,
            'errors' => 0,
            'total' => 0,
        ];

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;
        $afterHotelId = null;

        $this->output('Syncing images — scope: ' . $scope
            . ($force ? ' | FORCE: re-syncing all (including products with existing images)' : ' | skipping products with existing images'));

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

                // Fallback 1: single image_url stored from list sync (cheap, no API call)
                if (empty($images)) {
                    $imageUrl = TypeCoerce::toString($hotel['image_url'] ?? '');
                    if ($imageUrl !== '') {
                        $images = [['url' => $imageUrl]];
                    }
                }

                // Fallback 2: fetch from per-hotel detail endpoint.
                // The list endpoint (/api/v1/static/hotels) often omits images[];
                // the detail endpoint (/api/v1/static/hotels/{id}) always includes it.
                // The detail endpoint may return {"data": {...}} or the object directly.
                if (empty($images)) {
                    $api = Container::getApi();
                    $fresh = $api->getHotel($hotelId);

                    if ($fresh === null) {
                        $httpCode = $api->getHttpClient()->getLastHttpCode();
                        $apiError = $api->getHttpClient()->getLastError();
                        $this->output("[{$hotelId}] API detail fetch failed (HTTP {$httpCode}): {$apiError}");
                    } else {
                        // Unwrap {"data": {...}} envelope if present
                        $hotelData = is_array($fresh['data'] ?? null) ? $fresh['data'] : $fresh;

                        /** @var mixed $rawImages */
                        $rawImages = $hotelData['images'] ?? null;
                        if (!empty($rawImages) && is_array($rawImages)) {
                            /** @var array<mixed> $images */
                            $images = $rawImages;
                            $freshJson = (string) json_encode($images);
                            $firstUrl = '';
                            foreach ($images as $img) {
                                if (is_array($img)) {
                                    $u = TypeCoerce::toString($img['url'] ?? '');
                                } elseif (is_string($img)) {
                                    $u = $img;
                                } else {
                                    $u = '';
                                }
                                if ($u !== '') {
                                    $firstUrl = $u;
                                    break;
                                }
                            }
                            Container::getHotelRepository()->updateImages($hotelId, $firstUrl, $freshJson);
                        } else {
                            $topKeys = implode(', ', array_keys($fresh));
                            $this->output("[{$hotelId}] API returned no images (keys: {$topKeys})");
                        }
                    }
                }

                if (empty($images)) {
                    $this->output("[{$hotelId}] No images in API data ... SKIPPED");
                    $stats['hotels_skipped']++;
                    continue;
                }

                $imgCount = 0;
                $firstError = '';
                foreach ($images as $img) {
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

                    $isMain = ($imgCount === 0);
                    $ok = fn_sphinx_holidays_add_product_image($productId, $url, $isMain);

                    if ($ok) {
                        $imgCount++;
                    } else {
                        $stats['errors']++;
                        $imgError = \Tygh\Addons\SphinxHolidays\Api\ImageHelper::$lastDownloadError;
                        if ($firstError === '' && $imgError !== '') {
                            $firstError = $imgError . ' — ' . substr($url, 0, 100);
                        }
                    }
                }

                $hotelName = TypeCoerce::toString($hotel['name'] ?? '');
                if ($imgCount > 0) {
                    $this->output("[{$hotelId}] {$hotelName} ... {$imgCount} images added");
                    $stats['images_added'] += $imgCount;
                    $stats['hotels_processed']++;
                } else {
                    $detail = $firstError !== '' ? " [{$firstError}]" : '';
                    $this->output("[{$hotelId}] {$hotelName} ... FAILED (no images downloaded){$detail}");
                    $stats['errors']++;
                }
            }

            $processed += count($hotels);

            // Advance cursor to last hotel_id in this batch to prevent re-fetching the same rows.
            // $hotels is non-empty here (empty check above), so end() returns array<string,mixed>.
            $lastHotel = end($hotels);
            $afterHotelId = TypeCoerce::toString($lastHotel['hotel_id'] ?? '');
            if ($afterHotelId === '') {
                $afterHotelId = null;
            }
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        $this->output("Done: {$stats['hotels_processed']} hotels, {$stats['images_added']} images added, {$stats['hotels_skipped']} skipped, {$stats['errors']} errors (" . round($durationMs / 1000, 1) . 's)');

        return [
            'success' => true,
            'stats' => [
                'total' => $stats['total'],
                'synced' => $stats['hotels_processed'],
                'added' => $stats['images_added'],
                'skipped' => $stats['hotels_skipped'],
                'failed' => $stats['errors'],
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
