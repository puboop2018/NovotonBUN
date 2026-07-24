<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\PackageNormalizer;

/**
 * Behavior tests for the package normalization extracted from
 * functions/hotels.php — the DB record → normalized package shape the
 * hotel-data pipeline serves.
 */
final class PackageNormalizerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function dbRecord(): array
    {
        return [
            'package_id' => 'PKG-7',
            'package_name' => 'EDART +BEACH 2026',
            'min_price' => '453.5',
            'has_early_booking' => true,
            'seasons_count' => '3',
            'synced_at' => '2026-07-20 10:00:00',
        ];
    }

    public function testBaseShapeWithoutPriceinfoDetails(): void
    {
        $normalized = PackageNormalizer::normalize(self::dbRecord());

        self::assertSame('PKG-7', $normalized['IdCont']);
        self::assertSame('EDART +BEACH 2026', $normalized['PackageName']);
        self::assertSame(453.5, $normalized['min_price']);
        self::assertTrue($normalized['has_early_booking']);
        self::assertSame(3, $normalized['seasons_count']);
        self::assertArrayNotHasKey('priceinfo', $normalized, 'prefetch mode never decodes priceinfo');
    }

    public function testPriceinfoDetailsNormalizeSingleEntriesToLists(): void
    {
        $record = self::dbRecord();
        $record['priceinfo_data'] = (string) json_encode([
            // Single season / single price arrive as bare objects from the
            // XML-derived JSON — the pipeline expects lists.
            'seasons' => ['season' => ['IdSeason' => 'S1', 'From' => '2026-06-01']],
            'season_price' => ['IdRoom' => 'DBL', 'Price' => 453],
            'early_booking' => ['Percent' => 10],
        ]);

        $normalized = PackageNormalizer::normalize($record, true);

        self::assertSame([['IdSeason' => 'S1', 'From' => '2026-06-01']], $normalized['seasons']);
        self::assertSame([['IdRoom' => 'DBL', 'Price' => 453]], $normalized['season_price']);
        self::assertSame(['Percent' => 10], $normalized['early_booking']);
        self::assertArrayHasKey('priceinfo', $normalized);
    }

    public function testPriceinfoListsPassThroughUnchanged(): void
    {
        $record = self::dbRecord();
        $record['priceinfo_data'] = (string) json_encode([
            'seasons' => ['season' => [['IdSeason' => 'S1'], ['IdSeason' => 'S2']]],
            'season_price' => [['IdRoom' => 'DBL'], ['IdRoom' => 'SGL']],
        ]);

        $normalized = PackageNormalizer::normalize($record, true);

        self::assertCount(2, $normalized['seasons']);
        self::assertCount(2, $normalized['season_price']);
    }

    public function testGarbagePriceinfoFallsBackToTheBaseShape(): void
    {
        $record = self::dbRecord();
        $record['priceinfo_data'] = 'not json';

        $normalized = PackageNormalizer::normalize($record, true);

        self::assertSame('PKG-7', $normalized['IdCont']);
        self::assertArrayNotHasKey('priceinfo', $normalized);
        self::assertArrayNotHasKey('seasons', $normalized);
    }
}
