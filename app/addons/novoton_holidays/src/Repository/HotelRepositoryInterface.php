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
}
