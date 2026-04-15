<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_destination_whitelist table.
 *
 * Manages which destinations are enabled for sync.
 *
 * @since 1.2.0
 */
class DestinationWhitelistRepository
{
    use RowNarrowingTrait;

    /**
     * Get distinct country codes from whitelisted destinations.
     *
     * @return list<string>
     */
    public function getCountryCodes(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT d.country_code FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code != ''
             ORDER BY d.country_code",
        ));
    }

    /**
     * Get all whitelist entries.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return self::asRowList(db_get_array('SELECT destination_id, selection_type FROM ?:sphinx_destination_whitelist'));
    }

    /**
     * Get country codes for given destination IDs.
     *
     * @param int[] $destinationIds
     * @return array<int, string> destination_id => country_code
     */
    public function getCountryCodesForDestinations(array $destinationIds): array
    {
        if (empty($destinationIds)) {
            return [];
        }
        $raw = db_get_hash_single_array(
            'SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id IN (?n)',
            ['destination_id', 'country_code'],
            $destinationIds,
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(int) $k] = TypeCoerce::toString($v);
        }
        return $out;
    }

    /**
     * Get all destination IDs for given country codes.
     *
     * @param string[] $countryCodes
     * @return list<int>
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        if (empty($countryCodes)) {
            return [];
        }
        return self::asIntList(db_get_fields(
            'SELECT destination_id FROM ?:sphinx_destinations WHERE country_code IN (?a)',
            $countryCodes,
        ));
    }

    /**
     * Count whitelist entries.
     */
    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_destination_whitelist'));
    }

    /**
     * Find country destination by country code.
     */
    public function findCountryDestination(string $countryCode): ?int
    {
        $id = TypeCoerce::toInt(db_get_field(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
            $countryCode,
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Insert a whitelist entry (ignore if exists).
     */
    public function insertIgnore(int $destinationId, string $selectionType = 'all'): void
    {
        db_query(
            'INSERT IGNORE INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, ?s)',
            $destinationId,
            $selectionType,
        );
    }

    /**
     * Replace the entire whitelist within a transaction.
     *
     * Clears all existing entries and inserts the new list atomically.
     *
     * @param array<array{destination_id: int, selection_type: string}> $entries
     * @throws \Exception On failure (transaction is rolled back)
     */
    public function replaceAll(array $entries): void
    {
        db_query('START TRANSACTION');
        try {
            db_query('DELETE FROM ?:sphinx_destination_whitelist');

            foreach ($entries as $entry) {
                $destId = $entry['destination_id'];
                $selType = $entry['selection_type'] === 'all' ? 'all' : 'specific';
                if ($destId > 0) {
                    db_query(
                        'INSERT INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, ?s)
                         ON DUPLICATE KEY UPDATE selection_type = ?s',
                        $destId,
                        $selType,
                        $selType,
                    );
                }
            }

            db_query('COMMIT');
        } catch (\Exception $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Get non-country child destination IDs whitelisted for a given country code.
     *
     * @param string $countryCode ISO country code
     * @return list<int>
     */
    public function getWhitelistedChildIdsByCountry(string $countryCode): array
    {
        return self::asIntList(db_get_fields(
            "SELECT w.destination_id FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code = ?s AND d.type != 'country'",
            $countryCode,
        ));
    }

    /**
     * Get whitelist type counts grouped by destination type.
     *
     * @return array<string, int> type => count (e.g. ['country' => 3, 'region' => 12])
     */
    public function getCountsByDestinationType(): array
    {
        $raw = db_get_hash_single_array(
            'SELECT d.type, COUNT(*) as cnt FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             GROUP BY d.type',
            ['type', 'cnt'],
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $out[$k] = TypeCoerce::toInt($v);
            }
        }
        return $out;
    }

    /**
     * Get sample non-country destination names from the whitelist.
     *
     * @param int $limit Max number of names to return
     * @return list<string>
     */
    public function getSampleNonCountryNames(int $limit = 5): array
    {
        return self::asStringList(db_get_fields(
            "SELECT d.name FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.type != 'country'
             ORDER BY d.name LIMIT ?i",
            $limit,
        ));
    }
}
