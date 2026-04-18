<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Chosen room within a booking — id + display labels.
 */
final readonly class RoomSelection
{
    public function __construct(
        public string $roomId,
        public string $roomName,
        public string $typeDisplay,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            roomId: TypeCoerce::toString($extra['room_id'] ?? ''),
            roomName: TypeCoerce::toString($extra['room_name'] ?? ''),
            typeDisplay: TypeCoerce::toString($extra['room_type_display'] ?? ''),
        );
    }
}
