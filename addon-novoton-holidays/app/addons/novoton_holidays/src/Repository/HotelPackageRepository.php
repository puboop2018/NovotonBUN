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

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

class HotelPackageRepository implements HotelPackageRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Listing columns (excludes large priceinfo_data JSON).
     */
    private const string LISTING_COLUMNS = 'id, hotel_id, package_id, package_name, seasons_count,
        has_early_booking, min_price, currency, needs_price_compute, synced_at, created_at, updated_at';

    /**
     * @return list<array<string, mixed>>
     */
    public function findByHotelId(string $hotelId): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LISTING_COLUMNS . ' FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name',
            $hotelId,
        ));
    }

    /**
     * Find all packages for a hotel including full priceinfo_data JSON.
     * Use only when the caller needs to process pricing data.
     * @return list<array<string, mixed>>
     */
    public function findByHotelIdFull(string $hotelId): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name',
            $hotelId,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHotelAndPackageId(string $hotelId, string $packageId): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s',
            $hotelId,
            $packageId,
        ));
        return $row === [] ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHotelAndPackageName(string $hotelId, string $packageName): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_name = ?s',
            $hotelId,
            $packageName,
        ));
        return $row === [] ? null : $row;
    }

    public function exists(string $hotelId, string $packageId): bool
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT 1 FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s',
            $hotelId,
            $packageId,
        )) > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(string $hotelId, string $packageId, array $data): bool
    {
        $data['hotel_id'] = $hotelId;
        $data['package_id'] = $packageId;
        // PHP 8.1+: filter null values to prevent real_escape_string deprecation
        $data = array_filter($data, static fn ($v): bool => $v !== null);

        $existingId = TypeCoerce::toInt(db_get_field(
            'SELECT id FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_id = ?s',
            $hotelId,
            $packageId,
        ));

        if ($existingId > 0) {
            return (bool) db_query(
                'UPDATE ?:novoton_hotel_packages SET ?u WHERE id = ?i',
                $data,
                $existingId,
            );
        }

        return (bool) db_query('INSERT INTO ?:novoton_hotel_packages ?e', $data);
    }

    public function deleteByHotelId(string $hotelId): int
    {
        return TypeCoerce::toInt(db_query(
            'DELETE FROM ?:novoton_hotel_packages WHERE hotel_id = ?s',
            $hotelId,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEarlyBookingPackage(string $hotelId): ?array
    {
        $row = self::asRow(db_get_row(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND has_early_booking = 'Y' AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1",
            $hotelId,
        ));
        return $row === [] ? null : $row;
    }

    public function countByHotelId(string $hotelId): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s',
            $hotelId,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForListing(string $hotelId): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LISTING_COLUMNS . ' FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s ORDER BY package_name',
            $hotelId,
        ));
    }

    public function getPriceinfoData(string $hotelId, ?string $packageName = null): ?string
    {
        if ($packageName !== null) {
            $data = TypeCoerce::toString(db_get_field(
                'SELECT priceinfo_data FROM ?:novoton_hotel_packages
                 WHERE hotel_id = ?s AND package_name = ?s
                 ORDER BY synced_at DESC LIMIT 1',
                $hotelId,
                $packageName,
            ));
        } else {
            $data = TypeCoerce::toString(db_get_field(
                'SELECT priceinfo_data FROM ?:novoton_hotel_packages
                 WHERE hotel_id = ?s
                 ORDER BY synced_at DESC LIMIT 1',
                $hotelId,
            ));
        }
        return $data === '' ? null : $data;
    }

    public function getLastSyncedAt(string $hotelId): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT MAX(synced_at) FROM ?:novoton_hotel_packages WHERE hotel_id = ?s',
            $hotelId,
        ));
        return $val === '' ? null : $val;
    }

    public function getActivePackageName(string $hotelId): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT package_name FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1',
            $hotelId,
        ));
        return $val === '' ? null : $val;
    }

    public function getLatestPriceinfoData(string $hotelId): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY synced_at DESC LIMIT 1',
            $hotelId,
        ));
        return $val === '' ? null : $val;
    }

    /**
     * @return list<string>
     */
    public function getAllPriceinfoData(string $hotelId): array
    {
        return self::asStringList(db_get_fields(
            'SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL',
            $hotelId,
        ));
    }

    /**
     * Get package names with priceinfo data for a hotel (for AJAX dropdown).
     * @return list<array<string, mixed>>
     */
    public function findPackageNamesWithPriceinfo(string $hotelId): array
    {
        return self::asRowList(db_get_array(
            'SELECT package_name FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND priceinfo_data IS NOT NULL
             ORDER BY package_name',
            $hotelId,
        ));
    }

    /**
     * Get the first package name for a hotel (alphabetical order).
     */
    public function getFirstPackageName(string $hotelId): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name LIMIT 1',
            $hotelId,
        ));
        return $val === '' ? null : $val;
    }

    /**
     * Get package_id and package_name pairs for a hotel.
     * @return list<array<string, mixed>>
     */
    public function getPackageIdNamePairs(string $hotelId): array
    {
        return self::asRowList(db_get_array(
            'SELECT package_id, package_name FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name',
            $hotelId,
        ));
    }

    /**
     * Get package listing data for hotel detail view.
     * @return list<array<string, mixed>>
     */
    public function findForHotelDetail(string $hotelId): array
    {
        return self::asRowList(db_get_array(
            'SELECT package_id, package_name, min_price, has_early_booking, synced_at
             FROM ?:novoton_hotel_packages WHERE hotel_id = ?s ORDER BY package_name',
            $hotelId,
        ));
    }

    /**
     * Insert or update a package by hotel_id + package_id (simple upsert via ON DUPLICATE KEY).
     */
    public function upsertByHotelAndPackage(string $hotelId, string $packageId, string $packageName): bool
    {
        return (bool) db_query(
            'INSERT INTO ?:novoton_hotel_packages (hotel_id, package_id, package_name, created_at)
             VALUES (?s, ?s, ?s, NOW())
             ON DUPLICATE KEY UPDATE package_name = ?s',
            $hotelId,
            $packageId,
            $packageName,
            $packageName,
        );
    }
}
