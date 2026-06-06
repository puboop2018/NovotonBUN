<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: live one-hotel verification of the URL image-attach path.
 *
 * Resolves a single hotel's linked product, fetches its image URLs from the
 * Novoton API, runs fn_travel_core_attach_images_from_urls() against them, and
 * reports the ?:images_links row count for the product before and after — so a
 * single cron call confirms whether images actually land in the gallery on the
 * live server (where there is no DB/CS-Cart core to test against locally).
 *
 * Usage:
 *   cron_mode=diagnose_image_urls&hotel_id=110291          — attach + report delta
 *   cron_mode=diagnose_image_urls&hotel_id=110291&dry=Y    — list URLs only, no attach
 *   cron_mode=diagnose_image_urls&hotel_id=110291&max=10   — cap URLs (default 10)
 */
class DiagnoseImageUrlsCommand extends AbstractCronCommand
{
    private const int DEFAULT_MAX = 10;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['diagnose_image_urls'];
    }

    public static function getDescription(): string
    {
        return 'Attach a single hotel\'s image URLs and report images_links row count (live verification)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $hotelId = TypeCoerce::toString($this->getParam('hotel_id', ''));
        $dryRun = TypeCoerce::toString($this->getParam('dry', '')) === 'Y';
        $max = TypeCoerce::toInt($this->getParam('max', self::DEFAULT_MAX));
        if ($max <= 0) {
            $max = self::DEFAULT_MAX;
        }

        if ($hotelId === '') {
            $this->output('ERROR: &hotel_id=<id> is required. Example: &cron_mode=diagnose_image_urls&hotel_id=110291');
            return ['success' => false, 'error' => 'hotel_id required'];
        }

        $this->output("=== Diagnosing image URLs for hotel [{$hotelId}] ===");
        $this->output('Mode: ' . ($dryRun ? 'DRY RUN (list URLs only)' : 'ATTACH + report row-count delta'));

        // ── 1. Resolve the linked product ─────────────────────────────
        $hotel = Container::getInstance()->hotelRepository()->findById($hotelId);
        if ($hotel === null) {
            $this->output("ERROR: Hotel [{$hotelId}] not found in novoton_hotels.");
            return ['success' => false, 'error' => 'hotel not found'];
        }

        $hotelName = TypeCoerce::toString($hotel['hotel_name'] ?? '');
        $productId = PriceInfoFormatter::toInt($hotel['product_id'] ?? 0);
        $this->output("DB: name={$hotelName}, product_id={$productId}");

        if ($productId <= 0) {
            $this->output('ERROR: Hotel has no linked product_id. Run add_hotels_as_products first.');
            return ['success' => false, 'error' => 'no product linked'];
        }

        // ── 2. Build image URLs from the API (mirror AddProductsCommand) ─
        $urls = $this->collectImageUrls($hotelId, $max);
        $this->output('Image URLs from API: ' . count($urls));
        foreach ($urls as $i => $url) {
            $this->output("  [{$i}] {$url}");
        }

        if (empty($urls)) {
            $this->output('RESULT: No image URLs available from API. Nothing to attach.');
            return ['success' => true, 'product_id' => $productId, 'urls' => 0];
        }

        // ── 3. Count images_links rows BEFORE ─────────────────────────
        $before = $this->countImageLinks($productId);
        $this->output('');
        $this->output("images_links rows BEFORE: {$before}");

        if ($dryRun) {
            $this->output('DRY RUN — skipping attach. Re-run without &dry=Y to attach.');
            return ['success' => true, 'product_id' => $productId, 'urls' => count($urls), 'before' => $before];
        }

        // ── 4. Attach via the URL pipeline ────────────────────────────
        $handed = fn_travel_core_attach_images_from_urls($productId, $urls, true);
        $path = \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachPath;

        // ── 5. Count images_links rows AFTER ──────────────────────────
        $after = $this->countImageLinks($productId);
        $delta = $after - $before;

        $this->output('Attach path used: ' . ($path !== '' ? $path : '(none — nothing attached)'));
        $this->output("Images attached (function return): {$handed}");
        $this->output("images_links rows AFTER: {$after}");
        $this->output('Delta: ' . ($delta >= 0 ? "+{$delta}" : (string) $delta));
        $this->output('');

        if ($delta > 0) {
            $this->output("RESULT: SUCCESS — {$delta} image row(s) added to product #{$productId}.");
        } else {
            $this->output('RESULT: NO ROWS ADDED. Images were not attached — check that the');
            $this->output('URLs are reachable from the server and that fn_update_product ran without error.');
        }

        return [
            'success' => $delta > 0,
            'product_id' => $productId,
            'urls' => count($urls),
            'before' => $before,
            'after' => $after,
            'delta' => $delta,
        ];
    }

    /**
     * Fetch hotel images from the API and build absolute, space-encoded URLs.
     * Mirrors the URL construction in AddProductsCommand.
     *
     * @return list<string>
     */
    private function collectImageUrls(string $hotelId, int $max): array
    {
        $urls = [];
        try {
            $images = $this->api->hotels()->getHotelImages($hotelId);
            if (isset($images->url)) {
                foreach ($images->url as $url) {
                    $urls[] = Constants::IMAGE_BASE_URL . str_replace(' ', '%20', (string) $url);
                    if (count($urls) >= $max) {
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->output('WARN: API image fetch failed: ' . $e->getMessage());
        }

        return $urls;
    }

    private function countImageLinks(int $productId): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
            $productId,
        ));
    }
}
