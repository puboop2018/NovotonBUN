<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Dto\Booking;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Guest roster for a booking — adults/children counts, the holder name,
 * the flat CSV of guest names (display), and the JSON-encoded full
 * guests-data payload (parsed downstream).
 *
 * The dual string forms (CSV display vs JSON payload) are load-bearing:
 * admin emails render the CSV, the reservation API consumer re-decodes
 * the JSON. Keep both in the DTO so neither caller has to reconstruct.
 */
final readonly class GuestList
{
    /**
     * @param list<int> $childrenAges
     */
    public function __construct(
        public int $adults,
        public int $children,
        public array $childrenAges,
        public string $holderName,
        public string $guestNamesCsv,
        public string $guestsDataJson,
    ) {
    }

    public function childrenAgesCsv(): string
    {
        return $this->childrenAges === [] ? '' : implode(',', $this->childrenAges);
    }

    /**
     * @param array<string, mixed> $extra cart-item extra bag
     */
    public static function fromCartExtra(array $extra): self
    {
        return new self(
            adults: TypeCoerce::toInt($extra['adults'] ?? 0),
            children: TypeCoerce::toInt($extra['children'] ?? 0),
            childrenAges: self::parseAgesCsv(TypeCoerce::toString($extra['children_ages'] ?? '')),
            holderName: TypeCoerce::toString($extra['holder_name'] ?? ''),
            guestNamesCsv: TypeCoerce::toString($extra['guest_names'] ?? ''),
            guestsDataJson: TypeCoerce::toString($extra['guests_data'] ?? ''),
        );
    }

    /**
     * Build from the parsed guests data (form rows) + adults/children counts.
     *
     * @param list<array<string, mixed>> $guestsData Rows with `name`/`type`/`age` keys
     */
    public static function fromGuestsData(array $guestsData, int $adults, int $children, string $fallbackChildrenAgesCsv = ''): self
    {
        $names = [];
        $childAges = [];
        foreach ($guestsData as $g) {
            $row = TypeCoerce::toStringMap($g);
            $name = TypeCoerce::toString($row['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
            if (($row['type'] ?? '') === 'child' && isset($row['age'])) {
                $childAges[] = TypeCoerce::toInt($row['age']);
            }
        }

        $ages = $childAges !== [] ? $childAges : self::parseAgesCsv($fallbackChildrenAgesCsv);

        return new self(
            adults: $adults,
            children: $children,
            childrenAges: $ages,
            holderName: $names[0] ?? '',
            guestNamesCsv: implode(', ', $names),
            guestsDataJson: json_encode($guestsData) ?: '[]',
        );
    }

    /**
     * @return list<int>
     */
    private static function parseAgesCsv(string $csv): array
    {
        if ($csv === '') {
            return [];
        }
        $ages = [];
        foreach (explode(',', $csv) as $age) {
            $age = trim($age);
            if ($age !== '' && is_numeric($age)) {
                $ages[] = (int) $age;
            }
        }
        return $ages;
    }
}
