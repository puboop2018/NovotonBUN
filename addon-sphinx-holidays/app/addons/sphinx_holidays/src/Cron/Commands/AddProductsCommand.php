<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Create CS-Cart products from unlinked Sphinx hotels.
 *
 * Thin orchestrator: loads batches of unlinked hotels, resolves destination
 * hierarchies, then delegates per-hotel product creation to SphinxProductFactory.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=add_products
 *   php cron.php access_key=KEY mode=add_products country=TR
 *   php cron.php access_key=KEY mode=add_products retry_skipped=1
 *   php cron.php access_key=KEY mode=add_products retry_skipped=1 country=TR
 *   php cron.php access_key=KEY mode=add_products retry_skipped=invalid_country
 */
class AddProductsCommand extends AbstractSyncCommand
{
    public static function getDescription(): string
    {
        return 'Create CS-Cart products from unlinked Sphinx hotels (retry_skipped=1 to retry previously skipped)';
    }

    public function execute(array $params = []): array
    {
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();
        $factory = Container::getProductFactory();
        $template = ConfigProvider::getProductCategoryTemplate();

        $countryCode = $params['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);
        $batchSize = (int) ($params['batch_size'] ?? 200);
        $retrySkipped = $params['retry_skipped'] ?? '';

        // Handle retry_skipped: reset product_skip_reason so hotels become eligible again
        if ($retrySkipped !== '') {
            $reason = ($retrySkipped === '1') ? '' : $retrySkipped;
            $reset = $hotelRepo->resetSkipped($countryCode, $reason);
            $filter = $countryCode !== '' ? " for country {$countryCode}" : '';
            $reasonFilter = $reason !== '' ? " with reason '{$reason}'" : '';
            $this->output("Reset {$reset} previously skipped hotels{$filter}{$reasonFilter}. They are now eligible for product creation.");
        }

        $factory->loadValidCountryCodes();

        $stats = [
            'added'           => 0,
            'skipped'         => 0,
            'failed'          => 0,
            'invalid_country' => 0,
            'total'           => 0,
        ];

        if (!$destRepo->loadParentLookup()) {
            $this->output("WARNING: sphinx_destinations is empty — run destination sync first. Category paths will use hotel fields only.");
        }

        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $hotelRepo->findUnlinked($countryCode, min($remaining, $effectiveBatch));
            if (empty($hotels)) {
                break;
            }

            if ($stats['total'] === 0) {
                $this->output("Processing unlinked hotels in batches of {$effectiveBatch}...");
            }

            $stats['total'] += count($hotels);

            // Resolve destination hierarchies for this batch
            $destinationIds = array_filter(array_unique(array_column($hotels, 'destination_id')));
            $hierarchyMap = !empty($destinationIds) ? $destRepo->resolveHierarchies($destinationIds) : [];

            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'];
                $hierarchy = $hierarchyMap[(int) $hotel['destination_id']] ?? [];

                $result = $factory->createFromHotel($hotel, $hierarchy, $template);

                $this->output("[{$hotelId}] {$hotel['name']} ... " . strtoupper($result['status'])
                    . ($result['product_id'] ? " (ID: {$result['product_id']})" : '')
                    . ($result['reason'] ? " ({$result['reason']})" : ''));

                match ($result['status']) {
                    'added'   => $stats['added']++,
                    'linked'  => $stats['skipped']++,
                    'skipped' => str_contains($result['reason'], 'invalid country')
                        ? $stats['invalid_country']++
                        : $stats['failed']++,
                    'failed'  => $stats['failed']++,
                };
            }

            $processed += count($hotels);
        }

        FeatureMapper::clearCache();

        // Diagnostic: if nothing was processed, check for skipped hotels
        if ($stats['total'] === 0) {
            $skippedCount = $hotelRepo->countSkipped();
            if ($skippedCount > 0) {
                $byReason = $this->getSkippedBreakdown();
                $this->output("No eligible hotels found, but {$skippedCount} hotel(s) were previously skipped:");
                foreach ($byReason as $reason => $count) {
                    $this->output("  - {$reason}: {$count}");
                }
                $this->output("Run with retry_skipped=1 to make them eligible again.");
                $this->output("  Example: php cron.php access_key=KEY mode=add_products retry_skipped=1");
            }
        }

        if ($stats['invalid_country'] > 0) {
            $this->output("WARNING: {$stats['invalid_country']} hotels skipped — country codes not in CS-Cart. Check sync health log.");
            $this->output("After enabling the missing countries in CS-Cart, run: mode=add_products retry_skipped=invalid_country");
        }
        $this->output("Done: {$stats['added']} added, {$stats['skipped']} skipped, {$stats['failed']} failed, {$stats['invalid_country']} invalid country.");

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Get breakdown of skipped hotels by reason.
     *
     * @return array<string, int> reason => count
     */
    private function getSkippedBreakdown(): array
    {
        $rows = db_get_array(
            "SELECT product_skip_reason, COUNT(*) AS cnt
             FROM ?:sphinx_hotels
             WHERE product_skip_reason IS NOT NULL AND sync_status = 'active'
             GROUP BY product_skip_reason
             ORDER BY cnt DESC"
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['product_skip_reason']] = (int) $row['cnt'];
        }
        return $result;
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
