<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Payment + cancellation terms for a booking.
 *
 * The `*_raw` fields hold the unmodified API string (for admin audit /
 * re-rendering); the formatted fields are what the frontend shows.
 */
final readonly class BookingTerms
{
    public function __construct(
        public string $payment,
        public string $paymentRaw,
        public string $cancellation,
        public string $cancellationRaw,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            payment: TypeCoerce::toString($extra['terms_of_payment'] ?? ''),
            paymentRaw: TypeCoerce::toString($extra['terms_of_payment_raw'] ?? ''),
            cancellation: TypeCoerce::toString($extra['terms_of_cancellation'] ?? ''),
            cancellationRaw: TypeCoerce::toString($extra['terms_of_cancellation_raw'] ?? ''),
        );
    }
}
