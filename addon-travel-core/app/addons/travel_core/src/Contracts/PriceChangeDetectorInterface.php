<?php

declare(strict_types=1);

/**
 * Price Change Detector Interface
 *
 * Contract for analysing booking price changes and storing user-facing
 * alerts ("No Surprises" policy). Shared across all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface PriceChangeDetectorInterface
{
    /**
     * Analyse a price change and return structured display data.
     *
     * @param array<string, mixed> $bookingMeta
     * @return array{
     *   significant: bool,
     *   direction: string,
     *   old_price: float,
     *   new_price: float,
     *   difference: float,
     *   percent: float,
     *   currency: string,
     *   badge_type: string,
     *   context: string,
     *   booking_meta: array<string, mixed>,
     *   timestamp: int
     * }
     */
    public function analyse(
        float $oldPrice,
        float $newPrice,
        string $currency,
        string $context = 'add_to_cart',
        array $bookingMeta = [],
    ): array;

    /**
     * Check if a price change is significant enough to show to the user.
     */
    public function isSignificant(float $oldPrice, float $newPrice): bool;

    /**
     * Store a price change alert in the session.
     *
     * @param array<string, mixed> $alertData
     */
    public function storeAlert(array $alertData, string $cartId = ''): void;

    /**
     * Retrieve and clear all pending price change alerts from the session.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function consumeAlerts(): array;

    /**
     * Retrieve pending alerts without clearing them.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function peekAlerts(): array;
}
