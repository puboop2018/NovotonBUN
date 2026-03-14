<?php
declare(strict_types=1);
/**
 * Commission Calculator
 *
 * Handles commission application and price rounding.
 * Shared implementation used by all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\CommissionCalculatorInterface;

class CommissionCalculator implements CommissionCalculatorInterface
{
    private float $commission;
    private string $roundPrices;

    public function __construct(float $commission, string $roundPrices = 'Y')
    {
        $this->commission = max(0.0, $commission);
        $this->roundPrices = $roundPrices;
    }

    /**
     * Apply commission and optional rounding to a price.
     *
     * @param float $price Base price
     * @return float Price with commission applied
     */
    public function apply(float $price): float
    {
        $finalPrice = $price * (1 + ($this->commission / 100));

        if ($this->roundPrices === 'Y') {
            $finalPrice = round($finalPrice);
        }

        return $finalPrice;
    }

    /**
     * @return float Commission percentage
     */
    public function getCommission(): float
    {
        return $this->commission;
    }

    /**
     * @return string 'Y' or 'N'
     */
    public function getRoundPrices(): string
    {
        return $this->roundPrices;
    }
}
