<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Registry;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * Cron command: assign discovered board/meal types as CS-Cart product features.
 *
 * Reads boards_json from sphinx_hotels (populated by discover_boards mode),
 * resolves canonical codes to CS-Cart feature variants via travel_core FeatureMapper,
 * and assigns them as M-type (multiple checkboxes) product features.
 *
 * Diff-based: adds new board variants, removes stale ones.
 *
 * Usage:
 *   php cron.php access_key=KEY mode=assign_boards
 *   php cron.php access_key=KEY mode=assign_boards country=GR
 *   php cron.php access_key=KEY mode=assign_boards limit=100
 */
class AssignBoardsCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Assign discovered board/meal types as CS-Cart product features';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $hotelRepo = new HotelRepository();
        $featureAssigner = Container::getFeatureAssigner();

        $countryCode = $params['country'] ?? '';
        $limit = (int) ($params['limit'] ?? 0);

        // Check that feature_id_meals is configured in travel_core
        $featureId = (int) Registry::get('addons.travel_core.feature_id_meals');
        if ($featureId <= 0) {
            $this->output('ERROR: travel_core setting "feature_id_meals" is not configured (value: 0).');
            $this->output('Please set the Meals/Board feature ID in Admin > Add-ons > Travel Core settings.');
            return ['success' => false, 'error' => 'feature_id_meals not configured'];
        }

        $this->output("Using CS-Cart feature ID: {$featureId} for board/meals");

        // Get hotels with discovered boards AND linked products
        $hotels = $hotelRepo->findWithBoardsAndProduct($countryCode, $limit);
        $this->output('Hotels with boards + products: ' . count($hotels));

        if (empty($hotels)) {
            $this->output('No hotels to process. Run discover_boards first, then add_products.');
            return ['success' => true, 'stats' => ['processed' => 0]];
        }

        $stats = [
            'processed'       => 0,
            'assigned'        => 0,
            'removed'         => 0,
            'skipped_no_map'  => 0,
            'errors'          => 0,
        ];

        foreach ($hotels as $hotel) {
            $productId = (int) $hotel['product_id'];
            $hotelId = $hotel['hotel_id'];

            $boards = json_decode($hotel['boards_json'], true);
            if (!is_array($boards) || empty($boards)) {
                continue;
            }

            try {
                $featureAssigner->assignAll($productId, $hotel);
                $stats['processed']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                if ($stats['errors'] <= 10) {
                    $this->output("  [ERROR] Hotel {$hotelId}: " . $e->getMessage());
                }
            }

            // Progress every 100
            if ($stats['processed'] % 100 === 0 && $stats['processed'] > 0) {
                $this->output("  Progress: {$stats['processed']}/" . count($hotels));
            }
        }

        // Clear cache
        FeatureMapper::clearCache();

        $this->output('');
        $this->output('Board Assignment Summary:');
        $this->output("  Hotels processed: {$stats['processed']}");
        $this->output("  Errors: {$stats['errors']}");

        return ['success' => true, 'stats' => $stats];
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
