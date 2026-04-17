<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * Interface for hotel package data access.
 *
 * Centralizes all queries against the novoton_hotel_packages table.
 *
 * @since 3.5.0
 */
interface HotelPackageRepositoryInterface
{
    /**
     * Find all packages for a hotel (excludes large priceinfo_data JSON).
     *
     * @return list<array<string, mixed>>
     */
    public function findByHotelId(string $hotelId): array;

    /**
     * Find all packages for a hotel including full priceinfo_data JSON.
     * Use only when the caller needs to process pricing data.
     *
     * @return list<array<string, mixed>>
     */
    public function findByHotelIdFull(string $hotelId): array;

    /**
     * Find a specific package by hotel + package ID.
     *
     * @return array<string, mixed>|null
     */
    public function findByHotelAndPackageId(string $hotelId, string $packageId): ?array;

    /**
     * Find by hotel + package name.
     *
     * @return array<string, mixed>|null
     */
    public function findByHotelAndPackageName(string $hotelId, string $packageName): ?array;

    /**
     * Check if a package exists.
     */
    public function exists(string $hotelId, string $packageId): bool;

    /**
     * Upsert (insert or update) a package.
     *
     * @param array<string, mixed> $data
     */
    public function upsert(string $hotelId, string $packageId, array $data): bool;

    /**
     * Delete all packages for a hotel.
     *
     * @return int Number of rows deleted
     */
    public function deleteByHotelId(string $hotelId): int;

    /**
     * Get the first package with an early booking discount for a hotel.
     *
     * @return array<string, mixed>|null The priceinfo_data row, or null
     */
    public function findEarlyBookingPackage(string $hotelId): ?array;

    /**
     * Count packages for a hotel.
     */
    public function countByHotelId(string $hotelId): int;

    /**
     * Get packages for listing (excludes large priceinfo_data JSON).
     *
     * @return list<array<string, mixed>>
     */
    public function findForListing(string $hotelId): array;

    /**
     * Get priceinfo_data for a hotel, optionally filtered by package name.
     *
     * @return string|null JSON priceinfo_data or null
     */
    public function getPriceinfoData(string $hotelId, ?string $packageName = null): ?string;

    /**
     * Get the most recent synced_at timestamp for a hotel's packages.
     */
    public function getLastSyncedAt(string $hotelId): ?string;

    /**
     * Get the package name of the most recently synced package with priceinfo data.
     */
    public function getActivePackageName(string $hotelId): ?string;

    /**
     * Get the priceinfo_data from the most recently synced package.
     */
    public function getLatestPriceinfoData(string $hotelId): ?string;

    /**
     * Get all priceinfo_data rows for a hotel (non-null only).
     *
     * @return list<string> Array of JSON priceinfo_data strings
     */
    public function getAllPriceinfoData(string $hotelId): array;

    /**
     * Get package names with priceinfo data for a hotel (for AJAX dropdown).
     * @return list<array<string, mixed>>
     */
    public function findPackageNamesWithPriceinfo(string $hotelId): array;

    /**
     * Get the first package name for a hotel (alphabetical order).
     */
    public function getFirstPackageName(string $hotelId): ?string;

    /**
     * Get package_id and package_name pairs for a hotel.
     * @return list<array<string, mixed>>
     */
    public function getPackageIdNamePairs(string $hotelId): array;

    /**
     * Get package listing data for hotel detail view.
     * @return list<array<string, mixed>>
     */
    public function findForHotelDetail(string $hotelId): array;

    /**
     * Insert or update a package by hotel_id + package_id (simple upsert via ON DUPLICATE KEY).
     */
    public function upsertByHotelAndPackage(string $hotelId, string $packageId, string $packageName): bool;
}
