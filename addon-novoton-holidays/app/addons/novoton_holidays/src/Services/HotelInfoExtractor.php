<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;

/**
 * Parses the cached hotelinfo XML into the typed structures the availability
 * search needs: the rooms nodes, the board-type list (optionally re-ordered for
 * a selected meal plan), and the IdRoom => Type map.
 *
 * Extracted from HotelAvailabilitySearcher, where these pure XML routines sat
 * alongside the API-orchestration logic. Behaviour is preserved verbatim;
 * HotelAvailabilitySearcher delegates here. The only adjustment is the board
 * de-dup using a strict in_array (all entries are strings).
 */
class HotelInfoExtractor
{
    /** @return list<\SimpleXMLElement> */
    public function extractRooms(\SimpleXMLElement $hotelInfo): array
    {
        /** @var list<\SimpleXMLElement> */
        return array_values($hotelInfo->xpath('//rooms') ?: []);
    }

    /** @return list<string> */
    public function extractBoardTypes(\SimpleXMLElement $hotelInfo, string $mealPlan): array
    {
        $boardTypes = [];
        $boardElements = $hotelInfo->xpath('//board') ?: [];
        foreach ($boardElements as $b) {
            $boardId = (string) $b->IdBoard ?: (string) $b;
            if (!empty($boardId)) {
                $boardTypes[] = $boardId;
            }
        }

        if (empty($boardTypes)) {
            $boardTypes = ['ALL INCL', 'AI', 'FB', 'HB', 'BB', 'RO'];
        }

        // Re-order by preferred if a specific meal plan was selected
        if (!empty($mealPlan)) {
            $boardMapping = Constants::BOARD_MAPPING;
            $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];

            $reordered = [];
            foreach ($preferredBoards as $pb) {
                foreach ($boardTypes as $bt) {
                    if (str_contains(strtolower($bt), strtolower($pb)) || str_contains(strtolower($pb), strtolower($bt))) {
                        $reordered[] = $bt;
                    }
                }
            }
            foreach ($boardTypes as $bt) {
                if (!in_array($bt, $reordered, true)) {
                    $reordered[] = $bt;
                }
            }
            $boardTypes = array_unique($reordered);
        }

        return array_values($boardTypes);
    }

    /**
     * @return array<string, mixed>
     * @param list<\SimpleXMLElement> $rooms
     */
    public function buildRoomTypeMap(array $rooms): array
    {
        $map = [];
        foreach ($rooms as $roomNode) {
            $id = trim((string) ($roomNode->IdRoom ?? ''));
            $type = trim((string) ($roomNode->Type ?? ''));
            if (!empty($id) && !empty($type)) {
                $map[$id] = $type;
            }
        }

        return $map;
    }
}
