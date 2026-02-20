<?php
/**
 * Facility Repository Interface
 *
 * Contract for hotel facility data access operations.
 *
 * @package NovotonHolidays
 * @since 3.2.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

interface FacilityRepositoryInterface
{
    public function findById(int $facility_id): ?array;

    public function findAll(string $lang = 'en'): array;

    public function exists(int $facility_id): bool;

    public function save(int $facility_id, string $name_en, string $name_ro = ''): bool;

    public function delete(int $facility_id): bool;

    public function count(): int;

    public function getForHotel(string $hotel_id, string $lang = 'en'): array;

    public function getIdsForHotel(string $hotel_id): array;

    public function linkToHotel(string $hotel_id, int $facility_id): bool;

    public function unlinkFromHotel(string $hotel_id, int $facility_id): bool;

    public function clearHotelFacilities(string $hotel_id): bool;

    public function setHotelFacilities(string $hotel_id, array $facility_ids): bool;

    public function countHotelsWithFacility(int $facility_id): int;
}
