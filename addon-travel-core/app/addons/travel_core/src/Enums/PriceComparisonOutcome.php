<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Enums;

/**
 * The three outcomes of the checkout "No Surprises" price policy.
 */
enum PriceComparisonOutcome
{
    /** Prices agree (within the configured epsilon) — no action. */
    case Match;

    /**
     * The customer would pay LESS than the live price — silently correct the
     * cart upward so the order can complete. Never block.
     */
    case CorrectUp;

    /**
     * The customer would pay MORE than the live price by more than the alert
     * threshold — allow the order but notify admins to investigate.
     */
    case AboveThreshold;
}
