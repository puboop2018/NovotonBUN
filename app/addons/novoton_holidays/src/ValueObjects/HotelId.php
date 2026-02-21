<?php
declare(strict_types=1);
/**
 * HotelId Value Object
 *
 * Wraps the Novoton hotel identifier, ensuring it is always a non-empty
 * trimmed string. Prevents passing random strings or integers where a
 * validated hotel ID is expected.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\ValueObjects;

use Tygh\Addons\NovotonHolidays\Exceptions\InvalidArgumentException;

final class HotelId
{
    /** @var string */
    private $id;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    /**
     * Create from a raw string.
     *
     * @param string $id Raw hotel ID
     * @return self
     * @throws InvalidArgumentException If the ID is empty after trimming
     */
    public static function fromString(string $id): self
    {
        $trimmed = trim($id);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Hotel ID cannot be empty');
        }
        return new self($trimmed);
    }

    /**
     * Create from a nullable string (returns null for empty).
     */
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
