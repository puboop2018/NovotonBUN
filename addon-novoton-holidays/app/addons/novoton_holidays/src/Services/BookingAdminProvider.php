<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;

/**
 * Novoton implementation of BookingAdminProviderInterface.
 *
 * Provides Novoton-specific display data and actions for the
 * unified travel_bookings admin interface.
 *
 * @package NovotonHolidays
 * @since   2.8.0
 */
class BookingAdminProvider implements BookingAdminProviderInterface
{
    public function getDisplayData(string $providerBookingId): array
    {
        $booking = db_get_row(
            "SELECT booking_id, novoton_invoice_id, novoton_status, novoton_confirm_id,
                    base_price, api_price, status, alternatives_requested
             FROM ?:novoton_bookings WHERE booking_id = ?i",
            (int) $providerBookingId
        );

        if (empty($booking)) {
            return [];
        }

        $display = [];

        // Provider reference: Novoton invoice ID
        $display['provider_ref'] = !empty($booking['novoton_invoice_id'])
            ? 'NT ' . $booking['novoton_invoice_id']
            : '';

        // Status label with Novoton-specific styling
        $novotonStatus = $booking['novoton_status'] ?? '';
        $display['novoton_status'] = $novotonStatus;
        $display['novoton_invoice_id'] = $booking['novoton_invoice_id'] ?? '';
        $display['novoton_confirm_id'] = $booking['novoton_confirm_id'] ?? '';

        $statusLabels = [
            'Good' => '<span class="label label-success">Good</span>',
            'ASK'  => '<span class="label label-warning">ASK</span>',
            'ST'   => '<span class="label label-danger">ST</span>',
            'WT'   => '<span class="label label-info">WT</span>',
            'RQ'   => '<span class="label label-primary">RQ</span>',
        ];
        $display['status_label'] = $statusLabels[$novotonStatus] ?? '<span class="label">' . htmlspecialchars($booking['status'] ?? '') . '</span>';

        // Price info
        if (!empty($booking['api_price'])) {
            $display['api_price'] = $booking['api_price'];
        }

        return $display;
    }

    public function checkStatus(string $providerBookingId): array
    {
        $bookingId = (int) $providerBookingId;
        if ($bookingId <= 0) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Invalid booking ID'];
        }

        $booking = db_get_row("SELECT status, novoton_status FROM ?:novoton_bookings WHERE booking_id = ?i", $bookingId);
        $oldStatus = $booking['novoton_status'] ?? $booking['status'] ?? '';

        if (function_exists('fn_novoton_holidays_check_reservation_status')) {
            $result = fn_novoton_holidays_check_reservation_status($bookingId);
        } else {
            return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => '', 'error' => 'Status check function not available'];
        }

        $updatedBooking = db_get_row("SELECT status, novoton_status FROM ?:novoton_bookings WHERE booking_id = ?i", $bookingId);
        $newStatus = $updatedBooking['novoton_status'] ?? $updatedBooking['status'] ?? '';

        return [
            'changed' => $oldStatus !== $newStatus,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'error' => null,
        ];
    }

    public function getAvailableActions(array $booking): array
    {
        $actions = [];
        $providerBookingId = $booking['provider_booking_id'] ?? '';
        $display = $booking['provider_display'] ?? [];
        $novotonStatus = $display['novoton_status'] ?? '';

        // Check Status action for ASK bookings
        if ($novotonStatus === 'ASK') {
            $actions[] = [
                'name' => 'check_status',
                'label' => 'Check Status',
                'url' => 'novoton_bookings.resinfo',
                'method' => 'POST',
                'css_class' => 'btn-default',
                'icon' => 'icon-refresh',
                'booking_id' => $providerBookingId,
            ];
        }

        // Alternatives action for ST/RQ bookings
        if (in_array($novotonStatus, ['ST', 'RQ'], true)) {
            $actions[] = [
                'name' => 'alternatives',
                'label' => 'Alternatives',
                'url' => 'novoton_bookings.alternatives?booking_id=' . $providerBookingId,
                'method' => 'GET',
                'css_class' => 'btn-primary',
                'icon' => 'icon-list',
                'booking_id' => null,
            ];
        }

        return $actions;
    }

    public function getProviderViewUrl(string $providerBookingId): ?string
    {
        return 'novoton_bookings.view?booking_id=' . $providerBookingId;
    }
}
