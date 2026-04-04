<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Cron command: download and attach images from Sphinx API to CS-Cart products.
 *
 * Only processes hotels that:
 * - Are linked to a CS-Cart product (product_id > 0)
 * - Are in whitelisted countries
 * - Have no images yet (unless &force=Y is passed)
 *
 * Usage:
 *   sync_images                — sync missing images for whitelisted countries
 *   sync_images&country=GR    — sync missing images for Greece only
 *   sync_images&force=Y       — re-sync all images (even if product already has images)
 */
class SyncImagesCommand
{
    private ?\Closure $outputCallback = null;

    private const BATCH_SIZE = 50;

    public static function getDescription(): string
    {
        return 'Download and attach hotel images to CS-Cart products';
    }

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        $countryCode = $params['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);
        $batchSize = (int) ($params['batch_size'] ?? self::BATCH_SIZE);
        $force = ($params['force'] ?? '') === 'Y';

        // Resolve country filter: explicit param > whitelist
        $countryCodes = [];
        if ($countryCode !== '') {
            $countryCodes = [$countryCode];
        } else {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
        }

        if (empty($countryCodes)) {
            $this->output("ERROR: No whitelisted countries configured. Configure destination whitelist or pass &country=XX.");
            return [
                'success' => false,
                'stats'   => ['error' => 'no_whitelisted_countries'],
            ];
        }

        $stats = [
            'hotels_processed' => 0,
            'images_added'     => 0,
            'hotels_skipped'   => 0,
            'errors'           => 0,
            'total'            => 0,
        ];

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        $this->output("Syncing images for countries: " . implode(', ', $countryCodes)
            . ($force ? ' (FORCE: re-syncing all, including products with existing images)' : ' (skipping products with existing images)'));

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $this->findHotels($countryCodes, min($remaining, $effectiveBatch), !$force);
            if (empty($hotels)) {
                break;
            }

            $stats['total'] += count($hotels);

            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'];
                $productId = (int) $hotel['product_id'];
                $imagesJson = $hotel['images_json'] ?? '[]';

                $images = json_decode($imagesJson, true);
                if (empty($images) || !is_array($images)) {
                    $this->output("[{$hotelId}] No images in API data ... SKIPPED");
                    $stats['hotels_skipped']++;
                    continue;
                }

                $imgCount = 0;
                foreach ($images as $img) {
                    $url = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                    if (empty($url)) {
                        continue;
                    }

                    $isMain = ($imgCount === 0);
                    $ok = fn_sphinx_holidays_add_product_image($productId, $url, $isMain);

                    if ($ok) {
                        $imgCount++;
                    } else {
                        $stats['errors']++;
                    }
                }

                if ($imgCount > 0) {
                    $this->output("[{$hotelId}] {$hotel['name']} ... {$imgCount} images added");
                    $stats['images_added'] += $imgCount;
                    $stats['hotels_processed']++;
                } else {
                    $this->output("[{$hotelId}] {$hotel['name']} ... FAILED (no images downloaded)");
                    $stats['errors']++;
                }
            }

            $processed += count($hotels);
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        $this->output("Done: {$stats['hotels_processed']} hotels, {$stats['images_added']} images added, {$stats['hotels_skipped']} skipped, {$stats['errors']} errors (" . round($durationMs / 1000, 1) . "s)");

        return [
            'success' => true,
            'stats'   => [
                'total'  => $stats['total'],
                'synced' => $stats['hotels_processed'],
                'added'  => $stats['images_added'],
                'skipped' => $stats['hotels_skipped'],
                'failed' => $stats['errors'],
                'duration_ms' => $durationMs,
            ],
        ];
    }

    /**
     * Find hotels with linked products that need image sync.
     *
     * @param string[] $countryCodes  Whitelist country codes
     * @param int      $limit         Max rows to return
     * @param bool     $skipExisting  If true, LEFT JOINs images_links to skip products with images
     */
    private function findHotels(array $countryCodes, int $limit, bool $skipExisting): array
    {
        $join = '';
        $condition = db_quote(" AND h.country_code IN (?a)", $countryCodes);

        if ($skipExisting) {
            $join = " LEFT JOIN ?:images_links il ON il.object_id = h.product_id AND il.object_type = 'product'";
            $condition .= " AND il.pair_id IS NULL";
        }

        $limitClause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';

        return db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.images_json
             FROM ?:sphinx_hotels h
             {$join}
             WHERE h.sync_status = 'active'
               AND h.product_id IS NOT NULL AND h.product_id > 0
               AND h.images_json IS NOT NULL AND h.images_json != '[]'
               ?p
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
