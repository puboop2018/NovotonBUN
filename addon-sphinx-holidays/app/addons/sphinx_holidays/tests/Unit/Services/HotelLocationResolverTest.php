<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\HotelLocationResolver;

/**
 * The City/Region precedence ladder, exercised with REAL data probed from the
 * live Sphinx catalog. The tree there is exactly four levels:
 *
 *     2 continent "Asia"
 *     └── 233 country "Turkey"
 *         └── 3713 region "Antalya"
 *             └── 168566 destination "Antalya City"
 *
 * Every `destination` node hangs directly off a `region` (checked across a
 * 5,000-node sample), and nothing exists below `destination` — which is why
 * a city can never be found by walking UP, and the City ladder has no
 * ancestor rung while the Region ladder's ancestor rung is the common case.
 */
#[CoversClass(HotelLocationResolver::class)]
final class HotelLocationResolverTest extends TestCase
{
    /**
     * Hotel 3389 "Sealife Family Resort" → destination 168566 "Antalya City".
     * The healthy majority case: both values come from the tree.
     */
    public function testHotelOnADestinationNodeTakesCityFromItselfAndRegionFromItsParent(): void
    {
        $out = HotelLocationResolver::resolve(
            ['address_city' => ''],
            [
                'own_id' => 168566, 'own_type' => 'destination', 'own_name' => 'Antalya City',
                'city' => 'Antalya City', 'region' => 'Antalya', 'region_id' => 3713,
                'country' => 'Turkey', 'country_code' => 'TR',
            ],
        );

        self::assertSame('Antalya City', $out['city']);
        self::assertSame(HotelLocationResolver::SOURCE_DESTINATION, $out['city_source']);
        self::assertSame('Antalya', $out['region']);
        self::assertSame(3713, $out['region_id']);
        self::assertSame(HotelLocationResolver::SOURCE_ANCESTOR, $out['region_source']);
    }

    /**
     * Hotel 3319 "Grand Park Lara Hotel" → destination 3713, itself a region.
     * Region resolves from the hotel's own node; no city exists above it, so
     * City stays empty rather than borrowing the region or the country.
     */
    public function testHotelOnARegionNodeFillsRegionOnlyAndNeverInventsACity(): void
    {
        $out = HotelLocationResolver::resolve(
            ['address_city' => ''],
            [
                'own_id' => 3713, 'own_type' => 'region', 'own_name' => 'Antalya',
                'city' => '', 'region' => 'Antalya', 'region_id' => 3713,
                'country' => 'Turkey', 'country_code' => 'TR',
            ],
        );

        self::assertSame('Antalya', $out['region']);
        self::assertSame(HotelLocationResolver::SOURCE_DESTINATION, $out['region_source']);
        self::assertSame('', $out['city']);
        self::assertSame(HotelLocationResolver::SOURCE_NONE, $out['city_source']);
    }

    /**
     * Hotel 26366 "Adalya Port Hotel" → destination 233 "Turkey" (a COUNTRY).
     * The reported bug: the country must never become a city or a region.
     */
    public function testCountryAndContinentNodesProduceNeitherCityNorRegion(): void
    {
        foreach (['country' => 'Turkey', 'continent' => 'Asia'] as $type => $name) {
            $out = HotelLocationResolver::resolve(
                ['address_city' => ''],
                [
                    'own_id' => 233, 'own_type' => $type, 'own_name' => $name,
                    'city' => '', 'region' => '', 'region_id' => 0,
                    'country' => 'Turkey', 'country_code' => 'TR',
                ],
            );

            self::assertSame('', $out['city'], "{$type} must not name a city");
            self::assertSame('', $out['region'], "{$type} must not name a region");
        }
    }

    /**
     * Adalya's address city is "ANTALYA REGION" while its geocoded region is
     * "Antalya" — the provider stuffed a region string into the city field.
     * User ruling: demote it, let the geocode answer.
     */
    public function testSuspectAddressCityIsDemotedInFavourOfTheGeocodedCity(): void
    {
        $out = HotelLocationResolver::resolve(
            ['address_city' => 'ANTALYA REGION', 'geo_city' => 'Antalya', 'geo_region' => 'Antalya'],
            [
                'own_id' => 233, 'own_type' => 'country', 'own_name' => 'Turkey',
                'city' => '', 'region' => '', 'region_id' => 0,
            ],
        );

        self::assertSame('Antalya', $out['city']);
        self::assertSame(HotelLocationResolver::SOURCE_GEOCODE, $out['city_source']);
        self::assertSame('Antalya', $out['region']);
        self::assertSame(HotelLocationResolver::SOURCE_GEOCODE, $out['region_source']);
    }

    public function testHonestAddressCityBeatsTheGeocode(): void
    {
        // "Kemer" is a real city, unrelated to the region name → it stands.
        $out = HotelLocationResolver::resolve(
            ['address_city' => 'Kemer', 'geo_city' => 'Kemer Merkez', 'geo_region' => 'Antalya'],
            ['own_id' => 233, 'own_type' => 'country', 'own_name' => 'Turkey', 'city' => '', 'region' => ''],
        );

        self::assertSame('Kemer', $out['city']);
        self::assertSame(HotelLocationResolver::SOURCE_ADDRESS, $out['city_source']);
    }

    public function testSuspectAddressSurvivesOnlyWhenThereIsNoGeocodeYet(): void
    {
        // Before the geocode cron has run, a coarse label still beats an
        // empty City feature — and the 'address' provenance keeps the row
        // findable for the re-run.
        $out = HotelLocationResolver::resolve(
            ['address_city' => 'ANTALYA REGION', 'geo_city' => '', 'geo_region' => 'Antalya'],
            ['own_id' => 233, 'own_type' => 'country', 'own_name' => 'Turkey', 'city' => '', 'region' => ''],
        );

        self::assertSame('ANTALYA REGION', $out['city']);
        self::assertSame(HotelLocationResolver::SOURCE_ADDRESS, $out['city_source']);
    }

    public function testLooksLikeRegionFoldsCaseDiacriticsAndPunctuation(): void
    {
        self::assertTrue(HotelLocationResolver::looksLikeRegion('ANTALYA REGION', 'Antalya'));
        self::assertTrue(HotelLocationResolver::looksLikeRegion('Antalya', 'ANTALYA'));
        self::assertTrue(HotelLocationResolver::looksLikeRegion('Regiunea Constanța', 'Constanta'));
        self::assertFalse(HotelLocationResolver::looksLikeRegion('Kemer', 'Antalya'));
        // An empty side can never make something suspect.
        self::assertFalse(HotelLocationResolver::looksLikeRegion('Antalya', ''));
        self::assertFalse(HotelLocationResolver::looksLikeRegion('', 'Antalya'));
    }

    public function testRowSourceReportsTheWeakerOfTheTwoLadders(): void
    {
        self::assertSame(HotelLocationResolver::SOURCE_ANCESTOR, HotelLocationResolver::rowSource([
            'city_source' => HotelLocationResolver::SOURCE_DESTINATION,
            'region_source' => HotelLocationResolver::SOURCE_ANCESTOR,
        ]));
        self::assertSame(HotelLocationResolver::SOURCE_GEOCODE, HotelLocationResolver::rowSource([
            'city_source' => HotelLocationResolver::SOURCE_GEOCODE,
            'region_source' => HotelLocationResolver::SOURCE_ANCESTOR,
        ]));
        self::assertSame(HotelLocationResolver::SOURCE_NONE, HotelLocationResolver::rowSource([
            'city_source' => HotelLocationResolver::SOURCE_NONE,
            'region_source' => HotelLocationResolver::SOURCE_GEOCODE,
        ]));
    }

    public function testGeocodeIsTheLastRungForBothLadders(): void
    {
        $out = HotelLocationResolver::resolve(
            ['address_city' => '', 'geo_city' => 'Antalya', 'geo_region' => 'Antalya'],
            ['own_id' => 2, 'own_type' => 'continent', 'own_name' => 'Asia', 'city' => '', 'region' => ''],
        );

        self::assertSame('Antalya', $out['city']);
        self::assertSame('Antalya', $out['region']);
        self::assertSame(HotelLocationResolver::SOURCE_GEOCODE, HotelLocationResolver::rowSource($out));
    }
}
