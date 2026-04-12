<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;

/**
 * Novoton implementation of BookingAdminProviderInterface.
 *
 * Provides Novoton-specific display data, actions, and POST handlers
 * for the unified travel_bookings admin interface.
 *
 * @package NovotonHolidays
 * @since   2.8.0
 */
class BookingAdminProvider implements BookingAdminProviderInterface
{
    private BookingRepositoryInterface $bookingRepo;

    public function __construct(?BookingRepositoryInterface $bookingRepo = null)
    {
        $this->bookingRepo = $bookingRepo ?? new BookingRepository();
    }

    /** @return array<string, mixed> */
    public function getDisplayData(string $providerBookingId): array
    {
        $booking = $this->bookingRepo->findById((int) $providerBookingId);

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

    /** @return array<string, mixed> */
    public function checkStatus(string $providerBookingId): array
    {
        $bookingId = (int) $providerBookingId;
        if ($bookingId <= 0) {
            return ['changed' => false, 'old_status' => '', 'new_status' => '', 'error' => 'Invalid booking ID'];
        }

        $booking = $this->bookingRepo->findById($bookingId);
        $oldStatus = $booking['novoton_status'] ?? $booking['status'] ?? '';

        if (function_exists('fn_novoton_holidays_check_reservation_status')) {
            fn_novoton_holidays_check_reservation_status($bookingId);
        } else {
            return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => '', 'error' => 'Status check function not available'];
        }

        BookingRepository::invalidateCache($bookingId);
        $updatedBooking = $this->bookingRepo->findById($bookingId);
        $newStatus = $updatedBooking['novoton_status'] ?? $updatedBooking['status'] ?? '';

        return [
            'changed' => $oldStatus !== $newStatus,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $booking
     */
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
                'url' => 'travel_bookings.provider_action',
                'method' => 'POST',
                'css_class' => 'btn-default',
                'icon' => 'icon-refresh',
                'booking_id' => $providerBookingId,
                'extra_params' => ['provider_action' => 'resinfo'],
            ];
        }

        // Alternatives action for ST/RQ bookings
        if (in_array($novotonStatus, ['ST', 'RQ'], true)) {
            $actions[] = [
                'name' => 'alternatives',
                'label' => 'Alternatives',
                'url' => 'travel_bookings.view&booking_id=' . $providerBookingId . '&tab=alternatives',
                'method' => 'GET',
                'css_class' => 'btn-primary',
                'icon' => 'icon-list',
                'booking_id' => null,
            ];
        }

        // Cleanup orphans (global action, not per-booking)
        // This is surfaced as a bulk action in the manage view, not per-row

        return $actions;
    }

    public function getProviderViewUrl(string $providerBookingId): ?string
    {
        return 'travel_bookings.view?booking_id=' . (int) $providerBookingId;
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $request
     */
    public function handleAction(string $action, array $request): array
    {
        switch ($action) {
            case 'resinfo':
                return $this->handleResinfo($request);

            case 'request_alternatives':
                return $this->handleRequestAlternatives($request);

            case 'check_all_status':
                return $this->handleCheckAllStatus();

            case 'cleanup_orphans':
                return $this->handleCleanupOrphans();

            case 'update_novoton_id':
                return $this->handleUpdateNovotonId($request);

            default:
                return [
                    'redirect' => 'travel_bookings.manage',
                    'notification' => ['type' => 'W', 'title' => __('warning'), 'message' => "Unknown Novoton action: {$action}"],
                ];
        }
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $booking
     */
    public function getProviderTabs(array $booking): array
    {
        $tabs = [];
        $bookingId = $booking['provider_booking_id'] ?? $booking['booking_id'] ?? '';
        $display = $booking['provider_display'] ?? [];
        $novotonStatus = $display['novoton_status'] ?? '';

        // Alternatives tab for ST/RQ bookings or if alternatives were previously requested
        if (in_array($novotonStatus, ['ST', 'RQ'], true) || !empty($display['alternatives_requested'])) {
            $tabs[] = [
                'name' => 'alternatives',
                'label' => 'Alternatives',
                'dispatch' => 'travel_bookings.view&booking_id=' . $bookingId . '&tab=alternatives',
                'ajax' => false,
            ];
        }

        return $tabs;
    }

    // ── Provider-specific action handlers ──

    /**
     * Validate return_url to prevent open redirects.
     * Only allows relative dispatch URLs (no scheme/host).
     */
    private function validateReturnUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
            return '';
        }
        return $url;
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $request
     */
    private function handleResinfo(array $request): array
    {
        $bookingId = (int) ($request['booking_id'] ?? 0);
        if ($bookingId > 0) {
            fn_novoton_holidays_check_reservation_status($bookingId);
        }

        $returnUrl = $this->validateReturnUrl((string) ($request['return_url'] ?? ''));

        return [
            'redirect' => $returnUrl !== '' ? $returnUrl : 'travel_bookings.manage',
            'notification' => ['type' => 'N', 'title' => __('notice'), 'message' => __('novoton_holidays.status_checked')],
        ];
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $request
     */
    private function handleRequestAlternatives(array $request): array
    {
        $bookingId = (int) ($request['booking_id'] ?? 0);

        if ($bookingId > 0) {
            $result = fn_novoton_holidays_request_alternatives($bookingId);

            if (!empty($result['success'])) {
                return [
                    'redirect' => $this->validateReturnUrl((string) ($request['return_url'] ?? '')) ?: 'travel_bookings.manage',
                    'notification' => ['type' => 'N', 'title' => __('notice'), 'message' => __('novoton_holidays.alternatives_found', ['[count]' => 1])],
                ];
            }

            return [
                'redirect' => $this->validateReturnUrl((string) ($request['return_url'] ?? '')) ?: 'travel_bookings.manage',
                'notification' => ['type' => 'W', 'title' => __('warning'), 'message' => __('novoton_holidays.no_alternatives')],
            ];
        }

        return ['redirect' => 'travel_bookings.manage'];
    }

    /** @return array<string, mixed> */
    private function handleCheckAllStatus(): array
    {
        $results = fn_novoton_holidays_cron_resinfo();

        return [
            'redirect' => 'travel_bookings.manage&provider=novoton',
            'notification' => [
                'type' => 'N',
                'title' => __('notice'),
                'message' => __('novoton_holidays.bulk_status_checked', [
                    '[checked]' => $results['checked'] ?? 0,
                    '[changed]' => $results['changed'] ?? 0,
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function handleCleanupOrphans(): array
    {
        $bookingRepo = Container::getInstance()->bookingRepository();
        $count = $bookingRepo->countOrphans(24);

        if ($count > 0) {
            $bookingRepo->deleteOrphans(24);
            return [
                'redirect' => 'travel_bookings.manage&provider=novoton',
                'notification' => ['type' => 'N', 'title' => __('notice'), 'message' => "Cleaned up {$count} orphan booking(s) older than 24 hours."],
            ];
        }

        return [
            'redirect' => 'travel_bookings.manage&provider=novoton',
            'notification' => ['type' => 'N', 'title' => __('notice'), 'message' => 'No orphan bookings to clean up.'],
        ];
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $request
     */
    private function handleUpdateNovotonId(array $request): array
    {
        $bookingId = (int) ($request['booking_id'] ?? 0);
        $novotonInvoiceId = isset($request['novoton_invoice_id'])
            ? preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($request['novoton_invoice_id']))
            : '';

        if ($bookingId > 0) {
            $bookingRepo = Container::getInstance()->bookingRepository();
            $bookingRepo->update($bookingId, ['novoton_invoice_id' => $novotonInvoiceId]);

            // If ID provided, check status
            if (!empty($novotonInvoiceId)) {
                fn_novoton_holidays_check_reservation_status($bookingId);
            }

            return [
                'redirect' => 'travel_bookings.view&booking_id=' . $bookingId,
                'notification' => ['type' => 'N', 'title' => __('notice'), 'message' => 'Novoton ID updated'],
            ];
        }

        return ['redirect' => 'travel_bookings.manage'];
    }
}
