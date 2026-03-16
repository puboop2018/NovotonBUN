<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Sphinx implementation of BookingAdminProviderInterface.
 *
 * Provides Sphinx-specific display data and actions for the
 * unified travel_bookings admin interface.
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
class BookingAdminProvider implements BookingAdminProviderInterface
{
    public function getDisplayData(string $providerBookingId): array
    {
        $booking = db_get_row(
            "SELECT booking_id, offer_id, api_booking_ref, base_price, status,
                    payment_terms_json, cancellation_fees_json, last_status_check
             FROM ?:sphinx_bookings WHERE booking_id = ?i",
            (int) $providerBookingId
        );

        if (empty($booking)) {
            return [];
        }

        $display = [];

        // Provider reference: API booking ref or offer ID
        $display['provider_ref'] = !empty($booking['api_booking_ref'])
            ? $booking['api_booking_ref']
            : '';

        // Sphinx-specific fields
        $display['offer_id'] = $booking['offer_id'] ?? '';
        $display['api_booking_ref'] = $booking['api_booking_ref'] ?? '';

        if (!empty($booking['base_price'])) {
            $display['api_price'] = $booking['base_price'];
        }

        if (!empty($booking['last_status_check'])) {
            $display['last_status_check'] = $booking['last_status_check'];
        }

        // Payment terms & cancellation fees
        if (!empty($booking['payment_terms_json'])) {
            $terms = json_decode($booking['payment_terms_json'], true);
            if (is_array($terms)) {
                $display['payment_terms'] = $terms;
            }
        }

        if (!empty($booking['cancellation_fees_json'])) {
            $fees = json_decode($booking['cancellation_fees_json'], true);
            if (is_array($fees)) {
                $display['cancellation_fees'] = $fees;
            }
        }

        // Status label with Sphinx-specific styling
        $status = $booking['status'] ?? '';
        $statusLabels = [
            TravelConstants::STATUS_CONFIRMED => '<span class="label label-success">Confirmed</span>',
            TravelConstants::STATUS_PENDING   => '<span class="label label-warning">Pending</span>',
            TravelConstants::STATUS_CANCELLED => '<span class="label label-danger">Cancelled</span>',
            TravelConstants::STATUS_FAILED    => '<span class="label label-danger">Failed</span>',
        ];
        $display['status_label'] = $statusLabels[$status] ?? '<span class="label">' . htmlspecialchars($status) . '</span>';

        return $display;
    }

    public function checkStatus(string $providerBookingId): array
    {
        $bookingId = (int) $providerBookingId;
        if ($bookingId <= 0) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Invalid booking ID'];
        }

        $api = Container::getApi();
        $repo = Container::getBookingRepository();
        $service = new OrderStatusSyncService($api, $repo);

        $result = $service->checkSingle($bookingId);

        // Update last_status_check timestamp
        db_query("UPDATE ?:sphinx_bookings SET last_status_check = NOW() WHERE booking_id = ?i", $bookingId);

        return $result;
    }

    public function getAvailableActions(array $booking): array
    {
        $actions = [];
        $providerBookingId = $booking['provider_booking_id'] ?? '';
        $status = $booking['status'] ?? '';

        // Retry action for failed bookings
        if ($status === TravelConstants::STATUS_FAILED) {
            $actions[] = [
                'name' => 'retry',
                'label' => 'Retry Booking',
                'url' => 'travel_bookings.retry_booking',
                'method' => 'POST',
                'css_class' => 'btn-warning',
                'icon' => 'icon-repeat',
                'booking_id' => $booking['booking_id'] ?? '',
            ];
        }

        return $actions;
    }

    public function getProviderViewUrl(string $providerBookingId): ?string
    {
        // Sphinx doesn't have its own admin bookings controller,
        // so link to the unified view
        return null;
    }
}
