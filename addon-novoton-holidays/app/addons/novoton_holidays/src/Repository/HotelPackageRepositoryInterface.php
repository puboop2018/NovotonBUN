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
     * @param string $hotelId
     * @return array
     */
    public function findByHotelId(string $hotelId): array;

    /**
     * Find all packages for a hotel including full priceinfo_data JSON.
     * Use only when the caller needs to process pricing data.
     *
     * @param string $hotelId
     * @return array
     */
    public function findByHotelIdFull(string $hotelId): array;

    /**
     * Find a specific package by hotel + package ID.
     *
     * @param string $hotelId
     * @param string $packageId
     * @return array|null
     */
    public function findByHotelAndPackageId(string $hotelId, string $packageId): ?array;

    /**
     * Find by hotel + package name.
     *
     * @param string $hotelId
     * @param string $packageName
     * @return array|null
     */
    public function findByHotelAndPackageName(string $hotelId, string $packageName): ?array;

    /**
     * Check if a package exists.
     *
     * @param string $hotelId
     * @param string $packageId
     * @return bool
     */
    public function exists(string $hotelId, string $packageId): bool;

    /**
     * Upsert (insert or update) a package.
     *
     * @param string $hotelId
     * @param string $packageId
     * @param array  $data
     * @return bool
     */
    public function upsert(string $hotelId, string $packageId, array $data): bool;

    /**
     * Delete all packages for a hotel.
     *
     * @param string $hotelId
     * @return int Number of rows deleted
     */
    public function deleteByHotelId(string $hotelId): int;

    /**
     * Get the first package with an early booking discount for a hotel.
     *
     * @param string $hotelId
     * @return array|null The priceinfo_data row, or null
     */
    public function findEarlyBookingPackage(string $hotelId): ?array;

    /**
     * Count packages for a hotel.
     *
     * @param string $hotelId
     * @return int
     */
    public function countByHotelId(string $hotelId): int;

    /**
     * Get packages for listing (excludes large priceinfo_data JSON).
     *
     * @param string $hotelId
     * @return array
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
     * @return string[] Array of JSON priceinfo_data strings
     */
    public function getAllPriceinfoData(string $hotelId): array;
}
