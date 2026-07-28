<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper as CoreFeatureMapper;

/**
 * Cron command: explain feature assignment for ONE hotel.
 *
 * reassign_features prints only "OK" per hotel — when a product still shows
 * empty features afterwards, nothing says WHICH stage dropped the value:
 * an unconfigured feature id, empty provider data on the hotel row, a code
 * that did not normalize, or an unmapped value. This command runs the EXACT
 * assignment ReassignFeaturesCommand runs (same protected method), wrapped
 * in a report of every input and the product's resulting feature values —
 * paste the output into a support thread and the gap is named.
 *
 * Usage:
 *   cron_mode=diagnose_features&hotel_id=476
 */
class DiagnoseFeaturesCommand extends ReassignFeaturesCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['diagnose_features'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Explain feature assignment for one hotel (hotel_id=…): configured feature IDs, row data, and resulting product features';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(): array
    {
        $hotelId = TypeCoerce::toString($this->params['hotel_id'] ?? '');
        if ($hotelId === '') {
            $this->output('ERROR: hotel_id parameter is required (…&hotel_id=476)');
            return ['success' => false];
        }

        $hotel = TypeCoerce::toStringMap(db_get_row(
            'SELECT hotel_id, product_id, hotel_name, star_rating, property_type,
                    city, region, country, is_adults_only
             FROM ?:novoton_hotels WHERE hotel_id = ?s',
            $hotelId,
        ));
        if ($hotel === []) {
            $this->output("ERROR: no ?:novoton_hotels row for hotel {$hotelId}");
            return ['success' => false];
        }

        $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
        $this->output('Hotel ' . $hotelId . ' — ' . TypeCoerce::toString($hotel['hotel_name'] ?? ''));
        $this->output('product_id: ' . ($productId > 0 ? (string) $productId : 'NOT LINKED — nothing can be assigned'));
        $this->output('');

        // 1. Which feature types can assign at all on this store.
        $this->output('== configured CS-Cart feature ids (0 = assignments for that type NO-OP) ==');
        foreach ([
            'stars', 'property_type', 'resort', 'board', 'travel_group',
            'hotel_facility', 'room_facility', 'beach_access', 'city', 'region',
        ] as $type) {
            $fid = CoreFeatureMapper::getFeatureId($type);
            $this->output('  ' . str_pad($type, 15) . ($fid > 0 ? '#' . $fid : '0  ← not configured'));
        }
        $this->output('');

        // 2. What the provider data offers for this hotel.
        $this->output('== hotel row inputs ==');
        foreach (['star_rating', 'property_type', 'city', 'region', 'is_adults_only'] as $col) {
            $v = TypeCoerce::toString($hotel[$col] ?? '');
            $this->output('  ' . str_pad($col, 15) . ($v !== '' && $v !== '0' ? $v : '(empty — that feature stays empty)'));
        }
        $hotelData = fn_novoton_holidays_get_hotel_data($hotelId);
        $boards = is_array($hotelData) && is_array($hotelData['boards'] ?? null) ? $hotelData['boards'] : [];
        $this->output('  ' . str_pad('boards', 15) . (count($boards) > 0
            ? count($boards) . ' in stored hotel_data'
            : '(none in stored hotel_data — re-sync hotel info to fill Mese)'));
        $facilityCount = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s',
            $hotelId,
        ));
        $this->output('  ' . str_pad('facilities', 15) . $facilityCount . ' raw rows');
        $this->output('');

        if ($productId <= 0) {
            return ['success' => false];
        }

        // 3. Run the EXACT reassign for this hotel.
        $this->output('== running assignment (same code path as reassign_features) ==');
        $container = Container::getInstance();
        $this->assignFeatures(
            $productId,
            $hotelId,
            $hotel,
            $container->featureMapper(),
            $container->novotonNormalizer(),
            $container->facilityRepository(),
        );
        CoreFeatureMapper::clearCache();
        $this->output('  done');
        $this->output('');

        // 4. Ground truth: what the product carries NOW.
        $this->output('== product feature values after assignment ==');
        $rows = TypeCoerce::toRowList(db_get_array(
            'SELECT pfv.feature_id, fd.description AS feature_name, vd.variant
             FROM ?:product_features_values pfv
             JOIN ?:product_features_descriptions fd
                  ON fd.feature_id = pfv.feature_id AND fd.lang_code = ?s
             LEFT JOIN ?:product_feature_variant_descriptions vd
                  ON vd.variant_id = pfv.variant_id AND vd.lang_code = ?s
             WHERE pfv.product_id = ?i AND pfv.lang_code = ?s
             ORDER BY pfv.feature_id',
            CART_LANGUAGE,
            CART_LANGUAGE,
            $productId,
            CART_LANGUAGE,
        ));
        if ($rows === []) {
            $this->output('  (no feature values stored for this product)');
        }
        foreach ($rows as $row) {
            $this->output('  #' . TypeCoerce::toString($row['feature_id'])
                . ' ' . str_pad(TypeCoerce::toString($row['feature_name'] ?? ''), 22)
                . TypeCoerce::toString($row['variant'] ?? ''));
        }

        return ['success' => true];
    }
}
