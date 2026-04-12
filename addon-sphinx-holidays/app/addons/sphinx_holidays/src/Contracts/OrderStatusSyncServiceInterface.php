<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx order-status sync service.
 *
 * Polls the Sphinx Orders API to reconcile local booking statuses
 * against the provider's authoritative view.
 */
interface OrderStatusSyncServiceInterface
{
    /**
     * Install an output callback used for progress logging.
     */
    public function setOutputCallback(\Closure $callback): void;

    /**
     * Sync all order statuses from the Sphinx API.
     *
     * @return array{checked: int, changed: int, errors: int}
     */
    public function syncAll(): array;

    /**
     * Check status of a single booking by its booking_id.
     *
     * @return array{changed: bool, old_status: string, new_status: string, error: string|null}
     */
    public function checkSingle(int $bookingId): array;
}
