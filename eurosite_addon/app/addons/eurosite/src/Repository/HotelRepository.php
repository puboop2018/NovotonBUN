<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_hotels — the operator's own hotels (getOwnHotelsRequest),
 * keyed (tourop_code, product_code). The per-row tourop_code — live rows
 * report "LA" — is the source of truth for search/booking payloads, NOT the
 * addon's tourop_code setting (spec-example "EU").
 */
class HotelRepository
{
    use RowNarrowingTrait;

    /**
     * @param list<array{code: string, name: string, city_code: string, tourop: string, rooms: array<string, string>}> $hotels
     */
    public function upsertBatch(array $hotels, string $countryCode = ''): int
    {
        if ($hotels === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $submitted = 0;
        foreach (array_chunk($hotels, 250) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $h) {
                $code = trim(TypeCoerce::toString($h['code']));
                if ($code === '') {
                    continue;
                }
                $tuples[] = '(?s, ?s, ?s, ?s, ?s, ?s, ?s)';
                array_push(
                    $params,
                    trim(TypeCoerce::toString($h['tourop'])),
                    $code,
                    TypeCoerce::toString($h['name']),
                    trim(TypeCoerce::toString($h['city_code'])),
                    $countryCode,
                    (string) json_encode($h['rooms'], JSON_UNESCAPED_UNICODE),
                    $now,
                );
            }
            if ($tuples === []) {
                continue;
            }
            db_query(
                'INSERT INTO ?:eurosite_hotels
                    (tourop_code, product_code, name, city_code, country_code, rooms_json, last_synced_at)
                 VALUES ' . implode(', ', $tuples) . "
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    city_code = VALUES(city_code),
                    country_code = IF(VALUES(country_code) <> '', VALUES(country_code), country_code),
                    rooms_json = VALUES(rooms_json),
                    sync_status = 'active',
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
    public function getByCity(string $cityCode): array
    {
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:eurosite_hotels WHERE city_code = ?s AND sync_status = 'active' ORDER BY name",
            $cityCode,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProductCode(string $productCode): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:eurosite_hotels WHERE product_code = ?s LIMIT 1',
            $productCode,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Distinct tourop codes present in a city's hotel rows ("LA" live) —
     * what search payloads should target.
     *
     * @return list<string>
     */
    public function getTouropCodesForCity(string $cityCode): array
    {
        $rows = db_get_fields(
            "SELECT DISTINCT tourop_code FROM ?:eurosite_hotels WHERE city_code = ?s AND sync_status = 'active'",
            $cityCode,
        );

        return array_values(array_filter(array_map(
            static fn ($v): string => trim(TypeCoerce::toString($v)),
            is_array($rows) ? $rows : [],
        ), static fn (string $v): bool => $v !== ''));
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_hotels'));
    }

    public function getLastSyncedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:eurosite_hotels'));
    }
}
