<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Price + display-text fields for a booking cart item.
 *
 * `totalPrice` is the single source of truth; `price`, `base_price`,
 * and `original_price` on the outer cart-product array all derive from
 * this same value.
 */
final readonly class BookingPricing
{
    public function __construct(
        public float $totalPrice,
        public string $currency,
        public string $remark,
        public string $important,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            totalPrice: TypeCoerce::toFloat($extra['total_price'] ?? 0),
            currency: TypeCoerce::toString($extra['currency'] ?? ''),
            remark: TypeCoerce::toString($extra['remark'] ?? ''),
            important: TypeCoerce::toString($extra['important'] ?? ''),
        );
    }
}
