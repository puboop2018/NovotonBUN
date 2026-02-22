<?php
declare(strict_types=1);
/**
 * Booking Submission Service Interface
 *
 * Contract for submitting Novoton bookings to the API when CS-Cart
 * places an order.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface BookingSubmissionServiceInterface
{
    /**
     * Submit all Novoton bookings in the cart to the API.
     *
     * Called from the fn_novoton_holidays_place_order hook after CS-Cart
     * creates the order record.
     *
     * For multi-room bookings:
     *   - Sends ALL rooms in SINGLE API request IF same hotel, package, and dates
     *   - Sends SEPARATE API calls if rooms have different packages or dates
     *
     * @param int   $orderId Order ID
     * @param array $cart    Cart data
     */
    public function submitOrder(int $orderId, array $cart): void;
}
