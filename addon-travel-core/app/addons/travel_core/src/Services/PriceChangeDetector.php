<?php
declare(strict_types=1);
/**
 * Travel Core Price Change Detector
 *
 * Implements the "No Surprises" policy for price change communication.
 * Shared across all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Tygh;

class PriceChangeDetector
{
    /** Default tolerance threshold (percentage). Changes below this are silent. */
    private const DEFAULT_TOLERANCE_PERCENT = 1.0;

    /** Minimum absolute price difference to be considered significant (avoids floating-point noise). */
    private const MIN_ABSOLUTE_DIFFERENCE = 0.01;

    /** Session key for storing price change alerts. */
    private const SESSION_KEY = 'travel_price_change_alerts';

    /** @var float Custom tolerance, or 0 for default */
    private readonly float $tolerancePercent;

    public function __construct(float $tolerancePercent = 0.0)
    {
        $this->tolerancePercent = $tolerancePercent;
    }

    /**
     * Analyse a price change and return structured display data.
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

        $tolerance   = $this->getTolerancePercent();
        $significant = $percent >= $tolerance && abs($difference) > self::MIN_ABSOLUTE_DIFFERENCE;

        if ($difference > 0) {
            $direction = 'increase';
        } elseif ($difference < 0) {
            $direction = 'decrease';
        } else {
            $direction = 'none';
        }

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
        $absDiff = abs($newPrice - $oldPrice);
        $percent = $absDiff / $oldPrice * 100;
        return $percent >= $this->getTolerancePercent() && $absDiff > self::MIN_ABSOLUTE_DIFFERENCE;
    }

    /**
     * Store a price change alert in the session.
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
     */
    public function consumeAlerts(): array
    {
        $alerts = Tygh::$app['session'][self::SESSION_KEY] ?? [];
        unset(Tygh::$app['session'][self::SESSION_KEY]);
        return $alerts;
    }

    /**
     * Retrieve pending alerts without clearing them.
     */
    public function peekAlerts(): array
    {
        return Tygh::$app['session'][self::SESSION_KEY] ?? [];
    }

    private function getTolerancePercent(): float
    {
        if ($this->tolerancePercent > 0) {
            return $this->tolerancePercent;
        }
        return self::DEFAULT_TOLERANCE_PERCENT;
    }
}
