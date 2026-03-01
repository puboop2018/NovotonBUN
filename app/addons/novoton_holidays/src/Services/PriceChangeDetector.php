<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Price Change Detector
 *
 * Implements the "No Surprises" policy for price change communication.
 *
 * Responsibilities:
 *   - Detect whether a price change is significant (above tolerance threshold)
 *   - Classify changes as 'increase', 'decrease', or 'none'
 *   - Build structured data for UI display (old vs new, badge type, copy)
 *   - Store price change alerts in the session for template rendering
 *
 * Price Tolerance:
 *   Changes below the configurable threshold (default 1%) are silently
 *   applied without user notification. Changes above it trigger visible
 *   "Old vs New" comparisons.
 *
 * UX Rules:
 *   - Price Increase: show Old vs New, orange badge ("Price Updated")
 *   - Price Decrease: show new price + green badge ("Price Dropped!")
 *   - Small changes (< tolerance): silent update, no alert
 *
 * @package NovotonHolidays
 * @since 3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Tygh;

class PriceChangeDetector
{
    /** Default tolerance threshold (percentage). Changes below this are silent. */
    private const DEFAULT_TOLERANCE_PERCENT = 1.0;

    /** Session key for storing price change alerts. */
    private const SESSION_KEY = 'novoton_price_change_alerts';

    /**
     * Analyse a price change and return structured display data.
     *
     * @param float  $oldPrice       The price the customer previously saw
     * @param float  $newPrice       The current verified price
     * @param string $currency       Currency code (e.g. 'EUR', 'RON')
     * @param string $context        Where the change was detected: 'add_to_cart' or 'checkout'
     * @param array  $bookingMeta    Optional metadata (hotel_name, room_id, etc.)
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
     *   booking_meta: array
     * }
     */
    public function analyse(
        float  $oldPrice,
        float  $newPrice,
        string $currency,
        string $context = 'add_to_cart',
        array  $bookingMeta = []
    ): array {
        $difference = round($newPrice - $oldPrice, 2);
        $percent    = $oldPrice > 0
            ? round(abs($difference) / $oldPrice * 100, 2)
            : 0.0;

        $tolerance  = $this->getTolerancePercent();
        $significant = $percent >= $tolerance && abs($difference) > 0.01;

        if ($difference > 0) {
            $direction = 'increase';
        } elseif ($difference < 0) {
            $direction = 'decrease';
        } else {
            $direction = 'none';
        }

        // Badge type follows UX spec:
        // - increase → 'warning' (orange)
        // - decrease → 'success' (green)
        // - none / insignificant → no badge
        $badgeType = 'none';
        if ($significant) {
            $badgeType = $direction === 'increase' ? 'warning' : 'success';
        }

        return [
            'significant'  => $significant,
            'direction'    => $direction,
            'old_price'    => $oldPrice,
            'new_price'    => $newPrice,
            'difference'   => $difference,
            'percent'      => $percent,
            'currency'     => $currency,
            'badge_type'   => $badgeType,
            'context'      => $context,
            'booking_meta' => $bookingMeta,
            'timestamp'    => time(),
        ];
    }

    /**
     * Check if a price change is significant enough to show to the user.
     */
    public function isSignificant(float $oldPrice, float $newPrice): bool
    {
        if ($oldPrice <= 0) {
            return false;
        }
        $percent = abs($newPrice - $oldPrice) / $oldPrice * 100;
        return $percent >= $this->getTolerancePercent() && abs($newPrice - $oldPrice) > 0.01;
    }

    /**
     * Store a price change alert in the session for display at the next page render.
     *
     * @param array $alertData Output from analyse()
     * @param string $cartId   Cart product key (for checkout line-item matching)
     */
    public function storeAlert(array $alertData, string $cartId = ''): void
    {
        $alerts = Tygh::$app['session'][self::SESSION_KEY] ?? [];
        $key = $cartId ?: 'global_' . count($alerts);
        $alerts[$key] = $alertData;
        Tygh::$app['session'][self::SESSION_KEY] = $alerts;
    }

    /**
     * Retrieve and clear all pending price change alerts from the session.
     *
     * @return array Cart-ID-keyed array of alert data
     */
    public function consumeAlerts(): array
    {
        $alerts = Tygh::$app['session'][self::SESSION_KEY] ?? [];
        unset(Tygh::$app['session'][self::SESSION_KEY]);
        return $alerts;
    }

    /**
     * Retrieve pending alerts without clearing them (for template rendering).
     *
     * @return array Cart-ID-keyed array of alert data
     */
    public function peekAlerts(): array
    {
        return Tygh::$app['session'][self::SESSION_KEY] ?? [];
    }

    /**
     * Get the configured tolerance threshold percentage.
     * Changes below this percentage are silently applied.
     */
    private function getTolerancePercent(): float
    {
        return max(0.0, (float) (ConfigProvider::get('price_change_tolerance_percent', self::DEFAULT_TOLERANCE_PERCENT)));
    }
}
