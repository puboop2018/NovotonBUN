<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Repository for sphinx_destination_whitelist table.
 *
 * Manages which destinations are enabled for sync.
 *
 * @since 1.2.0
 */
class DestinationWhitelistRepository
{
    /**
     * Get distinct country codes from whitelisted destinations.
     *
     * @return string[]
     */
    public function getCountryCodes(): array
    {
        $codes = db_get_fields(
            "SELECT DISTINCT d.country_code FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code != ''
             ORDER BY d.country_code"
        );
        return $codes ?: [];
    }

    /**
     * Get all whitelist entries.
     *
     * @return array{destination_id: int, selection_type: string}[]
     */
    public function findAll(): array
    {
        return db_get_array("SELECT destination_id, selection_type FROM ?:sphinx_destination_whitelist");
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
        return db_get_hash_single_array(
            "SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id IN (?n)",
            ['destination_id', 'country_code'],
            $destinationIds
        );
    }

    /**
     * Get all destination IDs for given country codes.
     *
     * @param string[] $countryCodes
     * @return int[]
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        if (empty($countryCodes)) {
            return [];
        }
        $ids = db_get_fields(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code IN (?a)",
            $countryCodes
        );
        return array_map('intval', $ids);
    }

    /**
     * Count whitelist entries.
     */
    public function count(): int
    {
        return (int) db_get_field("SELECT COUNT(*) FROM ?:sphinx_destination_whitelist");
    }

    /**
     * Find country destination by country code.
     */
    public function findCountryDestination(string $countryCode): ?int
    {
        $id = db_get_field(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
            $countryCode
        );
        return ($id !== false && $id !== '') ? (int) $id : null;
    }

    /**
     * Insert a whitelist entry (ignore if exists).
     */
    public function insertIgnore(int $destinationId, string $selectionType = 'all'): void
    {
        db_query(
            "INSERT IGNORE INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, ?s)",
            $destinationId, $selectionType
        );
    }
}
