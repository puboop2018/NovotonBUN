<?php
/**
 * Novoton Holidays - Sync Log Repository
 * 
 * Centralized database access for sync logs.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

class SyncLogRepository
{
    /**
     * Find log by ID
     */
    public function findById(int $log_id): ?array
    {
        $log = db_get_row("SELECT * FROM ?:novoton_sync_log WHERE log_id = ?i", $log_id);
        return $log ?: null;
    }
    
    /**
     * Find recent logs
     */
    public function findRecent(int $limit = 20, string $type = ''): array
    {
        $where = '';
        if (!empty($type)) {
            $where = db_quote("WHERE sync_type = ?s", $type);
        }
        
        return db_get_array(
            "SELECT * FROM ?:novoton_sync_log {$where} ORDER BY sync_date DESC LIMIT ?i",
            $limit
        );
    }
    
    /**
     * Find logs by type
     */
    public function findByType(string $type, int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(" LIMIT ?i", $limit) : '';
        return db_get_array(
            "SELECT * FROM ?:novoton_sync_log WHERE sync_type = ?s ORDER BY sync_date DESC {$limit_clause}",
            $type
        );
    }
    
    /**
     * Get last sync for a type
     */
    public function getLastSync(string $type): ?array
    {
        $log = db_get_row(
            "SELECT * FROM ?:novoton_sync_log WHERE sync_type = ?s ORDER BY sync_date DESC LIMIT 1",
            $type
        );
        return $log ?: null;
    }
    
    /**
     * Get last sync date for a type
     */
    public function getLastSyncDate(string $type): ?string
    {
        return db_get_field(
            "SELECT sync_date FROM ?:novoton_sync_log WHERE sync_type = ?s ORDER BY sync_date DESC LIMIT 1",
            $type
        );
    }
    
    /**
     * Create log entry
     */
    public function create(string $type, array $data): int
    {
        $log_data = [
            'sync_type' => $type,
            'sync_date' => date('Y-m-d H:i:s'),
            'hotels_synced' => $data['synced'] ?? $data['hotels_synced'] ?? 0,
            'hotels_added' => $data['added'] ?? $data['hotels_added'] ?? 0,
            'hotels_updated' => $data['updated'] ?? $data['hotels_updated'] ?? 0,
            'errors' => $data['errors'] ?? 0,
            'duration' => $data['duration'] ?? 0,
            'details' => isset($data['details']) ? (is_array($data['details']) ? json_encode($data['details']) : $data['details']) : null
        ];
        
        $log_id = db_query("INSERT INTO ?:novoton_sync_log ?e", $log_data);
        return (int) $log_id;
    }
    
    /**
     * Log sync result (convenience method)
     */
    public function logSync(string $type, int $synced, int $added, int $updated, int $errors = 0, int $duration = 0, array $details = []): int
    {
        return $this->create($type, [
            'synced' => $synced,
            'added' => $added,
            'updated' => $updated,
            'errors' => $errors,
            'duration' => $duration,
            'details' => $details
        ]);
    }
    
    /**
     * Delete old logs
     */
    public function deleteOld(int $days = 30): int
    {
        // In CS-Cart, db_query returns affected rows count for DELETE
        $affected = db_query("DELETE FROM ?:novoton_sync_log WHERE sync_date < DATE_SUB(NOW(), INTERVAL ?i DAY)", $days);
        return (int) $affected;
    }
    
    /**
     * Count logs
     */
    public function count(string $type = ''): int
    {
        if (!empty($type)) {
            return (int) db_get_field("SELECT COUNT(*) FROM ?:novoton_sync_log WHERE sync_type = ?s", $type);
        }
        return (int) db_get_field("SELECT COUNT(*) FROM ?:novoton_sync_log");
    }

    /**
     * Find logs with pagination (for AJAX)
     *
     * @param int $page Current page (1-based)
     * @param int $per_page Items per page
     * @param string $type Optional sync type filter
     * @return array ['items' => array, 'total' => int, 'pages' => int]
     */
    public function findPaginated(int $page = 1, int $per_page = 10, string $type = ''): array
    {
        $page = max(1, $page);
        $per_page = max(1, min(100, $per_page));
        $offset = ($page - 1) * $per_page;

        $where = '';
        if (!empty($type)) {
            $where = db_quote("WHERE sync_type = ?s", $type);
        }

        $items = db_get_array(
            "SELECT * FROM ?:novoton_sync_log {$where} ORDER BY sync_date DESC LIMIT ?i OFFSET ?i",
            $per_page,
            $offset
        );

        $total = $this->count($type);
        $pages = (int) ceil($total / $per_page);

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }
    
    /**
     * Get sync statistics
     */
    public function getStats(int $days = 7): array
    {
        $stats = db_get_array(
            "SELECT sync_type, 
                    COUNT(*) as count,
                    SUM(hotels_synced) as total_synced,
                    SUM(hotels_added) as total_added,
                    SUM(hotels_updated) as total_updated,
                    SUM(errors) as total_errors,
                    AVG(duration) as avg_duration
             FROM ?:novoton_sync_log 
             WHERE sync_date > DATE_SUB(NOW(), INTERVAL ?i DAY)
             GROUP BY sync_type",
            $days
        );
        
        $result = [];
        foreach ($stats as $row) {
            $result[$row['sync_type']] = $row;
        }
        return $result;
    }
}
