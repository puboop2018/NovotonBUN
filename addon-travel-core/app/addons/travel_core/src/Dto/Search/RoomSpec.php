<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Search;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Per-physical-room occupancy spec for a multi-room search.
 *
 * Shape mirrors the React booking widget's JSON payload:
 *   `{"adults": 2, "children": 1, "childrenAges": [6]}`
 *
 * Normalisation rules: `adults` defaults to 2 if missing/invalid,
 * `children` is derived from `childrenAges` length when ages are given
 * (source of truth) else falls back to the declared count with an empty
 * ages list.
 */
final readonly class RoomSpec
{
    /**
     * @param list<int> $childrenAges
     */
    public function __construct(
        public int $adults,
        public int $children,
        public array $childrenAges,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $adults = TypeCoerce::toInt($raw['adults'] ?? 2);
        if ($adults < 1) {
            $adults = 2;
        }

        $ages = self::cleanAges($raw['childrenAges'] ?? []);

        if ($ages !== []) {
            $children = count($ages);
        } else {
            $children = TypeCoerce::toInt($raw['children'] ?? 0);
            if ($children < 0) {
                $children = 0;
            }
        }

        return new self($adults, $children, $ages);
    }

    /**
     * @return array{adults: int, children: int, childrenAges: list<int>}
     */
    public function toArray(): array
    {
        return [
            'adults' => $this->adults,
            'children' => $this->children,
            'childrenAges' => $this->childrenAges,
        ];
    }

    /**
     * @return list<int>
     */
    private static function cleanAges(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ages = [];
        foreach ($raw as $age) {
            if ($age === null || $age === '' || $age === 'null' || $age === 'age_needed') {
                continue;
            }
            if (!is_numeric($age)) {
                continue;
            }
            $ages[] = TypeCoerce::toInt($age);
        }
        return $ages;
    }
}
