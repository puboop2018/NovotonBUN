<?php
declare(strict_types=1);
/**
 * Occupancy Value Object
 *
 * Encapsulates the number of adults, children, and children ages for a single
 * room. Validates limits from Constants and provides occupancy string formatting.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\ValueObjects;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\NovotonHolidays\Exceptions\InvalidArgumentException;

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

    /**
     * Create an occupancy.
     *
     * @param int   $adults       Number of adults (1–MAX_ADULTS)
     * @param int   $children     Number of children (0–MAX_CHILDREN)
     * @param int[] $childrenAges Ages array (one per child)
     * @return self
     * @throws InvalidArgumentException
     */
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

        // Ensure ages array matches child count
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

    /**
     * Create from default search parameters.
     */
    public static function defaults(): self
    {
        return new self(TravelConstants::DEFAULT_ADULTS, TravelConstants::DEFAULT_CHILDREN, []);
    }

    public function adults(): int
    {
        return $this->adults;
    }

    public function children(): int
    {
        return $this->children;
    }

    /**
     * @return int[]
     */
    public function childrenAges(): array
    {
        return $this->childrenAges;
    }

    public function totalGuests(): int
    {
        return $this->adults + $this->children;
    }

    public function hasChildren(): bool
    {
        return $this->children > 0;
    }

    /**
     * Build the XML children fragment for the Novoton API.
     */
    public function toChildrenXml(): string
    {
        return self::buildAgeXml($this->childrenAges);
    }

    /**
     * Build <Age> XML elements from a raw ages array.
     *
     * Single source of truth for the age XML format used across
     * value objects and API clients.
     *
     * @param int[] $ages
     */
    public static function buildAgeXml(array $ages): string
    {
        $xml = '';
        foreach ($ages as $age) {
            $xml .= '<Age>' . (int) $age . '</Age>';
        }
        return $xml;
    }

    /**
     * Human-readable occupancy string for display.
     */
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
