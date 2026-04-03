<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotel Package Repository
 *
 * Centralized database access for the novoton_hotel_packages table.
 * Consolidates 53+ scattered db_* calls into a single repository.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

class HotelPackageRepository implements HotelPackageRepositoryInterface
{
    /**
     * Listing columns (excludes large priceinfo_data JSON).
     */
    private const LISTING_COLUMNS = 'id, hotel_id, package_id, package_name, seasons_count,
        has_early_booking, min_price, currency, needs_price_compute, synced_at, created_at, updated_at';

    public function findByHotelId(string $hotelId): array
    {
        return db_get_array(
            "SELECT " . self::LISTING_COLUMNS . " FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
            $hotelId
        );
    }

    /**
     * Find all packages for a hotel including full priceinfo_data JSON.
     * Use only when the caller needs to process pricing data.
     */
    public function findByHotelIdFull(string $hotelId): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name",
            $hotelId
        );
    }

    public function findByHotelAndPackageId(string $hotelId, string $packageId): ?array
    {
        $row = db_get_row(
            "SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
            $hotelId, $packageId
        );
        return $row ?: null;
    }

    public function findByHotelAndPackageName(string $hotelId, string $packageName): ?array
    {
        $row = db_get_row(
            "SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_name = ?s",
            $hotelId, $packageName
        );
        return $row ?: null;
    }

    public function exists(string $hotelId, string $packageId): bool
    {
        return (bool) db_get_field(
            "SELECT 1 FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
            $hotelId, $packageId
        );
    }

    public function upsert(string $hotelId, string $packageId, array $data): bool
    {
        $data['hotel_id'] = $hotelId;
        $data['package_id'] = $packageId;
        // PHP 8.1+: filter null values to prevent real_escape_string deprecation
        $data = array_filter($data, static fn($v) => $v !== null);

        $existingId = db_get_field(
            "SELECT id FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s",
            $hotelId, $packageId
        );

        if ($existingId) {
            return (bool) db_query(
                "UPDATE ?:novoton_hotel_packages SET ?u WHERE id = ?i",
                $data, (int) $existingId
            );
        }

        return (bool) db_query("INSERT INTO ?:novoton_hotel_packages ?e", $data);
    }

    public function deleteByHotelId(string $hotelId): int
    {
        return (int) db_query(
            "DELETE FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
            $hotelId
        );
    }

    public function findEarlyBookingPackage(string $hotelId): ?array
    {
        $row = db_get_row(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND has_early_booking = 'Y' AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );
        return $row ?: null;
    }

    public function countByHotelId(string $hotelId): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
            $hotelId
        );
    }

    public function findForListing(string $hotelId): array
    {
        return db_get_array(
            "SELECT " . self::LISTING_COLUMNS . " FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s ORDER BY package_name",
            $hotelId
        );
    }

    public function getPriceinfoData(string $hotelId, ?string $packageName = null): ?string
    {
        if ($packageName !== null) {
            $data = db_get_field(
                "SELECT priceinfo_data FROM ?:novoton_hotel_packages
                 WHERE hotel_id = ?s AND package_name = ?s
                 ORDER BY synced_at DESC LIMIT 1",
                $hotelId, $packageName
            );
        } else {
            $data = db_get_field(
                "SELECT priceinfo_data FROM ?:novoton_hotel_packages
                 WHERE hotel_id = ?s
                 ORDER BY synced_at DESC LIMIT 1",
                $hotelId
            );
        }
        return ($data !== false && $data !== '') ? (string) $data : null;
    }

    public function getLastSyncedAt(string $hotelId): ?string
    {
        $val = db_get_field(
            "SELECT MAX(synced_at) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s",
            $hotelId
        );
        return ($val !== false && $val !== '') ? (string) $val : null;
    }

    public function getActivePackageName(string $hotelId): ?string
    {
        $val = db_get_field(
            "SELECT package_name FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );
        return ($val !== false && $val !== '') ? (string) $val : null;
    }

    public function getLatestPriceinfoData(string $hotelId): ?string
    {
        $val = db_get_field(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId
        );
        return ($val !== false && $val !== '') ? (string) $val : null;
    }

    public function getAllPriceinfoData(string $hotelId): array
    {
        return db_get_fields(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL",
            $hotelId
        );
    }
}
