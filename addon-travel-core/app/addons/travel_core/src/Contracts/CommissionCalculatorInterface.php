<?php
declare(strict_types=1);
/**
 * Commission Calculator Interface
 *
 * Contract for commission application and price rounding.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface CommissionCalculatorInterface
{
    /**
     * Apply commission and optional rounding to a price.
     *
     * @param float $price Base price
     * @return float Price with commission applied
     */
    public function apply(float $price): float;

    /**
     * Get commission percentage.
     *
     * @return float
     */
    public function getCommission(): float;

    /**
     * Get round prices setting.
     *
     * @return string 'Y' or 'N'
     */
    public function getRoundPrices(): string;
}
