<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Resolves a hotel's City and Region by an explicit precedence ladder.
 *
 * The Sphinx destination tree is exactly four levels and every `destination`
 * node hangs directly off a `region` (verified against the live catalog):
 *
 *     continent → country → region → destination
 *
 * A hotel's `destination_id` may point at ANY level, which is the whole
 * problem: Adalya Port Hotel points at 233 "Turkey" (type `country`), so the
 * old code fell straight through to the API address and stored
 * "ANTALYA REGION" as its city while Regiune stayed empty forever.
 *
 * Because cities sit BELOW regions, walking up the tree can never discover a
 * city — only a region. Hence two different ladders:
 *
 *   City    1. the hotel's OWN node when its type is city-level
 *           2. address.city — unless it looks like the region (see below)
 *           3. reverse-geocoded city (geo_city column)
 *
 *   Region  1. the hotel's OWN node when its type is `region`
 *           2. the first `region` ancestor while walking up (the common case:
 *              every hotel that points at a `destination`)
 *           3. reverse-geocoded region (geo_region column)
 *
 * `country` and `continent` nodes never produce either value — the walk just
 * climbs past them.
 *
 * Suspect-address rule (user ruling): providers put region strings in the
 * city field ("ANTALYA REGION" for a hotel whose region is "Antalya"). When
 * address.city equals or contains the resolved region name, it is demoted and
 * the geocoded city answers instead — the geocode call is needed for that
 * hotel's region anyway, so it costs nothing extra.
 *
 * Pure: no DB, no API. The caller supplies the hotel row and the hierarchy
 * (from DestinationRepository::resolveHierarchies), so the same decision runs
 * during sync enrichment and during the backfill cron.
 */
final class HotelLocationResolver
{
    /** Destination node types that name a city-level place. */
    public const array CITY_LEVEL_TYPES = ['city', 'destination', 'resort'];

    /** Provenance values stored in ?:sphinx_hotels.location_source. */
    public const string SOURCE_DESTINATION = 'destination';
    public const string SOURCE_ANCESTOR = 'ancestor';
    public const string SOURCE_ADDRESS = 'address';
    public const string SOURCE_GEOCODE = 'geocode';
    public const string SOURCE_NONE = '';

    /**
     * @param array<string, mixed> $hotel Normalized/stored hotel row
     * @param array<string, mixed> $hierarchy resolveHierarchies() entry for the
     *                                        hotel's destination_id
     * @return array{city: string, region: string, region_id: int, city_source: string, region_source: string}
     */
    public static function resolve(array $hotel, array $hierarchy): array
    {
        $ownType = strtolower(trim(TypeCoerce::toString($hierarchy['own_type'] ?? '')));
        $ownName = trim(TypeCoerce::toString($hierarchy['own_name'] ?? ''));

        // ── Region ladder ───────────────────────────────────────────────
        $region = '';
        $regionId = 0;
        $regionSource = self::SOURCE_NONE;

        if ($ownType === 'region' && $ownName !== '') {
            $region = $ownName;
            $regionId = TypeCoerce::toInt($hierarchy['own_id'] ?? 0);
            $regionSource = self::SOURCE_DESTINATION;
        } elseif (trim(TypeCoerce::toString($hierarchy['region'] ?? '')) !== '') {
            $region = trim(TypeCoerce::toString($hierarchy['region']));
            $regionId = TypeCoerce::toInt($hierarchy['region_id'] ?? 0);
            $regionSource = self::SOURCE_ANCESTOR;
        } elseif (trim(TypeCoerce::toString($hotel['geo_region'] ?? '')) !== '') {
            $region = trim(TypeCoerce::toString($hotel['geo_region']));
            $regionSource = self::SOURCE_GEOCODE;
        }

        // ── City ladder ─────────────────────────────────────────────────
        $city = '';
        $citySource = self::SOURCE_NONE;

        if (in_array($ownType, self::CITY_LEVEL_TYPES, true) && $ownName !== '') {
            // Rung 1 — the hotel's own node names a city-level place.
            $city = $ownName;
            $citySource = self::SOURCE_DESTINATION;
        } else {
            // No city can come from ancestors (cities sit below regions), so
            // rung 2 is the API address — demoted when it merely repeats the
            // region — and rung 3 the geocode.
            $addressCity = trim(TypeCoerce::toString($hotel['address_city'] ?? ''));
            $geoCity = trim(TypeCoerce::toString($hotel['geo_city'] ?? ''));

            if ($addressCity !== '' && !self::looksLikeRegion($addressCity, $region)) {
                $city = $addressCity;
                $citySource = self::SOURCE_ADDRESS;
            } elseif ($geoCity !== '') {
                $city = $geoCity;
                $citySource = self::SOURCE_GEOCODE;
            } elseif ($addressCity !== '') {
                // Suspect, but it is all we have — better a coarse label than
                // an empty City feature. Provenance stays 'address' so the
                // row is findable once geocoding fills geo_city.
                $city = $addressCity;
                $citySource = self::SOURCE_ADDRESS;
            }
        }

        return [
            'city' => $city,
            'region' => $region,
            'region_id' => $regionId,
            'city_source' => $citySource,
            'region_source' => $regionSource,
        ];
    }

    /**
     * Whether an address city string is really the region under another name
     * ("ANTALYA REGION" vs region "Antalya"): equal, or one containing the
     * other, after case/diacritic/punctuation folding.
     */
    public static function looksLikeRegion(string $addressCity, string $region): bool
    {
        $a = self::fold($addressCity);
        $r = self::fold($region);
        if ($a === '' || $r === '') {
            return false;
        }

        return $a === $r || str_contains($a, $r) || str_contains($r, $a);
    }

    /**
     * Fold to a comparable form: lowercase, diacritics stripped, non-alnum
     * collapsed to single spaces.
     */
    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ă' => 'a',
            'â' => 'a', 'î' => 'i', 'ö' => 'o', 'ü' => 'u', 'ç' => 'c',
            'ğ' => 'g', 'ı' => 'i', 'é' => 'e', 'è' => 'e', 'á' => 'a',
        ]);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Winning provenance for the row's location_source column: the weaker of
     * the two ladders, so a row is flagged for follow-up while EITHER value
     * still rests on a fallback rung.
     *
     * @param array{city_source: string, region_source: string} $resolved
     */
    public static function rowSource(array $resolved): string
    {
        $rank = [
            self::SOURCE_NONE => 0,
            self::SOURCE_GEOCODE => 1,
            self::SOURCE_ADDRESS => 2,
            self::SOURCE_ANCESTOR => 3,
            self::SOURCE_DESTINATION => 4,
        ];
        $c = $rank[$resolved['city_source']] ?? 0;
        $r = $rank[$resolved['region_source']] ?? 0;

        return $c <= $r ? $resolved['city_source'] : $resolved['region_source'];
    }
}
