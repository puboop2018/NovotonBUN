<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Interface for provider-specific admin booking operations.
 *
 * Each travel provider addon (novoton, sphinx) implements this to expose
 * provider-specific display data and actions in the unified travel_bookings
 * admin interface.
 */
interface BookingAdminProviderInterface
{
    /**
     * Get provider-specific display data for a booking.
     *
     * Returns additional columns/fields to show in the unified admin UI
     * that are specific to this provider (e.g. Novoton invoice ID, Sphinx offer ID).
     *
     * @param string $providerBookingId The provider_booking_id from travel_bookings
     * @return array<string, mixed> Provider-specific data (e.g. ['novoton_invoice_id' => '...', 'novoton_status' => '...'])
     */
    public function getDisplayData(string $providerBookingId): array;

    /**
     * Check booking status with the provider's API.
     *
     * @param string $providerBookingId The provider_booking_id from travel_bookings
     * @return array{changed: bool, old_status: string, new_status: string, error: string|null}
     */
    public function checkStatus(string $providerBookingId): array;

    /**
     * Get available admin actions for a booking based on its current state.
     *
     * Returns a list of action descriptors that the unified UI can render as buttons.
     * Each action has: name, label, url, method (GET/POST), css_class, icon.
     *
     * @param array<string, mixed> $booking The travel_bookings row merged with provider display data
     * @return array<int, array{name: string, label: string, url: string, method: string, css_class: string, icon: string}>
     */
    public function getAvailableActions(array $booking): array;

    /**
     * Get the URL to view this booking in the provider's own admin interface.
     *
     * @param string $providerBookingId The provider_booking_id from travel_bookings
     * @return string|null URL, or null if no provider-specific view exists
     */
    public function getProviderViewUrl(string $providerBookingId): ?string;

    /**
     * Handle a provider-specific POST action from the unified booking UI.
     *
     * The unified controller delegates unknown POST modes to the provider's
     * handleAction() method. Returns a redirect target or result array.
     *
     * @param string $action  The action name (e.g. 'request_alternatives', 'cleanup_orphans')
     * @param array<string, mixed>  $request The $_REQUEST data
     * @return array{redirect: string, notification?: array{type: string, title: string, message: string}}
     */
    public function handleAction(string $action, array $request): array;

    /**
     * Get provider-specific tab definitions for the unified booking view.
     *
     * Returns tab descriptors that the unified UI renders alongside the
     * standard booking detail tabs (e.g. Novoton alternatives, order tab).
     *
     * @param array<string, mixed> $booking The full booking row (with provider_display enrichment)
     * @return array<int, array{name: string, label: string, dispatch: string, ajax: bool}>
     */
    public function getProviderTabs(array $booking): array;
}
