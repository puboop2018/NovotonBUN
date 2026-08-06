<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_cities — the getCityRequest catalog, with `is_own` marking the
 * rows that also appear in getOwnCityResponse (cities carrying the
 * operator's own offers — the searchable subset that matters most).
 */
class CityRepository
{
    use RowNarrowingTrait;

    /**
     * @param list<array{code: string, name: string, country_code?: string}> $cities
     */
    public function upsertBatch(array $cities, string $countryCode = '', bool $markOwn = false): int
    {
        if ($cities === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $own = $markOwn ? 'Y' : 'N';
        $submitted = 0;
        foreach (array_chunk($cities, 500) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $c) {
                $code = trim(TypeCoerce::toString($c['code']));
                if ($code === '') {
                    continue;
                }
                $tuples[] = '(?s, ?s, ?s, ?s, ?s)';
                array_push(
                    $params,
                    $code,
                    trim(TypeCoerce::toString($c['country_code'] ?? '')) ?: $countryCode,
                    TypeCoerce::toString($c['name']),
                    $own,
                    $now,
                );
            }
            if ($tuples === []) {
                continue;
            }
            // An own-cities pass must never demote is_own back to N for rows
            // the plain cities pass touches afterwards — hence the IF().
            db_query(
                'INSERT INTO ?:eurosite_cities (city_code, country_code, name, is_own, last_synced_at)
                 VALUES ' . implode(', ', $tuples) . "
                 ON DUPLICATE KEY UPDATE
                    country_code = IF(VALUES(country_code) <> '', VALUES(country_code), country_code),
                    name = VALUES(name),
                    is_own = IF(VALUES(is_own) = 'Y', 'Y', is_own),
                    last_synced_at = VALUES(last_synced_at)",
                ...$params,
            );
            $submitted += count($tuples);
        }

        return $submitted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getByCountry(string $countryCode, bool $ownOnly = false): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:eurosite_cities WHERE country_code = ?s ?p ORDER BY name',
            $countryCode,
            $ownOnly ? "AND is_own = 'Y'" : '',
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $cityCode): ?array
    {
        $row = self::asRow(db_get_row('SELECT * FROM ?:eurosite_cities WHERE city_code = ?s', $cityCode));

        return $row === [] ? null : $row;
    }

    /**
     * Country codes that have at least one own-offer city.
     *
     * @return list<string>
     */
    public function getOwnCountryCodes(): array
    {
        $rows = db_get_fields("SELECT DISTINCT country_code FROM ?:eurosite_cities WHERE is_own = 'Y' AND country_code <> ''");

        return array_values(array_map(static fn ($v): string => TypeCoerce::toString($v), is_array($rows) ? $rows : []));
    }

    public function count(bool $ownOnly = false): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:eurosite_cities ?p',
            $ownOnly ? "WHERE is_own = 'Y'" : '',
        ));
    }

    public function getLastSyncedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:eurosite_cities'));
    }
}
