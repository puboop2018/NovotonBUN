<?php
declare(strict_types=1);
/**
 * HotelId Value Object
 *
 * Wraps a hotel identifier, ensuring it is always a non-empty trimmed string.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\ValueObjects;

use Tygh\Addons\TravelCore\Exceptions\InvalidArgumentException;

final class HotelId
{
    /** @var string */
    private $id;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function fromString(string $id): self
    {
        $trimmed = trim($id);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Hotel ID cannot be empty');
        }
        return new self($trimmed);
    }

    public static function tryFromString(?string $id): ?self
    {
        if ($id === null || trim($id) === '') {
            return null;
        }
        return new self(trim($id));
    }

    public function toString(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }
}
