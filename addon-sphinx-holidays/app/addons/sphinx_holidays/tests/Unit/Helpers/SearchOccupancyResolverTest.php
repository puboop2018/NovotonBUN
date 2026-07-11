<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\SearchOccupancyResolver;

/**
 * Pins the Sphinx search occupancy contract
 * (`occupancy: [{adults, children_ages: [int]}]`, one entry per room —
 * Documentation/Sphinx API.txt) and the three storefront defects the
 * resolver fixed: infant age "0" dropped by !empty(), rooms_data ignored
 * (totals duplicated into every room), and rooms=0 sending an empty
 * occupancy array.
 */
#[CoversClass(SearchOccupancyResolver::class)]
class SearchOccupancyResolverTest extends TestCase
{
    public function testRoomsDataProducesPerRoomOccupancy(): void
    {
        // The API spec's own worked example: two rooms, different ages.
        $json = '[{"adults":2,"children":2,"childrenAges":[5,12]},{"adults":2,"children":0,"childrenAges":[]}]';

        self::assertSame(
            [
                ['adults' => 2, 'children_ages' => [5, 12]],
                ['adults' => 2, 'children_ages' => []],
            ],
            SearchOccupancyResolver::resolve($json, 4, 2, '5,12'),
            'per-room specs must win over the flat totals',
        );
    }

    public function testRoomsDataIntCastsStringAgesAndKeepsInfantZero(): void
    {
        $json = '[{"adults":"2","childrenAges":["0","6"]}]';

        self::assertSame(
            [['adults' => 2, 'children_ages' => [0, 6]]],
            SearchOccupancyResolver::resolve($json, 2, 1, ''),
        );
    }

    public function testMalformedRoomsDataFallsBackToFlatParams(): void
    {
        foreach (['not-json', '[]', '["scalar-room"]', '{"adults":2}'] as $bad) {
            $occupancy = SearchOccupancyResolver::resolve($bad, 2, 1, '6');
            self::assertSame(
                [['adults' => 2, 'children_ages' => [6]]],
                $occupancy,
                "rooms_data={$bad} must fall back to the legacy flat params",
            );
        }
    }

    public function testFlatCsvKeepsLoneInfantAgeZero(): void
    {
        // Regression: !empty("0") is true, so a single infant was silently
        // dropped and the search ran adults-only.
        self::assertSame(
            [['adults' => 2, 'children_ages' => [0]]],
            SearchOccupancyResolver::resolve('', 2, 1, '0'),
        );
    }

    public function testFlatCsvParsesAgesAndSkipsBlankTokens(): void
    {
        self::assertSame(
            [['adults' => 2, 'children_ages' => [6, 8]]],
            SearchOccupancyResolver::resolve('', 2, 1, ' 6, 8, '),
        );
    }

    public function testFlatParamsReplicateAcrossRooms(): void
    {
        self::assertSame(
            [
                ['adults' => 2, 'children_ages' => [6]],
                ['adults' => 2, 'children_ages' => [6]],
            ],
            SearchOccupancyResolver::resolve('', 2, 2, '6'),
            'legacy URLs without rooms_data keep the replicate behaviour',
        );
    }

    public function testRoomsZeroStillProducesOneRoom(): void
    {
        // Regression: rooms=0 built an EMPTY occupancy array.
        self::assertSame(
            [['adults' => 2, 'children_ages' => []]],
            SearchOccupancyResolver::resolve('', 2, 0, ''),
        );
    }

    public function testAdultsAreClampedToAtLeastOne(): void
    {
        self::assertSame(
            [['adults' => 1, 'children_ages' => []]],
            SearchOccupancyResolver::resolve('', 0, 1, ''),
        );
    }
}
