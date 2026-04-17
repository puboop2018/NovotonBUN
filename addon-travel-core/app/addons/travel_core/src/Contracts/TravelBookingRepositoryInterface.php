<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Repository contract for travel_bookings DB operations.
 *
 * Centralizes all raw db_*() calls against the ?:travel_bookings table,
 * extracted from the travel_bookings admin controller.
 */
interface TravelBookingRepositoryInterface
{
    /**
     * Get provider and provider_booking_id for a booking.
     *
     * @return array<string, mixed>|null Row with 'provider' and 'provider_booking_id', or null if not found
     */
    public function getProviderInfo(int $bookingId): ?array;

    /**
     * Get a single booking by ID with all columns.
     *
     * @return array<string, mixed>|null Full booking row, or null if not found
     */
    public function getById(int $bookingId): ?array;

    /**
     * Get paginated bookings with filter condition.
     *
     * @param string $condition SQL condition fragment (must be pre-quoted via db_quote)
     * @param string $sortColumn Safe sort column expression (e.g. 'tb.created_at')
     * @param string $sortOrder 'ASC' or 'DESC'
     * @param int $offset Pagination offset
     * @param int $limit Items per page
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getPaginated(string $condition, string $sortColumn, string $sortOrder, int $offset, int $limit): array;
}
