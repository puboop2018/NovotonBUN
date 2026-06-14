<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Read-only aggregate / reporting queries over the sphinx_hotels table:
 * counts (total, linked, orphaned, with-boards), distinct facet values
 * (countries, classifications, property types, destination ids) and the
 * last-synced timestamp.
 *
 * Extracted from HotelRepository, which mixed these reporting reads with the
 * core CRUD + sync-write surface. Behaviour is preserved verbatim; HotelRepository
 * keeps the public methods as thin delegations so its many callers (services,
 * controllers, cron commands) are unchanged.
 */
class HotelStatsRepository
{
    use RowNarrowingTrait;

    /**
     * Get hotel counts grouped by country code.
     *
     * @return array<string, int>
     */
    public function getCountsByCountry(): array
    {
        $rows = self::asRowList(db_get_array(
            "SELECT country_code, COUNT(*) as cnt FROM ?:sphinx_hotels WHERE sync_status = 'active' GROUP BY country_code ORDER BY cnt DESC",
        ));

        $counts = [];
        foreach ($rows as $row) {
            $code = TypeCoerce::toString($row['country_code'] ?? '');
            if ($code === '') {
                $code = 'unknown';
            }
            $counts[$code] = TypeCoerce::toInt($row['cnt'] ?? 0);
        }

        return $counts;
    }

    /**
     * Get distinct country codes from synced hotels.
     *
     * @return list<string>
     */
    public function getDistinctCountries(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT country_code FROM ?:sphinx_hotels WHERE country_code != '' ORDER BY country_code",
        ));
    }

    /**
     * Get total number of active hotels.
     */
    public function getTotal(): int
    {
        return TypeCoerce::toInt(db_get_field("SELECT COUNT(*) FROM ?:sphinx_hotels WHERE sync_status = 'active'"));
    }

    /**
     * Get last hotel sync timestamp, optionally per country.
     */
    public function getLastSyncedAt(?string $countryCode = null): ?string
    {
        if ($countryCode !== null) {
            $val = TypeCoerce::toString(db_get_field(
                'SELECT MAX(last_synced_at) FROM ?:sphinx_hotels WHERE country_code = ?s',
                $countryCode,
            ));
        } else {
            $val = TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:sphinx_hotels'));
        }
        return $val === '' ? null : $val;
    }

    /**
     * Count hotels that have a linked CS-Cart product.
     */
    public function countLinked(): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_id IS NOT NULL AND product_id > 0 AND sync_status = 'active'",
        ));
    }

    /**
     * Get distinct destination_ids for active hotels, filtered by country codes.
     *
     * @param string[] $countryCodes Country codes to filter (e.g. ['GR', 'BG'])
     * @return int[] Distinct destination IDs
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        if (empty($countryCodes)) {
            return self::asIntList(db_get_fields(
                "SELECT DISTINCT destination_id FROM ?:sphinx_hotels WHERE sync_status = 'active' AND destination_id > 0",
            ));
        }

        $placeholders = implode(',', array_fill(0, count($countryCodes), '?s'));
        return self::asIntList(db_get_fields(
            "SELECT DISTINCT destination_id FROM ?:sphinx_hotels WHERE sync_status = 'active' AND destination_id > 0 AND country_code IN ($placeholders)",
            ...$countryCodes,
        ));
    }

    /**
     * Count hotels that have boards_json AND a linked product.
     */
    public function countWithBoardsAndProduct(string $countryCode = ''): int
    {
        $condition = ' AND h.boards_json IS NOT NULL AND h.product_id IS NOT NULL AND h.product_id > 0';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p",
            $condition,
        ));
    }

    /**
     * Count sphinx_hotels rows whose linked product_id no longer exists in CS-Cart.
     *
     * These are hotels that believe they have a product but the product was deleted
     * from CS-Cart without clearing the sphinx_hotels link.
     */
    public function countOrphanedProducts(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.product_id > 0
             AND NOT EXISTS (SELECT 1 FROM ?:products p WHERE p.product_id = h.product_id)',
        ));
    }

    /**
     * Get distinct classification values present in the data.
     *
     * @return list<int>
     */
    public function getDistinctClassifications(): array
    {
        return self::asIntList(db_get_fields(
            'SELECT DISTINCT classification FROM ?:sphinx_hotels WHERE classification IS NOT NULL ORDER BY classification',
        ));
    }

    /**
     * Get distinct property_type values present in the data.
     *
     * @return list<string>
     */
    public function getDistinctPropertyTypes(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT property_type FROM ?:sphinx_hotels WHERE property_type IS NOT NULL AND property_type != '' ORDER BY property_type",
        ));
    }
}
