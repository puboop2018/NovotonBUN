<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Alternative Request Repository
 *
 * Centralized database access for alternative hotel requests.
 *
 * @package NovotonHolidays
 * @since   3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\TravelConstants;

class AlternativeRequestRepository implements AlternativeRequestRepositoryInterface
{
    /**
     * Find request by ID.
     * @return array<string, mixed>|null
     */
    public function findById(int $request_id): ?array
    {
        $row = db_get_row("SELECT * FROM ?:novoton_alternative_requests WHERE request_id = ?i", $request_id);
        return $row ?: null;
    }

    /**
     * Create a new alternative request and return its ID.
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        db_query("INSERT INTO ?:novoton_alternative_requests ?e", $data);
        return (int) db_get_field("SELECT LAST_INSERT_ID()");
    }

    /**
     * Find pending requests older than N hours (for API polling).
     * @return list<array<string, mixed>>
     */
    public function findPendingOlderThan(int $hours = 24, int $limit = 50): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests
             WHERE status = ?s
             AND novoton_request_id IS NOT NULL AND novoton_request_id != ''
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)
             ORDER BY created_at ASC LIMIT ?i",
            TravelConstants::STATUS_PENDING,
            $hours,
            $limit
        );
    }

    /**
     * Find pending requests that have a Novoton API reference.
     * @return list<array<string, mixed>>
     */
    public function findPendingWithApiRef(): array
    {
        return db_get_array(
            "SELECT request_id, novoton_request_id, hotel_name, contact_email
             FROM ?:novoton_alternative_requests
             WHERE status = ?s
               AND novoton_request_id != ''
               AND novoton_request_id IS NOT NULL",
            TravelConstants::STATUS_PENDING
        );
    }

    /**
     * Find requests with alternatives found but not yet notified.
     * @return array<string, mixed>
     */
    public function findUnnotified(int $limit = 20): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests
             WHERE status = 'alternatives_found'
             ORDER BY updated_at ASC LIMIT ?i",
            $limit
        );
    }

    /**
     * Update a request's status and optional data fields.
     * @param array<string, mixed> $data
     */
    public function update(int $request_id, array $data): bool
    {
        return (bool) db_query(
            "UPDATE ?:novoton_alternative_requests SET ?u WHERE request_id = ?i",
            $data,
            $request_id
        );
    }

    /**
     * Mark a request as having alternatives found.
     */
    public function markAlternativesFound(int $request_id, string $alternatives_json): bool
    {
        return $this->update($request_id, [
            'alternatives_data' => $alternatives_json,
            'status'            => 'alternatives_found',
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Mark a request as notified.
     */
    public function markNotified(int $request_id): bool
    {
        return $this->update($request_id, [
            'status'      => 'notified',
            'notified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Expire old pending requests.
     *
     * @return int Number of rows updated
     */
    public function expireOlderThan(int $days = 30): int
    {
        return (int) db_query(
            "UPDATE ?:novoton_alternative_requests
             SET status = 'expired', updated_at = NOW()
             WHERE status IN ('pending', 'pending_manual')
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i DAY)",
            $days
        );
    }

    /**
     * Delete a request by ID.
     */
    public function delete(int $request_id): bool
    {
        return (bool) db_query(
            "DELETE FROM ?:novoton_alternative_requests WHERE request_id = ?i",
            $request_id
        );
    }

    /**
     * Count requests matching optional status/conditions.
     *
     * @param string $whereSql  Pre-built WHERE clause (e.g. "WHERE status = 'pending'")
     * @param array<string, mixed>  $params    Bound parameters for the WHERE clause
     */
    public function countFiltered(string $whereSql = '', array $params = []): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_alternative_requests {$whereSql}",
            ...$params
        );
    }

    /**
     * Find requests with pagination and optional WHERE clause.
     *
     * @param string $whereSql  Pre-built WHERE clause
     * @param array<string, mixed>  $params    Bound parameters
     * @param int    $limit
     * @param int    $offset
     * @return array<string, mixed>
     */
    public function findFiltered(string $whereSql = '', array $params = [], int $limit = 30, int $offset = 0): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests {$whereSql} ORDER BY created_at DESC LIMIT ?i, ?i",
            ...array_merge($params, [$offset, $limit])
        );
    }

    /**
     * Get status counts grouped by status.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        return db_get_hash_single_array(
            "SELECT status, COUNT(*) as cnt FROM ?:novoton_alternative_requests GROUP BY status",
            ['status', 'cnt']
        );
    }
}