<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Contracts\HotelRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_hotels table operations.
 *
 * Provides type-safe read/write access with batch upsert support.
 * Implements the provider-neutral travel_core contract so cross-provider
 * tooling can depend on the abstraction; everything beyond the contract
 * (sync, boards, skip/gate, SEO batches) is sphinx-specific surface.
 */
class HotelRepository implements HotelRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Core columns for hotel listing (excludes large JSON/TEXT columns).
     */
    private const string LISTING_COLUMNS = 'hotel_id, product_id, name, classification, property_type,
        destination_id, destination_name, region_id, region_name,
        country_code, country_name, latitude, longitude,
        image_url, is_recommended, is_adults_only, rating, rating_count,
        sync_status, last_synced_at, created_at, updated_at';

    /** Explicit column list for safe aliasing (no regex needed). */
    private const array LISTING_COLUMN_NAMES = [
        'hotel_id', 'product_id', 'name', 'classification', 'property_type',
        'destination_id', 'destination_name', 'region_id', 'region_name',
        'country_code', 'country_name', 'latitude', 'longitude',
        'image_url', 'is_recommended', 'is_adults_only', 'rating', 'rating_count',
        'sync_status', 'last_synced_at', 'created_at', 'updated_at',
    ];

    private const string STATUS_ACTIVE = 'active';
    private const string STATUS_INACTIVE = 'inactive';
    private const string STATUS_ERROR = 'error';

    private const array VALID_STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ERROR];

    /**
     * Skip reason set by the availability gate for hotels that have no
     * immediate-confirmation offer. Hotels carrying this reason are excluded
     * from product creation by findUnlinked(); it is cleared automatically once
     * the hotel returns immediate availability on a later sync.
     */
    public const string SKIP_REASON_NO_AVAILABILITY = 'no_availability';

    /**
     * Get listing columns prefixed with a table alias.
     */
    private function aliasedListingColumns(string $alias = 'h'): string
    {
        return implode(', ', array_map(
            static fn (string $col): string => $alias . '.' . $col,
            self::LISTING_COLUMN_NAMES,
        ));
    }

    /**
     * Upsert a batch of hotels (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param list<array<string, mixed>> $hotels Array of hotel rows
     * @return int Number of rows affected
     */
    public function upsertBatch(array $hotels): int
    {
        if (empty($hotels)) {
            return 0;
        }

        $affected = 0;
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            if ($hotelId === '') {
                continue;
            }

            db_query(
                "INSERT INTO ?:sphinx_hotels
                    (hotel_id, name, classification, property_type,
                     destination_id, destination_name, region_id, region_name,
                     country_code, country_name, latitude, longitude,
                     address, phone, email, website,
                     description, short_description, image_url,
                     images_json, facilities_json, is_adults_only,
                     rating, rating_count,
                     sync_status, last_synced_at)
                 VALUES (?s, ?s, ?i, ?s,
                     ?i, ?s, ?i, ?s,
                     ?s, ?s, ?d, ?d,
                     ?s, ?s, ?s, ?s,
                     ?s, ?s, ?s,
                     ?s, ?s, ?s,
                     ?d, ?i,
                     'active', ?s)
                 AS new_row
                 ON DUPLICATE KEY UPDATE
                    name = new_row.name,
                    classification = new_row.classification,
                    property_type = new_row.property_type,
                    destination_id = new_row.destination_id,
                    destination_name = new_row.destination_name,
                    region_id = new_row.region_id,
                    region_name = new_row.region_name,
                    country_code = new_row.country_code,
                    country_name = new_row.country_name,
                    latitude = new_row.latitude,
                    longitude = new_row.longitude,
                    address = new_row.address,
                    phone = new_row.phone,
                    email = new_row.email,
                    website = new_row.website,
                    description = new_row.description,
                    short_description = new_row.short_description,
                    image_url = new_row.image_url,
                    images_json = new_row.images_json,
                    facilities_json = new_row.facilities_json,
                    is_adults_only = new_row.is_adults_only,
                    rating = new_row.rating,
                    rating_count = new_row.rating_count,
                    sync_status = 'active',
                    last_synced_at = new_row.last_synced_at,
                    product_skip_reason = IF(
                        ?:sphinx_hotels.destination_name != new_row.destination_name
                        OR ?:sphinx_hotels.country_name != new_row.country_name
                        OR ?:sphinx_hotels.country_code != new_row.country_code,
                        NULL, ?:sphinx_hotels.product_skip_reason
                    ),
                    product_needs_update = IF(
                        ?:sphinx_hotels.product_id IS NOT NULL AND ?:sphinx_hotels.product_id > 0 AND (
                            ?:sphinx_hotels.name != new_row.name
                            OR ?:sphinx_hotels.description != new_row.description
                            OR ?:sphinx_hotels.short_description != new_row.short_description
                            OR ?:sphinx_hotels.classification != new_row.classification
                            OR ?:sphinx_hotels.image_url != new_row.image_url
                        ),
                        'Y', ?:sphinx_hotels.product_needs_update
                    )",
                $hotelId,
                TypeCoerce::toString($hotel['name'] ?? ''),
                TypeCoerce::toInt($hotel['classification'] ?? 0),
                TypeCoerce::toString($hotel['property_type'] ?? 'hotel'),
                TypeCoerce::toInt($hotel['destination_id'] ?? 0),
                TypeCoerce::toString($hotel['destination_name'] ?? ''),
                TypeCoerce::toInt($hotel['region_id'] ?? 0),
                TypeCoerce::toString($hotel['region_name'] ?? ''),
                TypeCoerce::toString($hotel['country_code'] ?? ''),
                TypeCoerce::toString($hotel['country_name'] ?? ''),
                TypeCoerce::toFloat($hotel['latitude'] ?? 0),
                TypeCoerce::toFloat($hotel['longitude'] ?? 0),
                TypeCoerce::toString($hotel['address'] ?? ''),
                TypeCoerce::toString($hotel['phone'] ?? ''),
                TypeCoerce::toString($hotel['email'] ?? ''),
                TypeCoerce::toString($hotel['website'] ?? ''),
                TypeCoerce::toString($hotel['description'] ?? ''),
                TypeCoerce::toString($hotel['short_description'] ?? ''),
                TypeCoerce::toString($hotel['image_url'] ?? ''),
                TypeCoerce::toString($hotel['images_json'] ?? '[]'),
                TypeCoerce::toString($hotel['facilities_json'] ?? '[]'),
                TypeCoerce::toString($hotel['is_adults_only'] ?? 'N'),
                TypeCoerce::toFloat($hotel['rating'] ?? 0),
                TypeCoerce::toInt($hotel['rating_count'] ?? 0),
                date('Y-m-d H:i:s'),
            );

            $affected++;
        }

        return $affected;
    }

    /**
     * Get hotels with optional filters.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getFiltered(
        string $countryCode = '',
        int $destinationId = 0,
        int $regionId = 0,
        string $syncStatus = '',
        string $query = '',
        int $page = 1,
        int $perPage = 50,
    ): array {
        $condition = '';

        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }
        if ($destinationId > 0) {
            $condition .= db_quote(' AND h.destination_id = ?i', $destinationId);
        }
        if ($regionId > 0) {
            $condition .= db_quote(' AND h.region_id = ?i', $regionId);
        }
        if ($syncStatus !== '') {
            $condition .= db_quote(' AND h.sync_status = ?s', $syncStatus);
        }
        if ($query !== '') {
            $escaped = addcslashes($query, '%_\\');
            $condition .= db_quote(' AND h.name LIKE ?l', '%' . $escaped . '%');
        }

        $total = (int) db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels h WHERE 1 ?p',
            $condition,
        );

        $offset = ($page - 1) * $perPage;

        $cols = $this->aliasedListingColumns();
        $items = self::asRowList(db_get_array(
            "SELECT {$cols} FROM ?:sphinx_hotels h
             WHERE 1 ?p
             ORDER BY h.country_code ASC, h.name ASC
             LIMIT ?i, ?i",
            $condition,
            $offset,
            $perPage,
        ));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get a single hotel by ID.
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function findById(string $hotelId): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:sphinx_hotels WHERE hotel_id = ?s',
            $hotelId,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Whether a hotel row exists for the given hotel id.
     */
    #[\Override]
    public function exists(string $hotelId): bool
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT 1 FROM ?:sphinx_hotels WHERE hotel_id = ?s',
            $hotelId,
        )) > 0;
    }

    /**
     * Get hotels by destination ID (excludes large JSON/TEXT columns).
     * @return list<array<string, mixed>>
     */
    public function getByDestination(int $destinationId): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LISTING_COLUMNS . ' FROM ?:sphinx_hotels WHERE destination_id = ?i ORDER BY name ASC',
            $destinationId,
        ));
    }

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
     * Distinct list of countries that have at least one hotel.
     *
     * Contract-named alias of getDistinctCountries().
     *
     * @return list<string>
     */
    #[\Override]
    public function getCountries(): array
    {
        return $this->getDistinctCountries();
    }

    /**
     * Get total number of active hotels.
     */
    public function getTotal(): int
    {
        return (int) db_get_field("SELECT COUNT(*) FROM ?:sphinx_hotels WHERE sync_status = 'active'");
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
     * Search hotels by name (excludes large JSON/TEXT columns).
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $escaped = addcslashes($query, '%_\\');
        return self::asRowList(db_get_array(
            'SELECT ' . self::LISTING_COLUMNS . ' FROM ?:sphinx_hotels WHERE name LIKE ?l ORDER BY country_code ASC, name ASC LIMIT ?i',
            '%' . $escaped . '%',
            $limit,
        ));
    }

    /**
     * Lightweight hotel name search for AJAX autocomplete.
     * Returns only the columns needed for the Select2 dropdown display.
     * @return list<array<string, mixed>>
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $escaped = addcslashes($query, '%_\\');
        return self::asRowList(db_get_array(
            'SELECT hotel_id, name, classification, country_code, destination_name
             FROM ?:sphinx_hotels
             WHERE name LIKE ?l
             ORDER BY country_code ASC, name ASC
             LIMIT ?i',
            '%' . $escaped . '%',
            $limit,
        ));
    }

    /**
     * Link a hotel to a CS-Cart product.
     *
     * @return bool True when the hotel row was updated.
     */
    #[\Override]
    public function linkToProduct(string $hotelId, int $productId): bool
    {
        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_id = ?i WHERE hotel_id = ?s',
            $productId,
            $hotelId,
        )) > 0;
    }

    /**
     * Remove the hotel↔product association for the given product.
     *
     * Sets product_id to NULL, which findUnlinked()/countLinked() treat as
     * unlinked (alongside the legacy 0 value).
     *
     * @return bool True when a hotel row was unlinked.
     */
    #[\Override]
    public function unlinkProduct(int $productId): bool
    {
        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_id = NULL WHERE product_id = ?i',
            $productId,
        )) > 0;
    }

    /**
     * Get hotels that have no images stored (images_json is null/empty/[]).
     * Used by enrich_hotel_data to backfill from the detail API.
     *
     * @return list<array<string, mixed>>
     */
    public function findMissingImages(string $countryCode = '', int $limit = 100): array
    {
        $cond = "(h.images_json IS NULL OR h.images_json = '' OR h.images_json = '[]')";
        if ($countryCode !== '') {
            $cond .= db_quote(' AND h.country_code = ?s', $countryCode);
        }
        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';

        return self::asRowList(db_get_array(
            "SELECT h.hotel_id, h.name, h.product_id
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' AND {$cond}
             ORDER BY h.hotel_id ASC {$limitClause}",
        ));
    }

    /**
     * Get unlinked hotels (no product_id) with optional country filter.
     *
     * @return list<array<string, mixed>> List of hotel rows without linked products
     */
    public function findUnlinked(string $countryCode = '', int $limit = 0): array
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        $limitClause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';
        $cols = $this->aliasedListingColumns();

        // Product creation needs TEXT/JSON columns excluded from LISTING_COLUMNS
        $extraCols = ', h.description, h.short_description, h.facilities_json, h.boards_json';

        return self::asRowList(db_get_array(
            "SELECT {$cols}{$extraCols} FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active'
               AND (h.product_id IS NULL OR h.product_id = 0)
               AND h.product_skip_reason IS NULL ?p
             ORDER BY h.country_code ASC, h.name ASC ?p",
            $condition,
            $limitClause,
        ));
    }

    /**
     * Count hotels that have a linked CS-Cart product.
     */
    public function countLinked(): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_id IS NOT NULL AND product_id > 0 AND sync_status = 'active'",
        );
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
     * Get hotel_id → hotel_id map for a given destination (for matching cache deals to hotels).
     *
     * @return array<string, string> hotel_id => hotel_id
     */
    public function getHotelIdsByDestination(int $destinationId): array
    {
        $raw = db_get_hash_single_array(
            "SELECT hotel_id, hotel_id FROM ?:sphinx_hotels WHERE destination_id = ?i AND sync_status = 'active'",
            ['hotel_id', 'hotel_id'],
            $destinationId,
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $out[$k] = TypeCoerce::toString($v);
            }
        }
        return $out;
    }

    /**
     * Update boards_json for a batch of hotels.
     *
     * @param array<string, list<string>> $boardsByHotel hotel_id => array of canonical board codes
     * @return int Number of hotels updated
     */
    public function updateBoardsBatch(array $boardsByHotel): int
    {
        $updated = 0;
        foreach ($boardsByHotel as $hotelId => $boards) {
            $encoded = !empty($boards)
                ? json_encode(array_values(array_unique($boards)))
                : false;
            $json = is_string($encoded) ? $encoded : '[]';
            db_query(
                'UPDATE ?:sphinx_hotels SET boards_json = ?s WHERE hotel_id = ?s',
                $json,
                (string) $hotelId,
            );
            $updated++;
        }
        return $updated;
    }

    /**
     * Get hotels that have boards_json AND a linked product.
     *
     * Returns all fields needed by SphinxFeatureAssigner::assignAll().
     *
     * @param string $countryCode Optional country filter
     * @param int $limit Max rows (0 = unlimited)
     * @param int $offset Starting offset for pagination
     * @return list<array<string, mixed>> List of hotel rows
     */
    public function findWithBoardsAndProduct(string $countryCode = '', int $limit = 0, int $offset = 0): array
    {
        $condition = ' AND h.boards_json IS NOT NULL AND h.product_id IS NOT NULL AND h.product_id > 0';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND h.country_code = ?s', $countryCode);
        }

        $limitClause = '';
        if ($limit > 0) {
            $limitClause = db_quote(' LIMIT ?i, ?i', $offset, $limit);
        } elseif ($offset > 0) {
            // MySQL max BIGINT UNSIGNED — effectively "no limit, offset only"
            $limitClause = db_quote(' LIMIT ?i, 18446744073709551615', $offset);
        }

        return self::asRowList(db_get_array(
            "SELECT h.hotel_id, h.product_id, h.boards_json, h.name,
                    h.classification, h.property_type, h.destination_name,
                    h.facilities_json, h.country_code
             FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p
             ORDER BY h.hotel_id ASC ?p",
            $condition,
            $limitClause,
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

        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.sync_status = 'active' ?p",
            $condition,
        );
    }

    /**
     * Mark hotels as inactive if they weren't touched since the given timestamp.
     *
     * Replaces markInactiveExcept() for scalability: uses a single indexed WHERE clause
     * instead of NOT IN (100k IDs). Works because upsertBatch() sets last_synced_at = NOW()
     * on every hotel it touches, so any hotel with last_synced_at < $syncStartedAt was not
     * in the API response and is therefore stale.
     *
     * @param string $syncStartedAt Timestamp recorded before sync started (Y-m-d H:i:s)
     * @param string $countryCode Country code to scope the update
     * @return int Number of rows marked inactive
     */
    public function markInactiveBefore(string $syncStartedAt, string $countryCode): int
    {
        if ($countryCode === '') {
            return 0;
        }

        return (int) db_query(
            "UPDATE ?:sphinx_hotels SET sync_status = 'inactive'
             WHERE country_code = ?s AND sync_status = 'active' AND last_synced_at < ?s",
            $countryCode,
            $syncStartedAt,
        );
    }

    /**
     * Mark a hotel as skipped for product creation with a reason.
     *
     * Skipped hotels are excluded from findUnlinked() on subsequent runs.
     * Reset with resetSkipped() to retry.
     */
    public function markSkipped(string $hotelId, string $reason): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s WHERE hotel_id = ?s',
            $reason,
            $hotelId,
        );
    }

    /**
     * Find hotels eligible for the availability gate within the given destinations.
     *
     * Candidates are active hotels with no CS-Cart product yet and either no
     * skip reason or the availability gate's own reason — so the gate can both
     * newly flag unavailable hotels and clear hotels that became available,
     * while never touching linked products or hotels skipped for other reasons.
     *
     * @param int[] $destinationIds
     * @return list<array<string, mixed>> Rows of {hotel_id, destination_id, product_skip_reason}
     */
    public function findAvailabilityGateCandidates(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        return self::asRowList(db_get_array(
            'SELECT hotel_id, destination_id, product_skip_reason
             FROM ?:sphinx_hotels
             WHERE destination_id IN (?n)
               AND sync_status = ?s
               AND (product_id IS NULL OR product_id = 0)
               AND (product_skip_reason IS NULL OR product_skip_reason = ?s)',
            $destinationIds,
            self::STATUS_ACTIVE,
            self::SKIP_REASON_NO_AVAILABILITY,
        ));
    }

    /**
     * Set a skip reason on hotels that do not already carry one.
     *
     * Only NULL skip reasons are overwritten, so hotels skipped for other
     * reasons (invalid_country, no_description, ...) are left untouched.
     *
     * @param string[] $hotelIds
     * @return int Number of hotels updated
     */
    public function markSkippedBatch(array $hotelIds, string $reason): int
    {
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s
             WHERE hotel_id IN (?a) AND product_skip_reason IS NULL',
            $reason,
            $hotelIds,
        ));
    }

    /**
     * Clear a specific skip reason from hotels, making them eligible again.
     *
     * Scoped to the given reason so unrelated skips are preserved.
     *
     * @param string[] $hotelIds
     * @return int Number of hotels cleared
     */
    public function clearSkipReasonBatch(array $hotelIds, string $reason): int
    {
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE hotel_id IN (?a) AND product_skip_reason = ?s',
            $hotelIds,
            $reason,
        ));
    }

    /**
     * Reset skip reason for hotels, making them eligible for product creation again.
     *
     * @return int Number of hotels reset
     */
    public function resetSkipped(string $countryCode = '', string $reason = ''): int
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= db_quote(' AND country_code = ?s', $countryCode);
        }
        if ($reason !== '') {
            $condition .= db_quote(' AND product_skip_reason = ?s', $reason);
        }

        return (int) db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE product_skip_reason IS NOT NULL ?p',
            $condition,
        );
    }

    /**
     * Count hotels that have been skipped for product creation.
     */
    public function countSkipped(string $reason = ''): int
    {
        $condition = '';
        if ($reason !== '') {
            $condition = db_quote(' AND product_skip_reason = ?s', $reason);
        }
        return (int) db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_skip_reason IS NOT NULL ?p',
            $condition,
        );
    }

    /**
     * Bulk update sync_status for a list of hotel IDs.
     *
     * @param string[] $hotelIds Hotel IDs to update
     * @param string $status Target status (active, inactive, error)
     * @return int Number of rows affected
     */
    public function bulkUpdateStatus(array $hotelIds, string $status): int
    {
        if (empty($hotelIds) || !in_array($status, self::VALID_STATUSES, true)) {
            return 0;
        }

        return (int) db_query(
            'UPDATE ?:sphinx_hotels SET sync_status = ?s WHERE hotel_id IN (?a)',
            $status,
            $hotelIds,
        );
    }

    /**
     * Delete a single hotel by ID.
     *
     * Hotel data is self-contained on the row (boards/images/facilities are
     * JSON columns), so there are no child rows to cascade.
     *
     * @return bool True when the hotel existed and was deleted.
     */
    #[\Override]
    public function delete(string $hotelId): bool
    {
        return $this->bulkDelete([$hotelId]) > 0;
    }

    /**
     * Bulk delete hotels by ID.
     *
     * @param string[] $hotelIds Hotel IDs to delete
     * @return int Number of rows deleted
     */
    public function bulkDelete(array $hotelIds): int
    {
        if (empty($hotelIds)) {
            return 0;
        }

        return (int) db_query(
            'DELETE FROM ?:sphinx_hotels WHERE hotel_id IN (?a)',
            $hotelIds,
        );
    }

    /**
     * Fetch a batch of active linked hotels for SEO bulk-apply.
     *
     * Returns hotel rows with location data needed for placeholder building.
     *
     * @param int $offset Starting offset
     * @param int $batch Batch size
     * @return list<array<string, mixed>>
     */
    public function fetchLinkedBatchForSeo(int $offset, int $batch): array
    {
        return self::asRowList(db_get_array(
            "SELECT h.hotel_id, h.product_id, h.name, h.classification, h.property_type,
                    h.description, h.rating, h.facilities_json, h.boards_json,
                    h.latitude, h.longitude, h.image_url, h.address, h.phone, h.email, h.website,
                    h.destination_name, h.country_name, h.region_name
             FROM ?:sphinx_hotels h
             WHERE h.product_id IS NOT NULL AND h.product_id > 0
               AND h.sync_status = 'active'
             LIMIT ?i, ?i",
            $offset,
            $batch,
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
        return (int) db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels h
             WHERE h.product_id > 0
             AND NOT EXISTS (SELECT 1 FROM ?:products p WHERE p.product_id = h.product_id)',
        );
    }

    /**
     * Update image URL and images JSON for a hotel.
     *
     * Used when fresh image data is fetched from the API during image sync.
     *
     * @param string $hotelId The hotel ID
     * @param string $imageUrl Primary image URL
     * @param string $imagesJson JSON-encoded array of image data
     */
    public function updateImages(string $hotelId, string $imageUrl, string $imagesJson): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET image_url = ?s, images_json = ?s WHERE hotel_id = ?s',
            $imageUrl,
            $imagesJson,
            $hotelId,
        );
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
