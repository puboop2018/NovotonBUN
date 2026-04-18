<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Chosen board (meal plan) within a booking — id + display name.
 */
final readonly class BoardSelection
{
    public function __construct(
        public string $boardId,
        public string $boardName,
    ) {
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            boardId: TypeCoerce::toString($extra['board_id'] ?? ''),
            boardName: TypeCoerce::toString($extra['board_name'] ?? ''),
        );
    }
}
