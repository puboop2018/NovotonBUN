<?php
declare(strict_types=1);
/**
 * Pre-Order Price Verifier Interface
 *
 * Contract for verifying booking prices before order placement.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface PreOrderPriceVerifierInterface
{
    /**
     * Verify the price for a booking before order is placed.
     *
     * @param array $cart    CS-Cart cart data
     * @param array $booking Booking record data
     * @return array{verified: bool, new_price: float, message: string}
     */
    public function verify(array $cart, array $booking): array;
}
