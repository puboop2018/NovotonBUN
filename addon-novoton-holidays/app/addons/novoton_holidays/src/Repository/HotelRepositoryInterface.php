<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface HotelRepositoryInterface
{
    public function findById(string $hotel_id): ?array;
    public function findBasicById(string $hotel_id): ?array;
    public function findByProductId(int $product_id): ?array;
    public function getHotelIdByProduct(int $product_id): ?string;
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array;
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array;
    public function findByCountry(string $country): array;
    public function findWithoutPackages(int $limit = 0): array;
    public function count(array $filters = []): int;
    public function countWithoutPackagesByCountry(): array;
    public function exists(string $hotel_id): bool;
    public function save(string $hotel_id, array $data): bool;
    public function insert(array $data): bool;
    public function update(string $hotel_id, array $data): bool;
    public function upsert(array $data): bool;
    public function delete(string $hotel_id): bool;
    public function linkToProduct(string $hotel_id, int $product_id): bool;
    public function unlinkProduct(int $product_id): bool;
    public function getCountries(): array;
    public function getResorts(string $country = ''): array;
    public function getPackages(string $hotel_id): array;
    public function getPackagesForListing(string $hotel_id): array;
    public function savePackage(string $hotel_id, string $package_id, array $data): bool;
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0): array;
    public function getLocationsByIds(array $hotel_ids): array;
    public function findByCountryForListing(string $country): array;
    public function getLatestPriceinfoData(string $hotel_id): ?string;
    public function getLatestPackageSyncedAt(string $hotel_id): ?string;
    public function getCalendarPricesRaw(string $hotel_id): ?string;
    public function setCalendarPricesRaw(string $hotel_id, ?string $json): void;
    public function findNeedingPriceCheck(int $daysStale = 7, int $limit = 100): array;
    public function getAllIds(): array;
    public function findByCountryIndexed(string $country): array;
    public function findByCountryWithLimit(string $country, int $limit = 0): array;
    public function findNeedingPriceUpdate(int $staleHours = 24, int $limit = 100): array;
    public function findWithProductsSortedByStaleness(int $limit = 50): array;
    public function findWithPricesForExport(string $country): array;
    public function findForImport(string $country, string $importMode = 'new_only', array $selectedResorts = [], int $limit = 0): array;
    public function findIdsWithPriceinfoData(): array;
    public function countWithCalendarPrices(): int;
    public function countWithPackagesByCountry(string $country): int;
    public function getResortStatsByCountry(string $country): array;
    public function findUnlinkedForAdmin(string $country, string $filter = 'prices', int $limit = 500): array;
    public function getCountriesWithPriceCounts(): array;
    public function getCountryCityPairs(): array;
    public function getHotelData(string $hotel_id): ?string;
    public function findWithPriceinfoData(int $limit = 200): array;
    public function updatePackagesCount(string $hotel_id, int $count): bool;
}
