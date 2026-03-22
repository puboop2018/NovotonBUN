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
 */
class AddProductsCommand extends AbstractSyncCommand
{
    public static function getDescription(): string
    {
        return 'Create CS-Cart products from unlinked Sphinx hotels';
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

        if ($stats['invalid_country'] > 0) {
            $this->output("WARNING: {$stats['invalid_country']} hotels skipped — country codes not in CS-Cart. Check sync health log.");
        }
        $this->output("Done: {$stats['added']} added, {$stats['skipped']} skipped, {$stats['failed']} failed, {$stats['invalid_country']} invalid country.");

        return ['success' => true, 'stats' => $stats];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
