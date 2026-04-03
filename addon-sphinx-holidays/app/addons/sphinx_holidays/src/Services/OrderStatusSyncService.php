<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Syncs booking statuses from the Sphinx Orders API.
 *
 * Calls GET /api/v1/orders with created_after for incremental polling,
 * compares API statuses against local records, and updates both
 * sphinx_bookings and travel_bookings on changes.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
class OrderStatusSyncService
{
    private readonly SphinxApi $api;
    private readonly SphinxBookingRepository $repo;

    private ?\Closure $outputCallback = null;

    /** Map Sphinx API booking statuses to our internal TravelConstants statuses */
    private const STATUS_MAP = [
        'confirmed'  => TravelConstants::STATUS_CONFIRMED,
        'on_request' => TravelConstants::STATUS_PENDING,
        'cancelled'  => TravelConstants::STATUS_CANCELLED,
    ];

    public function __construct(SphinxApi $api, SphinxBookingRepository $repo)
    {
        $this->api = $api;
        $this->repo = $repo;
    }

    public function setOutputCallback(\Closure $callback): void
    {
        $this->outputCallback = $callback;
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }

    /**
     * Sync all order statuses from the Sphinx API.
     *
     * @return array{checked: int, changed: int, errors: int}
     */
    public function syncAll(): array
    {
        $stats = ['checked' => 0, 'changed' => 0, 'errors' => 0];

        // Get bookings that have been submitted to API (have order_id and non-orphan)
        $bookings = $this->getBookingsToCheck();

        if (empty($bookings)) {
            $this->output('No Sphinx bookings to check.');
            return $stats;
        }

        $this->output('Checking ' . count($bookings) . ' Sphinx booking(s) for status updates...');

        // Group bookings by order_id for efficient API lookups
        $byOrderId = [];
        foreach ($bookings as $booking) {
            $orderId = (int) $booking['order_id'];
            if ($orderId > 0) {
                $byOrderId[$orderId][] = $booking;
            }
        }

        // Fetch orders from API using reference_code (our order_id)
        foreach ($byOrderId as $orderId => $orderBookings) {
            $stats['checked'] += count($orderBookings);

            try {
                $apiResponse = $this->api->getOrders(1, 10, [
                    'reference_code' => (string) $orderId,
                ]);

                if (empty($apiResponse['data'])) {
                    $this->output("  Order #{$orderId}: not found in Sphinx API.");
                    continue;
                }

                // Process the first matching order
                $apiOrder = $apiResponse['data'][0];
                $changed = $this->processApiOrder($apiOrder, $orderBookings);
                $stats['changed'] += $changed;

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->output("  Order #{$orderId}: API error - " . $e->getMessage());
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx OrderStatusSync: API error for order #' . $orderId . ': ' . $e->getMessage(),
                ]);
            }
        }

        $this->output("Status sync complete: {$stats['checked']} checked, {$stats['changed']} changed, {$stats['errors']} errors.");
        return $stats;
    }

    /**
     * Check status of a single booking by its booking_id.
     *
     * @return array{changed: bool, old_status: string, new_status: string, error: string|null}
     */
    public function checkSingle(int $bookingId): array
    {
        $booking = $this->repo->findById($bookingId);
        if ($booking === null) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Booking not found'];
        }

        $orderId = (int) ($booking['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['changed' => false, 'old_status' => $booking['status'] ?? '', 'new_status' => '', 'error' => 'Booking not linked to an order'];
        }

        try {
            $apiResponse = $this->api->getOrders(1, 10, [
                'reference_code' => (string) $orderId,
            ]);

            if (empty($apiResponse['data'])) {
                return ['changed' => false, 'old_status' => $booking['status'] ?? '', 'new_status' => '', 'error' => 'Order not found in Sphinx API'];
            }

            $apiOrder = $apiResponse['data'][0];
            $changed = $this->processApiOrder($apiOrder, [$booking]);

            $updatedBooking = $this->repo->findById($bookingId);
            return [
                'changed' => $changed > 0,
                'old_status' => $booking['status'] ?? '',
                'new_status' => $updatedBooking['status'] ?? $booking['status'] ?? '',
                'error' => null,
            ];

        } catch (\Throwable $e) {
            return ['changed' => false, 'old_status' => $booking['status'] ?? '', 'new_status' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Process an API order response and update local bookings if statuses differ.
     *
     * @return int Number of bookings that changed status
     */
    private function processApiOrder(array $apiOrder, array $localBookings): int
    {
        $changed = 0;
        $apiBookings = $apiOrder['bookings'] ?? [];

        if (empty($apiBookings)) {
            return 0;
        }

        // Match API bookings to local bookings.
        // For single-booking orders (most common), match by position.
        // For multi-booking orders, attempt matching by api_booking_ref first.
        foreach ($localBookings as $local) {
            $localStatus = $local['status'] ?? '';
            $bookingId = (int) $local['booking_id'];
            $bookingType = $local['room_type'] ?? 'hotel';

            // Try to find the matching API booking
            $apiBooking = null;
            $localRef = $local['api_booking_ref'] ?? '';

            // Match by booking reference if available
            if (!empty($localRef)) {
                foreach ($apiBookings as $ab) {
                    if (($ab['booking_confirmation_number'] ?? '') === $localRef) {
                        $apiBooking = $ab;
                        break;
                    }
                }
            }

            // Fallback: match by position (single booking per order)
            if ($apiBooking === null && count($apiBookings) === 1) {
                $apiBooking = $apiBookings[0];
            }

            if ($apiBooking === null) {
                continue;
            }

            $apiStatus = $apiBooking['status'] ?? '';
            $internalStatus = self::STATUS_MAP[$apiStatus] ?? '';

            if (empty($internalStatus) || $localStatus === $internalStatus) {
                continue;
            }

            // Status changed — update
            $this->repo->update($bookingId, ['status' => $internalStatus]);

            $orderId = (int) ($local['order_id'] ?? 0);
            $hotelName = $local['hotel_name'] ?? '';

            $this->output("  Booking #{$bookingId} [{$bookingType}] (Order #{$orderId}): {$localStatus} → {$internalStatus}");

            fn_log_event('general', 'runtime', [
                'message' => "Sphinx OrderStatusSync: booking #{$bookingId} [{$bookingType}] status changed: {$localStatus} → {$internalStatus}",
                'order_id' => $orderId,
                'api_status' => $apiStatus,
            ]);

            // Store payment terms and cancellation fees from the order
            $this->storeOrderTerms($bookingId, $apiOrder);

            // Send admin email alert on cancellation
            if ($internalStatus === TravelConstants::STATUS_CANCELLED) {
                $this->sendStatusChangeAlert($orderId, $bookingId, $hotelName, $localStatus, $internalStatus);
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * Store payment terms and cancellation fees from an API order response.
     */
    private function storeOrderTerms(int $bookingId, array $apiOrder): void
    {
        $updates = [];

        if (!empty($apiOrder['payment_terms'])) {
            $updates['payment_terms_json'] = json_encode($apiOrder['payment_terms'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($apiOrder['cancellation_fees'])) {
            $updates['cancellation_fees_json'] = json_encode($apiOrder['cancellation_fees'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($updates)) {
            // Direct update to sphinx_bookings only (these fields don't exist in travel_bookings)
            $this->repo->updateDirect($bookingId, $updates);
        }
    }

    /**
     * Send admin email alert when a booking status changes to cancelled.
     */
    private function sendStatusChangeAlert(int $orderId, int $bookingId, string $hotelName, string $oldStatus, string $newStatus): void
    {
        $adminEmail = $this->getAdminEmail();
        if (empty($adminEmail)) {
            return;
        }

        $subject = "Sphinx Booking Status Change - Order #{$orderId}";
        $body = "A Sphinx booking status has changed:\n\n"
            . "Order ID: #{$orderId}\n"
            . "Booking ID: #{$bookingId}\n"
            . "Hotel: {$hotelName}\n"
            . "Status: {$oldStatus} → {$newStatus}\n\n"
            . "Please review this booking in the admin panel.";

        try {
            fn_send_mail([
                'to'      => $adminEmail,
                'from'    => 'default_company_orders_department',
                'subject' => $subject,
                'body'    => $body,
            ], 'A');
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Failed to send booking notification: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get bookings that should be checked for status updates.
     *
     * Returns bookings that:
     * - Are linked to an order (order_id > 0)
     * - Have a non-terminal status (not already cancelled)
     * - Were created in the last 90 days
     */
    private function getBookingsToCheck(): array
    {
        return $this->repo->findForStatusCheck([TravelConstants::STATUS_CANCELLED]);
    }

    /**
     * Get admin email for alerts.
     */
    private function getAdminEmail(): string
    {
        $email = db_get_field(
            "SELECT value FROM ?:settings_objects WHERE name = 'company_orders_department'"
        );
        return is_string($email) ? $email : '';
    }
}
