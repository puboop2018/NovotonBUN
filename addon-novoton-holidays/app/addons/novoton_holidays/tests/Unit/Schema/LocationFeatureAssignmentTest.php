<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins novoton's City/Region product-feature assignment (sphinx parity).
 *
 * Field case: Admiral Hotel after reassign_features had facilities and
 * property type but EMPTY Oraș/Regiune — novoton had no region assignment
 * at all, and city only flowed through the 'resort' canonical mapping,
 * which is unconfigured on fresh stores. Locations are open-ended proper
 * nouns, so they are assigned BY NAME on the shared location features
 * (variant looked up / created on demand), exactly like
 * SphinxFeatureAssigner::assignLocationFeature.
 */
final class LocationFeatureAssignmentTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testFeatureMapperAssignsLocationsByName(): void
    {
        $src = self::src('src/Services/FeatureMapper.php');

        self::assertStringContainsString(
            'public function assignLocationByName(int $productId, string $featureType, string $locationName): bool',
            $src,
        );
        // Name-based variant lookup on the CONFIGURED feature, created when
        // missing — and a hard no-op when the feature id is unset.
        self::assertStringContainsString('CoreFeatureMapper::getFeatureId($featureType)', $src);
        self::assertStringContainsString('?:product_feature_variant_descriptions', $src);
        self::assertStringContainsString('$this->createVariant($featureId, $locationName, $locationName)', $src);
    }

    public function testCoreFeatureIdSelfHealsForLocationTypes(): void
    {
        // Without this, assignLocationByName silently no-ops on every store
        // where feature_id_city / feature_id_region were never configured —
        // the exact "Oraș/Regiune stay empty after reassign" field case.
        $core = (string) file_get_contents(
            dirname(__DIR__, 7)
            . '/addon-travel-core/app/addons/travel_core/src/Services/FeatureMapper.php',
        );
        self::assertStringContainsString('LOCATION_FEATURE_NAMES', $core);
        self::assertStringContainsString("'city' => ['City', 'Oraș', 'Oras']", $core);
        self::assertStringContainsString("'region' => ['Region', 'Regiune']", $core);
        // Found id persists into the setting (one lookup, not per call)…
        self::assertStringContainsString("updateValue(\$settingKey, (string) \$featureId, 'travel_core', true)", $core);
        // …and the in-request cache resets with the rest of the caches.
        self::assertStringContainsString('self::$featureIdCache = [];', $core);
    }

    public function testDiagnoseCommandNarratesTheExactReassignPath(): void
    {
        $src = self::src('src/Cron/Commands/DiagnoseFeaturesCommand.php');
        self::assertStringContainsString('class DiagnoseFeaturesCommand extends ReassignFeaturesCommand', $src);
        self::assertStringContainsString("['diagnose_features']", $src);
        // It must run the SAME assignment, not a re-implementation…
        self::assertStringContainsString('$this->assignFeatures(', $src);
        // …which requires the parent hook to stay overridable-callable.
        self::assertStringContainsString(
            'protected function assignFeatures(',
            self::src('src/Cron/Commands/ReassignFeaturesCommand.php'),
        );
        // The three diagnostic stages.
        self::assertStringContainsString('configured CS-Cart feature ids', $src);
        self::assertStringContainsString('hotel row inputs', $src);
        self::assertStringContainsString('product feature values after assignment', $src);
    }

    public function testBothWritePathsAssignCityAndRegion(): void
    {
        foreach ([
            'src/Cron/Commands/ReassignFeaturesCommand.php',
            'src/Cron/Commands/AddProductsCommand.php',
        ] as $rel) {
            $src = self::src($rel);
            self::assertStringContainsString("assignLocationByName(\$productId, 'city'", $src, $rel);
            self::assertStringContainsString("assignLocationByName(\$productId, 'region'", $src, $rel);
        }

        // The reassign query must actually FETCH the region column.
        self::assertStringContainsString(
            'h.city, h.region, h.country',
            self::src('src/Cron/Commands/ReassignFeaturesCommand.php'),
        );
    }
}
