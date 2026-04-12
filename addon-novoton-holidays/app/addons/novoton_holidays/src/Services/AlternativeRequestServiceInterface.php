<?php
declare(strict_types=1);
/**
 * Alternative Request Service Interface
 *
 * Contract for creating alternative booking requests via the Novoton API.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface AlternativeRequestServiceInterface
{
    /**
     * Create an alternative booking request.
     *
     * Calls the Novoton hotel_request API, stores the request in the database
     * (encrypting PII fields), and sends a confirmation email.
     *
     * @param array<string, mixed> $params {
     *   hotel_id: string,
     *   hotel_name: string,
     *   check_in: string,
     *   check_out: string,
     *   nights: int,
     *   adults: int,
     *   children: int,
     *   num_rooms: int,
     *   contact_email: string,
     *   contact_phone: string,
     *   notes: string
     * }
     * @return array{success: bool, request_id: int, novoton_id: string, message: string, error: string}
     */
    public function submitAlternativeBookingRequest(array $params): array;
}