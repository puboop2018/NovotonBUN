<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Facility Repository
 * 
 * Centralized database access for facilities data.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

class FacilityRepository implements FacilityRepositoryInterface
{
    /** @var array<string, string> Allowed facility name columns by language */
    private const NAME_COLUMNS = [
        'ro' => 'facility_name_ro',
        'en' => 'facility_name_en',
    ];

    private static function nameField(string $lang): string
    {
        return self::NAME_COLUMNS[$lang] ?? self::NAME_COLUMNS['en'];
    }

    /**
     * Find facility by ID
     */
    public function findById(int $facility_id): ?array
    {
        $facility = db_get_row("SELECT * FROM ?:novoton_facilities WHERE facility_id = ?i", $facility_id);
        return $facility ?: null;
    }

    /**
     * Find all facilities
     */
    public function findAll(string $lang = 'en'): array
    {
        $col = self::nameField($lang);
        return db_get_array("SELECT facility_id, {$col} as facility_name FROM ?:novoton_facilities ORDER BY {$col}");
    }
    
    /**
     * Check if facility exists
     */
    public function exists(int $facility_id): bool
    {
        return (bool) db_get_field("SELECT 1 FROM ?:novoton_facilities WHERE facility_id = ?i", $facility_id);
    }
    
    /**
     * Save facility (insert or update)
     */
    public function save(int $facility_id, string $name_en, string $name_ro = ''): bool
    {
        if (empty($name_ro)) {
            $name_ro = $name_en;
        }
        
        if ($this->exists($facility_id)) {
            return (bool) db_query(
                "UPDATE ?:novoton_facilities SET facility_name_en = ?s, facility_name_ro = ?s WHERE facility_id = ?i",
                $name_en, $name_ro, $facility_id
            );
        }
        
        return (bool) db_query(
            "INSERT INTO ?:novoton_facilities (facility_id, facility_name_en, facility_name_ro) VALUES (?i, ?s, ?s)",
            $facility_id, $name_en, $name_ro
        );
    }
    
    /**
     * Delete facility
     */
    public function delete(int $facility_id): bool
    {
        // Delete hotel links first
        db_query("DELETE FROM ?:novoton_hotel_facilities WHERE facility_id = ?i", $facility_id);
        return (bool) db_query("DELETE FROM ?:novoton_facilities WHERE facility_id = ?i", $facility_id);
    }
    
    /**
     * Count facilities
     */
    public function count(): int
    {
        return (int) db_get_field("SELECT COUNT(*) FROM ?:novoton_facilities");
    }
    
    // =========================================================================
    // Hotel-Facility Links
    // =========================================================================
    
    /**
     * Get facilities for a hotel
     */
    public function getForHotel(string $hotel_id, string $lang = 'en'): array
    {
        $col = self::nameField($lang);

        return db_get_array(
            "SELECT f.facility_id, f.{$col} as facility_name
             FROM ?:novoton_hotel_facilities hf
             LEFT JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s
             ORDER BY f.{$col}",
            $hotel_id
        );
    }
    
    /**
     * Get facility IDs for a hotel
     */
    public function getIdsForHotel(string $hotel_id): array
    {
        return db_get_fields("SELECT facility_id FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s", $hotel_id);
    }
    
    /**
     * Link facility to hotel
     */
    public function linkToHotel(string $hotel_id, int $facility_id): bool
    {
        return (bool) db_query(
            "INSERT IGNORE INTO ?:novoton_hotel_facilities (hotel_id, facility_id) VALUES (?s, ?i)",
            $hotel_id, $facility_id
        );
    }
    
    /**
     * Unlink facility from hotel
     */
    public function unlinkFromHotel(string $hotel_id, int $facility_id): bool
    {
        return (bool) db_query(
            "DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s AND facility_id = ?i",
            $hotel_id, $facility_id
        );
    }
    
    /**
     * Clear all facilities for a hotel
     */
    public function clearHotelFacilities(string $hotel_id): bool
    {
        return (bool) db_query("DELETE FROM ?:novoton_hotel_facilities WHERE hotel_id = ?s", $hotel_id);
    }
    
    /**
     * Set facilities for a hotel (replace all)
     */
    public function setHotelFacilities(string $hotel_id, array $facility_ids): bool
    {
        db_query("START TRANSACTION");
        try {
            $this->clearHotelFacilities($hotel_id);

            foreach ($facility_ids as $facility_id) {
                $this->linkToHotel($hotel_id, (int) $facility_id);
            }

            db_query("COMMIT");
            return true;
        } catch (\Exception $e) {
            db_query("ROLLBACK");
            throw $e;
        }
    }
    
    /**
     * Count hotels with a specific facility
     */
    public function countHotelsWithFacility(int $facility_id): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotel_facilities WHERE facility_id = ?i",
            $facility_id
        );
    }

    // =========================================================================
    // Type-filtered queries
    // =========================================================================

    /**
     * Get facilities for a hotel filtered by feature type (hotel_facility, room_facility, travel_group, beach_access)
     */
    public function getForHotelByType(string $hotel_id, string $facility_type, string $lang = 'en'): array
    {
        $col = self::nameField($lang);

        return db_get_array(
            "SELECT f.facility_id, f.{$col} as facility_name
             FROM ?:novoton_hotel_facilities hf
             JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s AND f.facility_type = ?s
             ORDER BY f.{$col}",
            $hotel_id, $facility_type
        );
    }

    /**
     * Get all facilities for a hotel, grouped by feature type.
     * Returns ['hotel_facility' => [['facility_id' => 1, ...], ...], 'travel_group' => [...], ...]
     */
    public function getForHotelGroupedByType(string $hotel_id, string $lang = 'en'): array
    {
        $col = self::nameField($lang);

        $rows = db_get_array(
            "SELECT f.facility_id, f.facility_type, f.{$col} as facility_name
             FROM ?:novoton_hotel_facilities hf
             JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s
             ORDER BY f.facility_type, f.{$col}",
            $hotel_id
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['facility_type']][] = $row;
        }
        return $grouped;
    }

    /**
     * Update facility feature type mapping
     */
    public function updateType(int $facility_id, string $facility_type): bool
    {
        return (bool) db_query(
            "UPDATE ?:novoton_facilities SET facility_type = ?s WHERE facility_id = ?i",
            $facility_type, $facility_id
        );
    }
}
