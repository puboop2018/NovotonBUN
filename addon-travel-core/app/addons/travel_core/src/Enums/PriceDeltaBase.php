<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Enums;

/**
 * Denominator used when expressing a checkout price delta as a percentage.
 *
 * The two providers historically diverged here (novoton: API price,
 * sphinx: form price). The enum keeps that divergence explicit and
 * configuration-visible instead of buried in inline arithmetic.
 */
enum PriceDeltaBase
{
    /** Percent of the live API price (novoton's historical behaviour). */
    case Api;

    /** Percent of the price shown on the form (sphinx's historical behaviour). */
    case Form;
}
