<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Cron command: download and attach images from Sphinx API to CS-Cart products.
 *
 * Finds hotels that have a linked product but no images in CS-Cart yet,
 * then downloads all images from images_json and attaches them via
 * fn_update_image_pairs().
 *
 * By default, only hotels from whitelisted countries are processed.
 *
 * Usage: index.php?dispatch=sphinx_cron.run&access_key=KEY&cron_mode=sync_images
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

        // Resolve country filter: explicit param > whitelist > all
        $countryCodes = [];
        if ($countryCode !== '') {
            $countryCodes = [$countryCode];
        } else {
            $countryCodes = ConfigProvider::getSelectedCountryCodes();
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

        if (!empty($countryCodes)) {
            $this->output("Syncing images for hotels in whitelisted countries: " . implode(', ', $countryCodes));
        } else {
            $this->output("Syncing images for all hotels (no country whitelist configured)...");
        }

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $this->findHotelsNeedingImages($countryCodes, min($remaining, $effectiveBatch));
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
        $stats['duration_ms'] = $durationMs;

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
     * Find hotels that have a linked product but no images in CS-Cart yet.
     *
     * LEFT JOINs ?:images_links to exclude products that already have images.
     */
    private function findHotelsNeedingImages(array $countryCodes, int $limit): array
    {
        $condition = '';
        if (!empty($countryCodes)) {
            $condition .= db_quote(" AND h.country_code IN (?a)", $countryCodes);
        }

        $limitClause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';

        return db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.images_json
             FROM ?:sphinx_hotels h
             LEFT JOIN ?:images_links il ON il.object_id = h.product_id AND il.object_type = 'product'
             WHERE h.sync_status = 'active'
               AND h.product_id IS NOT NULL AND h.product_id > 0
               AND h.images_json IS NOT NULL AND h.images_json != '[]'
               AND il.pair_id IS NULL ?p
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
