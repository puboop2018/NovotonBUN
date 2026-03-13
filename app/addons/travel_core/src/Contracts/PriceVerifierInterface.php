<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Price verification contract.
 *
 * Each travel provider implements this to verify offer prices
 * before adding to cart or placing an order.
 */
interface PriceVerifierInterface
{
    /**
     * Verify the current price of an offer.
     *
     * @param array $params Offer identification params (provider-specific IDs + search params)
     * @return array {
     *   success: bool,
     *   total_price: float,
     *   base_price: float,
     *   currency: string,
     *   terms_of_payment: string|null,
     *   terms_of_cancellation: string|null,
     *   free_cancellation_until: string|null,
     *   is_available: bool,
     *   error: string|null
     * }
     */
    public function verifyPrice(array $params): array;
}
