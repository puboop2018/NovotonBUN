<?php
declare(strict_types=1);
/**
 * Commission Calculator
 * Handles commission application and price rounding.
 *
 * Path: app/addons/novoton_holidays/src/CommissionCalculator.php
 */

namespace Tygh\Addons\NovotonHolidays;

class CommissionCalculator implements CommissionCalculatorInterface
{
    /** @var float Commission percentage */
    private $commission;

    /** @var string Round prices ('Y'/'N') */
    private $roundPrices;

    public function __construct(float $commission, string $roundPrices = 'Y')
    {
        $this->commission = max(0.0, $commission);
        $this->roundPrices = $roundPrices;
    }

    /**
     * Apply commission and optional rounding to a price
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
