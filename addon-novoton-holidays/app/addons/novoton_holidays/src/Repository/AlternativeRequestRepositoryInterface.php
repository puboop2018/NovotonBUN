<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface AlternativeRequestRepositoryInterface
{
    public function findById(int $request_id): ?array;
    public function create(array $data): int;
    public function findPendingOlderThan(int $hours = 24, int $limit = 50): array;
    public function findPendingWithApiRef(): array;
    public function findUnnotified(int $limit = 20): array;
    public function update(int $request_id, array $data): bool;
    public function markAlternativesFound(int $request_id, string $alternatives_json): bool;
    public function markNotified(int $request_id): bool;
    public function expireOlderThan(int $days = 30): int;
    public function delete(int $request_id): bool;
}
