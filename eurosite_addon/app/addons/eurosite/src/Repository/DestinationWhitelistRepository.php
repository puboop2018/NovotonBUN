<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_destination_whitelist — which countries/cities are enabled for
 * sync + storefront search (sphinx pattern, collapsed to codes since the
 * Eurosite tree is flat country → city).
 *
 * Row shapes:
 *   (country_code, city_code='', selection_type='all')      — whole country
 *   (country_code, city_code='XYZ', selection_type='specific') — one city
 */
class DestinationWhitelistRepository
{
    use RowNarrowingTrait;

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:eurosite_destination_whitelist ORDER BY country_code, city_code',
        ));
    }

    /**
     * Distinct whitelisted country codes (both row kinds count).
     *
     * @return list<string>
     */
    public function getCountryCodes(): array
    {
        $rows = db_get_fields('SELECT DISTINCT country_code FROM ?:eurosite_destination_whitelist');

        return array_values(array_filter(array_map(
            static fn ($v) => trim(TypeCoerce::toString($v)),
            is_array($rows) ? $rows : [],
        ), static fn (string $v) => $v !== ''));
    }

    /**
     * City codes searchable for a country: every synced city when the country
     * has an 'all' row, else only its 'specific' city rows.
     *
     * @return list<string>
     */
    public function getAllowedCityCodes(string $countryCode): array
    {
        $hasAll = TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:eurosite_destination_whitelist
             WHERE country_code = ?s AND city_code = '' AND selection_type = 'all'",
            $countryCode,
        )) > 0;

        $rows = $hasAll
            ? db_get_fields('SELECT city_code FROM ?:eurosite_cities WHERE country_code = ?s', $countryCode)
            : db_get_fields(
                "SELECT city_code FROM ?:eurosite_destination_whitelist WHERE country_code = ?s AND city_code <> ''",
                $countryCode,
            );

        return array_values(array_filter(array_map(
            static fn ($v) => trim(TypeCoerce::toString($v)),
            is_array($rows) ? $rows : [],
        ), static fn (string $v) => $v !== ''));
    }

    public function isCityAllowed(string $countryCode, string $cityCode): bool
    {
        return in_array($cityCode, $this->getAllowedCityCodes($countryCode), true);
    }

    /**
     * Replace the whole whitelist atomically (admin save).
     *
     * @param list<array{country_code: string, city_code?: string, selection_type?: string}> $entries
     */
    public function replaceAll(array $entries): void
    {
        db_query('START TRANSACTION');
        try {
            db_query('DELETE FROM ?:eurosite_destination_whitelist');
            foreach ($entries as $e) {
                $country = trim(TypeCoerce::toString($e['country_code'] ?? ''));
                if ($country === '') {
                    continue;
                }
                $city = trim(TypeCoerce::toString($e['city_code'] ?? ''));
                $type = ($e['selection_type'] ?? '') === 'all' || $city === '' ? 'all' : 'specific';
                db_query(
                    'INSERT INTO ?:eurosite_destination_whitelist (country_code, city_code, selection_type)
                     VALUES (?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE selection_type = VALUES(selection_type)',
                    $country,
                    $city,
                    $city === '' ? 'all' : $type,
                );
            }
            db_query('COMMIT');
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_destination_whitelist'));
    }
}
