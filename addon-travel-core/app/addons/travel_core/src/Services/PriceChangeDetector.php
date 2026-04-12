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

use Tygh\Addons\TravelCore\Contracts\PriceChangeDetectorInterface;
use Tygh\Tygh;

class PriceChangeDetector implements PriceChangeDetectorInterface
{
    /** Default tolerance threshold (percentage). Changes below this are silent. */
    private const DEFAULT_TOLERANCE_PERCENT = 1.0;

    /** Minimum absolute price difference to be considered significant (avoids floating-point noise). */
    private const MIN_ABSOLUTE_DIFFERENCE = 0.01;

    /** Session key for storing price change alerts. */
    private const SESSION_KEY = 'travel_price_change_alerts';

    public function __construct(
        private readonly float $tolerancePercent = 0.0,
    ) {
    }

    /**
     * Analyse a price change and return structured display data.
     *
     * @param array<string, mixed> $bookingMeta
     * @return array<string, mixed>
     */
    #[\Override]
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

        $direction = match (true) {
            $difference > 0 => 'increase',
            $difference < 0 => 'decrease',
            default         => 'none',
        };

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
    #[\Override]
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
     *
     * @param array<string, mixed> $alertData
     */
    #[\Override]
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
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function consumeAlerts(): array
    {
        $alerts = Tygh::$app['session'][self::SESSION_KEY] ?? [];
        unset(Tygh::$app['session'][self::SESSION_KEY]);
        return $alerts;
    }

    /**
     * Retrieve pending alerts without clearing them.
     *
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
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
