<?php
declare(strict_types=1);
/**
 * Pre-Order Price Verifier Interface
 *
 * Contract for verifying booking prices before order placement.
 * Providers implement this to re-check live API prices at checkout
 * and apply corrections or notifications as needed.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface PreOrderPriceVerifierInterface
{
    /**
     * Verify prices for all provider bookings in the cart before order is placed.
     *
     * Each provider scans the cart for its own booking products, re-checks
     * prices against its API, and returns corrections/notifications.
     *
     * @param array<string, mixed> $cart CS-Cart cart data
     * @return array{allow: bool, corrections: array, notifications: array}
     *   - allow: whether the order should proceed (typically always true)
     *   - corrections: cart_id => price correction data
     *   - notifications: list of discrepancy data for admin alerts
     */
    public function verify(array $cart): array;
}
