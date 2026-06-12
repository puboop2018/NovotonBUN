<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;

/**
 * Cron command: diagnose feature assignment for a specific hotel.
 *
 * For each feature type shows three resolution gates:
 *   1. feature_id — the CS-Cart feature configured in Travel Core settings
 *   2. source data — what is in the sphinx_hotels row (classification, boards_json, etc.)
 *   3. resolve() — what FeatureMapper::resolve('sphinx', type, value) returns
 *
 * Read-only; does NOT modify the product or DB.
 *
 * Usage:
 *   cron_mode=diagnose_product_features&hotel_id=59841
 */
class DiagnoseProductFeaturesCommand extends AbstractSyncCommand
{
    private const string API_SOURCE = 'sphinx';

    #[\Override]
    public static function getDescription(): string
    {
        return 'Diagnose feature assignment gates for a single hotel (read-only)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        if ($hotelId === '') {
            $this->output('ERROR: &hotel_id=<id> is required.');
            $this->output('Example: &cron_mode=diagnose_product_features&hotel_id=59841');
            return ['success' => false, 'error' => 'hotel_id required'];
        }

        // Re-seed so the diagnostic reflects the same state as an actual add_products run.
        $this->seedFeatureMappings();

        $hotel = Container::getHotelRepository()->findById($hotelId);
        if ($hotel === null) {
            $this->output("ERROR: hotel [{$hotelId}] not found in sphinx_hotels.");
            return ['success' => false, 'error' => 'hotel not found'];
        }

        $normalizer = Container::getNormalizer();
        $hotelName = TypeCoerce::toString($hotel['name'] ?? '');
        $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);

        $this->output("=== Feature Diagnosis: [{$hotelId}] {$hotelName} ===");
        $this->output('Product ID: ' . ($productId > 0 ? (string) $productId : '(not linked)'));
        $this->output('');

        // ── Scalar selectbox features ──────────────────────────────────

        $this->diagnoseSelectBox(
            'stars',
            'feature_id_property_rating',
            TypeCoerce::toString($hotel['classification'] ?? ''),
            $normalizer->normalizeStarRating($hotel['classification'] ?? null),
        );

        $this->diagnoseSelectBox(
            'property_type',
            'feature_id_property_type',
            TypeCoerce::toString($hotel['property_type'] ?? ''),
            $normalizer->normalizePropertyType($hotel['property_type'] ?? null),
        );

        $this->diagnoseSelectBox(
            'resort',
            'feature_id_location',
            TypeCoerce::toString($hotel['destination_name'] ?? ''),
            $normalizer->normalizeResort($hotel['destination_name'] ?? null),
        );

        // ── Region (numeric ID, no normalizer) ────────────────────────
        $this->output('--- region ---');
        $featureIdRegion = FeatureMapper::getFeatureId('region');
        $regionId = TypeCoerce::toString($hotel['region_id'] ?? '');
        $regionName = TypeCoerce::toString($hotel['region_name'] ?? '');
        $this->output('  feature_id  : ' . ($featureIdRegion > 0 ? (string) $featureIdRegion : 'NOT CONFIGURED (set feature_id_region in Travel Core settings)'));
        $this->output("  source      : region_id={$regionId}, region_name={$regionName}");
        if ($regionId !== '' && $regionId !== '0') {
            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'region', $regionId);
            $this->outputMapping($mapping);
        } else {
            $this->output('  resolve     : SKIP (region_id empty)');
        }
        $this->output('');

        // ── City (location variant — no alias table, direct DB lookup) ─
        $this->output('--- city ---');
        $featureIdCity = FeatureMapper::getFeatureId('city');
        $cityName = TypeCoerce::toString($hotel['destination_name'] ?? '');
        $this->output('  feature_id  : ' . ($featureIdCity > 0 ? (string) $featureIdCity : 'NOT CONFIGURED (set feature_id_city in Travel Core settings)'));
        $this->output("  source      : destination_name={$cityName}");
        if ($cityName !== '' && $featureIdCity > 0) {
            $variantId = TypeCoerce::toInt(db_get_field(
                'SELECT pf.variant_id FROM ?:product_feature_variant_descriptions pf
                 WHERE pf.variant = ?s AND pf.lang_code = ?s
                 AND pf.variant_id IN (SELECT variant_id FROM ?:product_feature_variants WHERE feature_id = ?i)
                 LIMIT 1',
                trim($cityName),
                CART_LANGUAGE,
                $featureIdCity,
            ));
            if ($variantId > 0) {
                $this->output("  variant     : variant_id={$variantId} (will reuse)");
            } else {
                $this->output('  variant     : NOT FOUND (will be auto-created on assignment)');
            }
        } elseif ($cityName === '') {
            $this->output('  resolve     : SKIP (destination_name empty)');
        }
        $this->output('');

        // ── Boards ────────────────────────────────────────────────────
        $this->output('--- board ---');
        $featureIdBoard = FeatureMapper::getFeatureId('board');
        $boardsJson = TypeCoerce::toString($hotel['boards_json'] ?? '');
        $this->output('  feature_id  : ' . ($featureIdBoard > 0 ? (string) $featureIdBoard : 'NOT CONFIGURED (set feature_id_meals in Travel Core settings)'));
        if ($boardsJson === '' || $boardsJson === '[]') {
            $this->output('  source      : boards_json EMPTY (run discover_boards cron first)');
        } else {
            /** @var mixed $decoded */
            $decoded = json_decode($boardsJson, true);
            /** @var string[] $boardList */
            $boardList = is_array($decoded) ? array_map(TypeCoerce::toString(...), $decoded) : [];
            $this->output('  source      : boards_json has ' . count($boardList) . ' code(s): ' . implode(', ', array_slice($boardList, 0, 20)));
            foreach ($boardList as $code) {
                $mapping = FeatureMapper::resolve(self::API_SOURCE, 'board', $code);
                $status = $mapping !== null ? 'RESOLVED map_id=' . TypeCoerce::toString($mapping['map_id'] ?? '') : 'UNRESOLVED';
                $this->output("    [{$code}] {$status}");
            }
        }
        $this->output('');

        // ── Facilities ────────────────────────────────────────────────
        $this->output('--- hotel_facility / room_facility / beach_access ---');
        $featureIdHotelFac = FeatureMapper::getFeatureId('hotel_facility');
        $featureIdRoomFac = FeatureMapper::getFeatureId('room_facility');
        $featureIdBeach = FeatureMapper::getFeatureId('beach_access');
        $this->output('  feature_id hotel_facility : ' . ($featureIdHotelFac > 0 ? (string) $featureIdHotelFac : 'NOT CONFIGURED'));
        $this->output('  feature_id room_facility  : ' . ($featureIdRoomFac > 0 ? (string) $featureIdRoomFac : 'NOT CONFIGURED'));
        $this->output('  feature_id beach_access   : ' . ($featureIdBeach > 0 ? (string) $featureIdBeach : 'NOT CONFIGURED'));

        $facilitiesJson = TypeCoerce::toString($hotel['facilities_json'] ?? '');
        $facilityCodes = $this->resolveFacilityCodes($facilitiesJson);

        $this->output('');

        // ── Travel group (derived) ─────────────────────────────────────
        $this->output('--- travel_group ---');
        $featureIdTG = FeatureMapper::getFeatureId('travel_group');
        $isAdultsOnly = TypeCoerce::toString($hotel['is_adults_only'] ?? 'N') === 'Y';
        $this->output('  feature_id  : ' . ($featureIdTG > 0 ? (string) $featureIdTG : 'NOT CONFIGURED (set feature_id_travel_group in Travel Core settings)'));
        $this->output('  source      : is_adults_only=' . ($isAdultsOnly ? 'Y' : 'N'));

        $groupCodes = TravelGroupResolver::derive($facilityCodes, $isAdultsOnly);
        if (empty($groupCodes)) {
            $this->output('  derived     : no travel groups (no matching facility codes)');
        } else {
            $this->output('  derived     : ' . implode(', ', $groupCodes));
            foreach ($groupCodes as $code) {
                $mapping = FeatureMapper::resolve(self::API_SOURCE, 'travel_group', $code);
                $status = $mapping !== null ? 'RESOLVED map_id=' . TypeCoerce::toString($mapping['map_id'] ?? '') : 'UNRESOLVED';
                $this->output("    [{$code}] {$status}");
            }
        }
        $this->output('');

        $this->output('=== End of diagnosis ===');
        return ['success' => true, 'hotel_id' => $hotelId];
    }

    /**
     * Resolve facility JSON into canonical codes, printing a summary.
     * Returns facility codes for travel_group derivation.
     *
     * @return string[]
     */
    private function resolveFacilityCodes(string $facilitiesJson): array
    {
        if ($facilitiesJson === '' || $facilitiesJson === '[]') {
            $this->output('  source      : facilities_json EMPTY');
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($facilitiesJson, true);
        $facList = is_array($decoded) ? $decoded : [];
        $this->output('  source      : facilities_json has ' . count($facList) . ' item(s)');

        $resolved = 0;
        $unresolved = 0;
        $unresolvedSample = [];
        $codes = [];

        foreach ($facList as $rawFac) {
            /** @var array<string, mixed> $fac */
            $fac = is_array($rawFac) ? $rawFac : [];
            $facId = TypeCoerce::toString($fac['id'] ?? '');
            if ($facId === '') {
                continue;
            }
            $facName = TypeCoerce::toString($fac['name'] ?? '');
            $mapping = FeatureMapper::resolveFacility(self::API_SOURCE, $facId);
            if ($mapping !== null) {
                $resolved++;
                $code = TypeCoerce::toString($mapping['canonical_code'] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            } else {
                $unresolved++;
                if (count($unresolvedSample) < 15) {
                    $unresolvedSample[] = "{$facId}:{$facName}";
                }
            }
        }

        $this->output("  resolve     : {$resolved} resolved, {$unresolved} unresolved");
        if (!empty($unresolvedSample)) {
            $this->output('  unresolved sample: ' . implode(', ', $unresolvedSample));
        }

        return $codes;
    }

    /**
     * Diagnose a scalar selectbox feature (stars, property_type, resort).
     */
    private function diagnoseSelectBox(
        string $featureType,
        string $settingKey,
        string $rawValue,
        ?string $normalizedCode,
    ): void {
        $featureId = FeatureMapper::getFeatureId($featureType);
        $this->output("--- {$featureType} ---");
        $this->output('  feature_id  : ' . ($featureId > 0 ? (string) $featureId : "NOT CONFIGURED (set {$settingKey} in Travel Core settings)"));
        $this->output('  source      : ' . ($rawValue !== '' ? $rawValue : '(empty)'));
        $this->output('  normalized  : ' . ($normalizedCode !== null ? $normalizedCode : '(null — no match)'));

        if ($normalizedCode !== null) {
            $mapping = FeatureMapper::resolve(self::API_SOURCE, $featureType, $normalizedCode);
            $this->outputMapping($mapping);
        } else {
            $this->output('  resolve     : SKIP (null normalized value)');
        }

        $this->output('');
    }

    /**
     * @param array<string, mixed>|null $mapping
     */
    private function outputMapping(?array $mapping): void
    {
        if ($mapping === null) {
            $this->output('  resolve     : UNRESOLVED (no alias row in travel_api_alias — re-seeding may fix this)');
            return;
        }

        $mapId = TypeCoerce::toString($mapping['map_id'] ?? '');
        $canonical = TypeCoerce::toString($mapping['canonical_code'] ?? '');
        $variantId = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);
        $variantStr = $variantId > 0 ? (string) $variantId : 'not yet — will be auto-created';
        $this->output("  resolve     : OK (map_id={$mapId}, canonical={$canonical}, variant_id={$variantStr})");
    }
}
