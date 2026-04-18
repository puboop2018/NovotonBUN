<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Booker contact details (email + phone) stored on the cart item.
 *
 * Format not validated at the DTO boundary — business-level validation
 * (RFC email, phone shape) happens in SecurityService / the form layer.
 */
final readonly class ContactInfo
{
    public function __construct(
        public string $email,
        public string $phone,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            email: TypeCoerce::toString($extra['contact_email'] ?? ''),
            phone: TypeCoerce::toString($extra['contact_phone'] ?? ''),
        );
    }

    /**
     * Build from the nested `contact` sub-array inside booking form data:
     *   ['contact' => ['email' => '...', 'phone' => '...']]
     *
     * @param array<string, mixed> $bookingData
     */
    public static function fromBookingData(array $bookingData): self
    {
        $contact = TypeCoerce::toStringMap($bookingData['contact'] ?? []);
        return new self(
            email: TypeCoerce::toString($contact['email'] ?? ''),
            phone: TypeCoerce::toString($contact['phone'] ?? ''),
        );
    }
}
