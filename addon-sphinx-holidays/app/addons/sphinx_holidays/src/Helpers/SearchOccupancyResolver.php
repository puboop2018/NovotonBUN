<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Dto\Search\RoomSpec;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Builds the Sphinx search `occupancy` array — the API contract is
 * `occupancy: [{adults: int, children_ages: [int]}]` with ONE entry PER ROOM
 * (Documentation/Sphinx API.txt, hotels/search: the worked example shows two
 * rooms with different children_ages).
 *
 * Two sources, in priority order:
 *  1. The widget's `rooms_data` JSON (per-room `{adults, children,
 *     childrenAges}` — parsed via the shared RoomSpec, which int-casts ages
 *     and keeps age 0). This is the only source that can express DIFFERENT
 *     occupancies per room.
 *  2. Legacy flat params (`adults`, `rooms`, `children_ages` CSV) for old /
 *     hand-built URLs: the totals are replicated into every room (the
 *     pre-rooms_data behaviour, correct for the dominant rooms=1 case).
 *
 * Fixed here and pinned by SearchOccupancyResolverTest:
 *  - infant age "0" in the CSV was dropped (`!empty("0")` is true), silently
 *    searching adults-only;
 *  - multi-room searches ignored rooms_data and sent the TOTAL adults + ALL
 *    child ages duplicated into every room;
 *  - rooms=0 produced an EMPTY occupancy array.
 */
final class SearchOccupancyResolver
{
    /** Upper bound on rooms for the legacy replicate path (widget caps far lower). */
    private const int MAX_ROOMS = 8;

    /**
     * @return list<array{adults: int, children_ages: list<int>}>
     */
    public static function resolve(string $roomsDataJson, int $adults, int $rooms, string $childrenAgesCsv): array
    {
        $fromRooms = self::fromRoomsDataJson($roomsDataJson);
        if ($fromRooms !== []) {
            return $fromRooms;
        }

        $ages = self::parseAgesCsv($childrenAgesCsv);
        $adults = max(1, $adults);
        $rooms = min(self::MAX_ROOMS, max(1, $rooms));

        $occupancy = [];
        for ($r = 0; $r < $rooms; $r++) {
            $occupancy[] = ['adults' => $adults, 'children_ages' => $ages];
        }

        return $occupancy;
    }

    /**
     * @return list<array{adults: int, children_ages: list<int>}>
     */
    private static function fromRoomsDataJson(string $roomsDataJson): array
    {
        if ($roomsDataJson === '') {
            return [];
        }

        $decoded = json_decode($roomsDataJson, true);
        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        $occupancy = [];
        foreach ($decoded as $room) {
            if (!is_array($room)) {
                return []; // malformed payload — let the legacy params decide
            }
            $spec = RoomSpec::fromArray(TypeCoerce::toStringMap($room));
            $occupancy[] = ['adults' => $spec->adults, 'children_ages' => $spec->childrenAges];
        }

        return $occupancy;
    }

    /**
     * CSV → int ages. `$csv !== ''` (not `!empty()`) so a lone infant age
     * "0" survives; blank tokens from trailing commas are skipped.
     *
     * @return list<int>
     */
    private static function parseAgesCsv(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        $ages = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ($token === '' || !is_numeric($token)) {
                continue;
            }
            $ages[] = (int) $token;
        }

        return $ages;
    }
}
