<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;

/**
 * One checkout price policy for ALL provider addons (audit A1 follow-up).
 *
 * Builds the shared PriceComparisonPolicy from travel_core settings so novoton
 * and sphinx apply identical rules — previously novoton alerted at a
 * configurable 55% of the API price with no float epsilon, while sphinx used a
 * hardcoded 20% of the form price with a 0.01 epsilon.
 *
 * Settings (Add-ons → Travel Core), all with code-side defaults so absent
 * settings — e.g. before an addon settings refresh — behave sanely:
 *   checkout_alert_percent   overpayment % that alerts admins        (default 20)
 *   checkout_alert_floor     minimum overpayment € for a % alert     (default 5)
 *   checkout_big_overage     overpayment € that always alerts        (default 100)
 *   checkout_absorb_increase price increase € the merchant absorbs
 *                            instead of asking the customer to
 *                            re-confirm; 0 = always re-confirm       (default 0)
 */
final class CheckoutPriceGuard
{
    /**
     * Sub-cent float-noise tolerance (engineering constant, not a setting):
     * prices pass through commission multiplication and serialization, so
     * "equal" prices routinely differ by fractions of a cent.
     */
    public const MATCH_EPSILON = 0.01;

    public static function policy(): PriceComparisonPolicy
    {
        return new PriceComparisonPolicy(
            self::alertPercent(),
            self::MATCH_EPSILON,
            alertFloorAbs: self::alertFloor(),
            bigOverageAbs: self::bigOverage(),
        );
    }

    public static function alertPercent(): float
    {
        return max(0.0, self::floatSetting('checkout_alert_percent', 20.0));
    }

    public static function alertFloor(): float
    {
        return max(0.0, self::floatSetting('checkout_alert_floor', 5.0));
    }

    public static function bigOverage(): float
    {
        return max(0.0, self::floatSetting('checkout_big_overage', 100.0));
    }

    /**
     * Price increases up to this amount are absorbed by the merchant (the
     * customer pays the price they were shown); larger increases correct the
     * cart and require the customer to re-confirm the order.
     */
    public static function absorbIncrease(): float
    {
        return max(0.0, self::floatSetting('checkout_absorb_increase', 0.0));
    }

    private static function floatSetting(string $name, float $default): float
    {
        return RegistryCoerce::float('addons.travel_core.' . $name, $default);
    }
}
