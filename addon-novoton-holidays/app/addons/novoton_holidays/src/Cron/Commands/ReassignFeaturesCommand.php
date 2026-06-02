<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\FeatureMapper;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper as CoreFeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;

/**
 * Cron command: re-assign product features to all linked hotel products.
 *
 * Processes every Novoton hotel that already has a CS-Cart product linked,
 * re-seeds feature aliases, and re-assigns stars, property type, boards,
 * facilities, resort, and travel group for each product.
 *
 * Use this after:
 *   - The travel_api_alias schema was migrated (map_id added to unique key)
 *   - Configuring feature IDs in Travel Core
 *   - Any time features are empty on existing products
 *
 * Scope filters:
 *   &country=RO            — only hotels for that country
 *   &limit=N               — cap total hotels processed
 *   &batch_size=N          — batch size (default 200)
 *
 * Usage:
 *   cron_mode=reassign_features
 *   cron_mode=reassign_features&country=RO
 *   cron_mode=reassign_features&country=RO&limit=100
 */
class ReassignFeaturesCommand extends AbstractCronCommand
{
    private const int BATCH_SIZE = 200;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['reassign_features'];
    }

    public static function getDescription(): string
    {
        return 'Re-assign CS-Cart product features (stars, property type, resort…) to all linked hotel products';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $startMs = (int) (microtime(true) * 1000);

        $countryParam = TypeCoerce::toString($this->params['country'] ?? '');
        $limit = TypeCoerce::toInt($this->params['limit'] ?? 0);
        $batchSize = TypeCoerce::toInt($this->params['batch_size'] ?? self::BATCH_SIZE);
        if ($batchSize <= 0) {
            $batchSize = self::BATCH_SIZE;
        }

        $scope = $countryParam !== '' ? "country={$countryParam}" : 'all countries';
        $this->output("Re-assigning product features — scope: {$scope}");
        $this->output('Seeding feature mappings...');

        if (function_exists('fn_travel_core_seed_feature_map')) {
            fn_travel_core_seed_feature_map();
        }
        if (function_exists('fn_novoton_holidays_seed_travel_aliases')) {
            fn_novoton_holidays_seed_travel_aliases();
        }

        $this->output('Seeding done. Processing hotels...');
        $this->output('');

        $container = Container::getInstance();
        $featureMapper = $container->featureMapper();
        $normalizer = $container->novotonNormalizer();
        $facilityRepo = $container->facilityRepository();

        $stats = ['processed' => 0, 'errors' => 0, 'total' => 0];
        $processed = 0;
        $effectiveBatch = ($limit > 0 && $limit < $batchSize) ? $limit : $batchSize;
        $lastHotelId = null;

        while (true) {
            $remaining = ($limit > 0) ? ($limit - $processed) : $effectiveBatch;
            if ($remaining <= 0) {
                break;
            }

            $hotels = $this->findLinkedHotels($countryParam, min($remaining, $effectiveBatch), $lastHotelId);
            if (empty($hotels)) {
                break;
            }

            $stats['total'] += count($hotels);

            foreach ($hotels as $hotel) {
                $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
                $hotelName = TypeCoerce::toString($hotel['hotel_name'] ?? '');

                if ($productId <= 0) {
                    continue;
                }

                try {
                    $this->assignFeatures($productId, $hotelId, $hotel, $featureMapper, $normalizer, $facilityRepo);
                    $stats['processed']++;
                    $this->output("[{$hotelId}] {$hotelName} (product {$productId}) ... OK");
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->output("[{$hotelId}] {$hotelName} ... ERROR: " . $e->getMessage());
                    fn_log_event('general', 'runtime', [
                        'message' => "Novoton reassign_features: failed for hotel {$hotelId}: " . $e->getMessage(),
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

        CoreFeatureMapper::clearCache();

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
     * @param array<string, mixed> $hotel
     */
    private function assignFeatures(
        int $productId,
        string $hotelId,
        array $hotel,
        FeatureMapper $featureMapper,
        ProviderNormalizerInterface $normalizer,
        FacilityRepositoryInterface $facilityRepo,
    ): void {
        // Star rating
        $starRating = TypeCoerce::toInt($hotel['star_rating'] ?? 0);
        if ($starRating >= 1) {
            $code = $normalizer->normalizeStarRating((string) $starRating);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'stars', $code);
            }
        }

        // Property type
        $propertyType = TypeCoerce::toString($hotel['property_type'] ?? '');
        if ($propertyType !== '') {
            $code = $normalizer->normalizePropertyType($propertyType);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'property_type', $code);
            }
        }

        // Resort / City
        $city = TypeCoerce::toString($hotel['city'] ?? '');
        if ($city !== '') {
            $code = $normalizer->normalizeResort($city);
            if ($code !== null) {
                $featureMapper->assignFeatureViaCore($productId, 'resort', $code);
            }
        }

        // Boards — read from stored hotel_data JSON (no API call needed)
        $hotelData = fn_novoton_holidays_get_hotel_data($hotelId);
        if (is_array($hotelData)) {
            $boards = is_array($hotelData['boards'] ?? null) ? $hotelData['boards'] : [];
            if (!empty($boards)) {
                $boardCodes = [];
                foreach ($boards as $board) {
                    $raw = is_array($board)
                        ? PriceInfoFormatter::toScalar($board['IdBoard'] ?? $board['Board'] ?? '')
                        : PriceInfoFormatter::toScalar($board);
                    $code = $normalizer->normalizeBoardCode($raw);
                    if ($code !== null) {
                        $boardCodes[] = $code;
                    }
                }
                if (!empty($boardCodes)) {
                    $featureMapper->assignMultipleViaCore($productId, 'board', array_unique($boardCodes));
                }
            }
        }

        // Facilities
        $allFacilityIds = $facilityRepo->getIdsForHotel($hotelId);
        $facilityCodes = [];
        if (!empty($allFacilityIds)) {
            foreach ($allFacilityIds as $fid) {
                $code = $normalizer->normalizeFacilityCode($fid);
                if ($code !== null) {
                    $facilityCodes[] = $code;
                }
            }
            if (!empty($facilityCodes)) {
                $featureMapper->assignFacilitiesViaCore($productId, array_unique($facilityCodes));
            }
        }

        // Travel Group — derived from resolved facility codes + adults-only flag
        $resolvedFacilityCodes = [];
        foreach ($facilityCodes as $code) {
            $mapping = CoreFeatureMapper::resolveFacility('novoton', $code);
            if ($mapping !== null && !empty($mapping['canonical_code'])) {
                $resolvedFacilityCodes[] = TypeCoerce::toString($mapping['canonical_code']);
            }
        }

        $travelGroups = TravelGroupResolver::derive(
            $resolvedFacilityCodes,
            TypeCoerce::toString($hotel['is_adults_only'] ?? 'N') === 'Y',
        );
        if (!empty($travelGroups)) {
            $featureMapper->assignMultipleViaCore($productId, 'travel_group', $travelGroups);
        }
    }

    /**
     * Find hotels with linked CS-Cart products, paginated by hotel_id cursor.
     *
     * @return array<string, mixed>[]
     */
    private function findLinkedHotels(string $countryParam, int $limit, ?string $afterHotelId = null): array
    {
        $condition = '';
        if ($countryParam !== '') {
            $condition .= db_quote(' AND h.country = ?s', $countryParam);
        }
        if ($afterHotelId !== null && $afterHotelId !== '') {
            $condition .= db_quote(' AND h.hotel_id > ?s', $afterHotelId);
        }

        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        $rows = db_get_array(
            'SELECT h.hotel_id, h.product_id, h.hotel_name, h.star_rating,
                    h.property_type, h.city, h.country, h.is_adults_only
             FROM ?:novoton_hotels h
             WHERE h.product_id IS NOT NULL AND h.product_id > 0
               ?p
             ORDER BY h.hotel_id ASC ?p',
            $condition,
            $limitClause,
        );

        /** @var array<string, mixed>[] $result */
        $result = is_array($rows) ? $rows : [];
        return $result;
    }
}
