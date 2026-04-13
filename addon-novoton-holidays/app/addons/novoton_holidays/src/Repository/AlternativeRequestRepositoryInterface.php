<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface AlternativeRequestRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $request_id): ?array;
    /** @param array<string, mixed> $data */
    public function create(array $data): int;
    /** @return list<array<string, mixed>> */
    public function findPendingOlderThan(int $hours = 24, int $limit = 50): array;
    /** @return list<array<string, mixed>> */
    public function findPendingWithApiRef(): array;
    /** @return list<array<string, mixed>> */
    public function findUnnotified(int $limit = 20): array;
    /** @param array<string, mixed> $data */
    public function update(int $request_id, array $data): bool;
    public function markAlternativesFound(int $request_id, string $alternatives_json): bool;
    public function markNotified(int $request_id): bool;
    public function expireOlderThan(int $days = 30): int;
    public function delete(int $request_id): bool;
    /** @param array<string, mixed> $params */
    public function countFiltered(string $whereSql = '', array $params = []): int;
    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function findFiltered(string $whereSql = '', array $params = [], int $limit = 30, int $offset = 0): array;
    /** @return array<string, int> */
    public function getStatusCounts(): array;
}
