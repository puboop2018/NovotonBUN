<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

interface SyncLogRepositoryInterface
{
    public function findById(int $log_id): ?array;
    public function findRecent(int $limit = 20, string $type = ''): array;
    public function getLastSync(string $type): ?array;
    public function getLastSyncDate(string $type): ?string;
    public function create(string $type, array $data): int;
    public function logSync(string $type, int $total, int $updated, int $failed = 0, int $duration = 0, string $status = 'completed', array $details = []): int;
    public function deleteOld(int $days = 30): int;
    public function count(string $type = ''): int;
    public function getStats(int $days = 7): array;
}
