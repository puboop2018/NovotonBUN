<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
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
    private SphinxBookingRepository $repo;

    public function __construct(?SphinxBookingRepository $repo = null)
    {
        $this->repo = $repo ?? new SphinxBookingRepository();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getDisplayData(string $providerBookingId): array
    {
        $booking = $this->repo->findById((int) $providerBookingId);

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

    /**
     * @return array<string, mixed>
     */
    #[\Override]
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
        $this->repo->updateDirect($bookingId, ['last_status_check' => date('Y-m-d H:i:s')]);

        return $result;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<int, array{name: string, label: string, url: string, method: string, css_class: string, icon: string}>
     */
    #[\Override]
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

    #[\Override]
    public function getProviderViewUrl(string $providerBookingId): ?string
    {
        // Sphinx doesn't have its own admin bookings controller,
        // so link to the unified view
        return null;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function handleAction(string $action, array $request): array
    {
        // Sphinx has no provider-specific POST actions yet
        return [
            'redirect' => 'travel_bookings.manage',
            'notification' => ['type' => 'W', 'title' => __('warning'), 'message' => "Unknown Sphinx action: {$action}"],
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<int, array{name: string, label: string, dispatch: string, ajax: bool}>
     */
    #[\Override]
    public function getProviderTabs(array $booking): array
    {
        // Sphinx has no provider-specific tabs
        return [];
    }
}
