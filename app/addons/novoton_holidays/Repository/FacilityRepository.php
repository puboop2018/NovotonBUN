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
        $name_field = ($lang == 'ro') ? 'facility_name_ro' : 'facility_name_en';
        return db_get_array("SELECT facility_id, {$name_field} as facility_name FROM ?:novoton_facilities ORDER BY {$name_field}");
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
        $name_field = ($lang == 'ro') ? 'facility_name_ro' : 'facility_name_en';
        
        return db_get_array(
            "SELECT f.facility_id, f.{$name_field} as facility_name
             FROM ?:novoton_hotel_facilities hf
             LEFT JOIN ?:novoton_facilities f ON hf.facility_id = f.facility_id
             WHERE hf.hotel_id = ?s
             ORDER BY f.{$name_field}",
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
        $this->clearHotelFacilities($hotel_id);
        
        foreach ($facility_ids as $facility_id) {
            $this->linkToHotel($hotel_id, (int) $facility_id);
        }
        
        return true;
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
}
