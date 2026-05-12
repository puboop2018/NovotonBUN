<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Dto\Invoice;

use Tygh\Addons\FgoInvoicing\Constants;

/**
 * Romanian VAT rate, snapped to one of the standard {0, 5, 9, 11, 21}
 * values FGO accepts. Mirrors the WooCommerce reference plugin's
 * CONFIG::closestVATRate(), with the addition of 0% as a permitted output.
 */
final readonly class VatRate
{
    public function __construct(public int $percent)
    {
        if (!in_array($percent, Constants::VAT_RATES, true)) {
            throw new \InvalidArgumentException(
                'VatRate must be one of {' . implode(',', Constants::VAT_RATES) . '}, got ' . $percent,
            );
        }
    }

    /**
     * Snap an arbitrary VAT percentage (e.g. computed from subtotal/tax)
     * to the nearest accepted FGO rate.
     */
    public static function snap(float $rawPercent): self
    {
        if (is_nan($rawPercent) || $rawPercent <= 0.0) {
            return new self(0);
        }

        $rounded = (int) round($rawPercent);
        if (in_array($rounded, Constants::VAT_RATES, true)) {
            return new self($rounded);
        }

        $closest = Constants::VAT_RATES[0];
        $bestDiff = PHP_INT_MAX;
        foreach (Constants::VAT_RATES as $rate) {
            $diff = (int) abs($rate - $rounded);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $closest = $rate;
            }
        }
        return new self($closest);
    }

    /**
     * Compute the VAT rate from a subtotal/tax pair, then snap to FGO.
     */
    public static function fromSubtotalAndTax(float $subtotal, float $tax): self
    {
        if ($subtotal <= 0.0) {
            return new self(0);
        }
        $pct = ((($subtotal + $tax) / $subtotal) - 1) * 100;
        return self::snap($pct);
    }
}
