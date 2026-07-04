<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto;

use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;

/**
 * Result of comparing a checkout form price against the live API price.
 *
 * @see \Tygh\Addons\TravelCore\Services\PriceComparisonPolicy
 */
final readonly class PriceComparison
{
    /**
     * @param PriceComparisonOutcome $outcome what the "No Surprises" policy decided
     * @param float $difference absolute price difference (>= 0), unrounded
     * @param float $percentDelta difference as a percent of the configured base, unrounded
     */
    public function __construct(
        public PriceComparisonOutcome $outcome,
        public float $difference,
        public float $percentDelta,
    ) {
    }
}
