<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Synchronises novoton bookings into the shared travel_bookings table.
 *
 * All access to travel_bookings for novoton bookings lives here, lifted out of
 * BookingRepository so the cross-provider coupling is a single, explicit,
 * testable surface. BookingRepository keeps the novoton_bookings writes and the
 * surrounding transaction and delegates the travel_bookings half to this class.
 *
 * Behaviour (SQL and parameters) is preserved verbatim from BookingRepository;
 * the only change is that the upsert's field values are coerced through
 * TypeCoerce instead of raw casts (equivalent for the data that flows here).
 */
class BookingSyncRepository implements BookingSyncRepositoryInterface
{
    /**
     * Map of novoton_bookings column => travel_bookings column for partial
     * update mirroring. Only these fields are stored in travel_bookings.
     *
     * @var array<string, string>
     */
    private const array UPDATE_FIELD_MAP = [
        'order_id' => 'order_id',
        'user_id' => 'user_id',
        'hotel_id' => 'hotel_id',
        'hotel_name' => 'hotel_name',
        'room_type' => 'room_name',
        'board_id' => 'board_code',
        'check_in' => 'check_in',
        'check_out' => 'check_out',
        'nights' => 'nights',
        'adults' => 'adults',
        'children' => 'children',
        'children_ages' => 'children_ages',
        'total_price' => 'total_price',
        'currency' => 'currency',
        'status' => 'status',
        'guests_data' => 'guests_json',
    ];

    /**
     * Mirror a created (or replaced) booking into travel_bookings.
     *
     * Maps novoton-specific fields to the provider-agnostic schema and upserts
     * via INSERT ... ON DUPLICATE KEY UPDATE — atomic, relying on the
     * UNIQUE KEY uq_provider_booking(provider, provider_booking_id).
     *
     * @param array<string, mixed> $data novoton_bookings column data
     */
    public function upsertFromBooking(int $booking_id, array $data): void
    {
        $travel_record = [
            'provider' => 'novoton',
            'provider_booking_id' => (string) $booking_id,
            'order_id' => TypeCoerce::toInt($data['order_id'] ?? 0),
            'user_id' => TypeCoerce::toInt($data['user_id'] ?? 0),
            'hotel_id' => $data['hotel_id'] ?? '',
            'hotel_name' => $data['hotel_name'] ?? '',
            'room_name' => $data['room_type'] ?? '',
            'board_code' => $data['board_id'] ?? '',
            'check_in' => $data['check_in'] ?? '',
            'check_out' => $data['check_out'] ?? '',
            'nights' => TypeCoerce::toInt($data['nights'] ?? 0),
            'adults' => TypeCoerce::toInt($data['adults'] ?? 2),
            'children' => TypeCoerce::toInt($data['children'] ?? 0),
            'children_ages' => $data['children_ages'] ?? '',
            'total_price' => TypeCoerce::toFloat($data['total_price'] ?? 0),
            'currency' => $data['currency'] ?? 'EUR',
            'status' => $data['status'] ?? TravelConstants::STATUS_PENDING,
            'guests_json' => $data['guests_data'] ?? '{}',
        ];

        db_query(
            'INSERT INTO ?:travel_bookings ?e ON DUPLICATE KEY UPDATE ?u',
            $travel_record,
            $travel_record,
        );
    }

    /**
     * Mirror a partial booking update into travel_bookings.
     *
     * Only the fields travel_bookings actually stores are forwarded; the sync
     * is skipped entirely when no mirrored field changed.
     *
     * @param array<string, mixed> $data Changed novoton_bookings columns
     */
    public function applyBookingUpdate(int $booking_id, array $data): void
    {
        $travelUpdate = [];
        foreach (self::UPDATE_FIELD_MAP as $novotonField => $travelField) {
            if (array_key_exists($novotonField, $data)) {
                $travelUpdate[$travelField] = $data[$novotonField];
            }
        }

        if (empty($travelUpdate)) {
            return;
        }

        db_query(
            "UPDATE ?:travel_bookings SET ?u WHERE provider = 'novoton' AND provider_booking_id = ?s",
            $travelUpdate,
            (string) $booking_id,
        );
    }

    /**
     * Remove the travel_bookings mirror for a single booking.
     */
    public function deleteByBookingId(int $booking_id): void
    {
        db_query(
            "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id = ?s",
            (string) $booking_id,
        );
    }

    /**
     * Remove travel_bookings mirrors for orphan novoton bookings (no order,
     * older than the given age in hours).
     */
    public function deleteOrphansOlderThan(int $hours): void
    {
        db_query(
            "DELETE tb FROM ?:travel_bookings tb
             INNER JOIN ?:novoton_bookings nb ON tb.provider_booking_id = CAST(nb.booking_id AS CHAR)
             WHERE tb.provider = 'novoton' AND nb.order_id = 0
               AND nb.created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours,
        );
    }

    /**
     * Remove travel_bookings mirrors for the given novoton booking IDs.
     *
     * @param list<string> $booking_ids provider_booking_id values
     */
    public function deleteByBookingIds(array $booking_ids): void
    {
        if ($booking_ids === []) {
            return;
        }

        db_query(
            "DELETE FROM ?:travel_bookings WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
            $booking_ids,
        );
    }

    /**
     * Re-assign travel_bookings mirrors to a user (after login claims bookings).
     *
     * @param list<string> $booking_ids provider_booking_id values
     */
    public function assignUser(int $user_id, array $booking_ids): void
    {
        if ($booking_ids === []) {
            return;
        }

        db_query(
            "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'novoton' AND provider_booking_id IN (?a)",
            $user_id,
            $booking_ids,
        );
    }
}
