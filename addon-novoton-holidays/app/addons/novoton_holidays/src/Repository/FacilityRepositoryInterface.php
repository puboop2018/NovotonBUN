<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface FacilityRepositoryInterface
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public function findById(int $facility_id): ?array;
    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(string $lang = 'en'): array;
    /**
     * @return list<array<string, mixed>>
     */
    public function findAllFull(): array;
    public function exists(int $facility_id): bool;
    public function save(int $facility_id, string $name_en, string $name_ro = ''): bool;
    public function delete(int $facility_id): bool;
    public function count(): int;
    /**
     * @return array<string, mixed>
     */
    public function getForHotel(string $hotel_id, string $lang = 'en'): array;
    /**
     * @return array<string, mixed>
     */
    public function getIdsForHotel(string $hotel_id): array;
    public function linkToHotel(string $hotel_id, int $facility_id): bool;
    public function clearHotelFacilities(string $hotel_id): bool;
    /**
     * @param list<int> $facility_ids
     */
    public function setHotelFacilities(string $hotel_id, array $facility_ids): bool;
    public function unlinkFromHotel(string $hotel_id, int $facility_id): bool;
    public function countHotelsWithFacility(int $facility_id): int;
    /**
     * @return array<string, mixed>
     */
    public function getForHotelByType(string $hotel_id, string $facility_type, string $lang = 'en'): array;
    /**
     * @return array<string, mixed>
     */
    public function getForHotelGroupedByType(string $hotel_id, string $lang = 'en'): array;
    public function updateType(int $facility_id, string $facility_type): bool;
    public function updateTypeAndTranslation(int $facility_id, string $facility_type, ?string $name_ro = null): bool;
    public function getLastSyncedAt(): ?string;
}
