<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\PriceComparison;
use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;
use Tygh\Addons\TravelCore\Enums\PriceDeltaBase;

/**
 * The shared checkout "No Surprises" price policy, extracted from the two
 * providers' PreOrderPriceVerifier implementations (audit A1).
 *
 * Rule, applied at the final checkout step:
 *   - form price BELOW the live API price      → correct the cart up, never block
 *   - form price ABOVE it beyond the threshold → allow, but alert admins
 *   - otherwise                                → match, no action
 *
 * The knobs encode the providers' historical arithmetic differences
 * explicitly (they had silently drifted while duplicated):
 *   - novoton: percent of API price, no match epsilon, threshold from settings
 *   - sphinx:  percent of form price, 0.01 match epsilon, threshold 20
 * Converging them is a product decision; until then the divergence is
 * visible here instead of buried in two copies of inline math.
 */
final readonly class PriceComparisonPolicy
{
    /**
     * @param float $alertThresholdPercent overpayment percent above which admins are alerted
     * @param float $matchEpsilon absolute difference treated as float noise (a match)
     * @param PriceDeltaBase $percentBase denominator for the percent
     * @param float $alertFloorAbs minimum absolute overpayment for a percent-based alert
     *                             (stops percent-only false alarms on cheap items)
     * @param float|null $bigOverageAbs absolute overpayment that alerts regardless of percent
     *                                  (catches large drifts on expensive items); null disables
     */
    public function __construct(
        private float $alertThresholdPercent,
        private float $matchEpsilon = 0.0,
        private PriceDeltaBase $percentBase = PriceDeltaBase::Api,
        private float $alertFloorAbs = 0.0,
        private ?float $bigOverageAbs = null,
    ) {
    }

    public function compare(float $formPrice, float $apiPrice): PriceComparison
    {
        $difference = abs($apiPrice - $formPrice);
        $base = match ($this->percentBase) {
            PriceDeltaBase::Api => $apiPrice,
            PriceDeltaBase::Form => $formPrice,
        };
        $percentDelta = $base > 0 ? ($difference / $base) * 100 : 0.0;

        // No live price to compare against — nothing to correct or alert on.
        if ($apiPrice <= 0) {
            return new PriceComparison(PriceComparisonOutcome::Match, $difference, $percentDelta);
        }

        if ($this->matchEpsilon > 0 && $difference < $this->matchEpsilon) {
            return new PriceComparison(PriceComparisonOutcome::Match, $difference, $percentDelta);
        }

        if ($formPrice < $apiPrice) {
            return new PriceComparison(PriceComparisonOutcome::CorrectUp, $difference, $percentDelta);
        }

        if ($this->bigOverageAbs !== null && $difference >= $this->bigOverageAbs) {
            return new PriceComparison(PriceComparisonOutcome::AboveThreshold, $difference, $percentDelta);
        }

        if ($percentDelta > $this->alertThresholdPercent && $difference >= $this->alertFloorAbs) {
            return new PriceComparison(PriceComparisonOutcome::AboveThreshold, $difference, $percentDelta);
        }

        return new PriceComparison(PriceComparisonOutcome::Match, $difference, $percentDelta);
    }
}
