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

class AlternativeRequestRepository
{
    /**
     * Find request by ID.
     */
    public function findById(int $request_id): ?array
    {
        $row = db_get_row("SELECT * FROM ?:novoton_alternative_requests WHERE request_id = ?i", $request_id);
        return $row ?: null;
    }

    /**
     * Find pending requests older than N hours (for API polling).
     */
    public function findPendingOlderThan(int $hours = 24, int $limit = 50): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_alternative_requests
             WHERE status = 'pending'
             AND novoton_request_id IS NOT NULL AND novoton_request_id != ''
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)
             ORDER BY created_at ASC LIMIT ?i",
            $hours,
            $limit
        );
    }

    /**
     * Find requests with alternatives found but not yet notified.
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
}
