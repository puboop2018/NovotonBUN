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
