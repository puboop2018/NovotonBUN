<?php
declare(strict_types=1);
/**
 * Occupancy Value Object
 *
 * Encapsulates the number of adults, children, and children ages for a single room.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\ValueObjects;

use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\Exceptions\InvalidArgumentException;

final class Occupancy
{
    /** @var int */
    private $adults;

    /** @var int */
    private $children;

    /** @var int[] */
    private $childrenAges;

    private function __construct(int $adults, int $children, array $childrenAges)
    {
        $this->adults       = $adults;
        $this->children     = $children;
        $this->childrenAges = $childrenAges;
    }

    public static function create(int $adults, int $children = 0, array $childrenAges = []): self
    {
        if ($adults < 1 || $adults > TravelConstants::MAX_ADULTS) {
            throw new InvalidArgumentException(
                "Adults must be between 1 and " . TravelConstants::MAX_ADULTS . ", got: {$adults}"
            );
        }
        if ($children < 0 || $children > TravelConstants::MAX_CHILDREN) {
            throw new InvalidArgumentException(
                "Children must be between 0 and " . TravelConstants::MAX_CHILDREN . ", got: {$children}"
            );
        }

        $ages = [];
        for ($i = 0; $i < $children; $i++) {
            $age = isset($childrenAges[$i]) ? (int)($childrenAges[$i]) : 0;
            if ($age < TravelConstants::MIN_CHILD_AGE || $age > TravelConstants::MAX_CHILD_AGE) {
                $age = max(TravelConstants::MIN_CHILD_AGE, min(TravelConstants::MAX_CHILD_AGE, $age));
            }
            $ages[] = $age;
        }

        return new self($adults, $children, $ages);
    }

    public static function defaults(): self
    {
        return new self(TravelConstants::DEFAULT_ADULTS, TravelConstants::DEFAULT_CHILDREN, []);
    }

    public function adults(): int        { return $this->adults; }
    public function children(): int      { return $this->children; }
    public function childrenAges(): array { return $this->childrenAges; }
    public function totalGuests(): int   { return $this->adults + $this->children; }
    public function hasChildren(): bool  { return $this->children > 0; }

    public function toDisplayString(): string
    {
        $str = $this->adults . ' adult' . ($this->adults !== 1 ? 's' : '');
        if ($this->children > 0) {
            $str .= ', ' . $this->children . ' child' . ($this->children !== 1 ? 'ren' : '');
        }
        return $str;
    }

    public function equals(self $other): bool
    {
        return $this->adults === $other->adults
            && $this->children === $other->children
            && $this->childrenAges === $other->childrenAges;
    }

    public function __toString(): string
    {
        return $this->toDisplayString();
    }
}
