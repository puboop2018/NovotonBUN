<?php
/**
 * Sync Log Repository Interface
 *
 * Contract for sync operation log data access.
 *
 * @package NovotonHolidays
 * @since 3.2.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

interface SyncLogRepositoryInterface
{
    public function findById(int $log_id): ?array;

    public function findRecent(int $limit = 20, string $type = ''): array;

    public function findByType(string $type, int $limit = 0): array;

    public function getLastSync(string $type): ?array;

    public function getLastSyncDate(string $type): ?string;

    public function create(string $type, array $data): int;

    public function logSync(string $type, int $total, int $updated, int $failed = 0, int $duration = 0, string $status = 'completed', array $details = []): int;

    public function deleteOld(int $days = 30): int;

    public function count(string $type = ''): int;

    public function findPaginated(int $page = 1, int $per_page = 10, string $type = ''): array;

    public function trimToLatest(int $keep = 100): int;

    public function getStats(int $days = 7): array;
}
