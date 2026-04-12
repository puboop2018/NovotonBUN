<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Interface for Novoton booking price verification via the room_price API.
 *
 * @since 3.4.0
 */
interface PriceVerificationServiceInterface
{
    /**
     * Verify price via room_price API and extract terms.
     *
     * @param array<string, mixed> $params {hotel_id, room_id, board_id, check_in, check_out, adults, children_ages: int[]}
     * @return array{
     *   success: bool,
     *   total_price: float,
     *   base_price: float,
     *   terms_of_payment: string,
     *   terms_of_cancellation: string,
     *   remark: string,
     *   important: string,
     *   error: string
     * }
     */
    public function verifyPrice(array $params): array;
}