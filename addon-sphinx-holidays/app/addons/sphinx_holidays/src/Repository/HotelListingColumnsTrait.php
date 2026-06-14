<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Shared definition of the sphinx_hotels "listing" column set — the core
 * columns used for hotel lists/search (excludes the large JSON/TEXT columns).
 *
 * Lives in a trait so HotelRepository and HotelSearchRepository share one
 * source of truth for the column list and its aliased form, rather than
 * duplicating the constants across the two repositories.
 */
trait HotelListingColumnsTrait
{
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
}
