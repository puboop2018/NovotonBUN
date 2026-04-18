<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Check-in / check-out pair with derived nights count.
 *
 * Kept intentionally simple — no timezone arithmetic, mirrors the
 * existing `calculateNights()` string-diff logic.
 */
final readonly class StayDates
{
    public function __construct(
        public string $checkIn,
        public string $checkOut,
        public int $nights,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            checkIn: TypeCoerce::toString($extra['check_in'] ?? ''),
            checkOut: TypeCoerce::toString($extra['check_out'] ?? ''),
            nights: TypeCoerce::toInt($extra['nights'] ?? 0),
        );
    }

    /**
     * Build from check-in/check-out strings, deriving nights.
     */
    public static function fromDates(string $checkIn, string $checkOut): self
    {
        return new self($checkIn, $checkOut, self::nightsBetween($checkIn, $checkOut));
    }

    public static function nightsBetween(string $checkIn, string $checkOut): int
    {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);
        if (!$in || !$out || $out <= $in) {
            return 0;
        }
        return (int) (($out - $in) / 86400);
    }
}
