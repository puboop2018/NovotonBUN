<?php
declare(strict_types=1);
/**
 * Orchestrates hotel-specific availability searches against the Novoton API.
 *
 * Fetches cached hotel info, builds room-type and board-type maps, then
 * dispatches either single-room or multi-room API calls and returns the
 * structured result arrays the controller and templates expect.
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;

class HotelAvailabilitySearcher
{
    /** @var SearchServiceInterface */
    private SearchServiceInterface $searchService;

    /** @var bool */
    private bool $debug;

    /** @var string[] Accumulated debug lines (read via getDebugLog) */
    private array $debugLog = [];

    public function __construct(SearchServiceInterface $searchService, bool $debug = false)
    {
        $this->searchService = $searchService;
        $this->debug         = $debug;
    }

    // =====================================================================
    // Public API
    // =====================================================================

    /**
     * Search a specific hotel for room availability.
     *
     * @param array $params Normalized params from SearchParameterNormalizer
     * @return array{
     *   results: array,
     *   all_room_results: array,
     *   is_multi_room: bool,
     *   multi_room_total_options: int,
     *   max_room_capacity: array,
     *   early_booking_discounts: array,
     *   early_booking_range: array
     * }
     */
    public function search(array $params): array
    {
        $hotelId  = $params['hotel_id'];
        $checkIn  = $params['check_in'];
        $checkOut = $params['check_out'];
        $nights   = $params['nights'];
        $adults   = $params['adults'];
        $children = $params['children'];
        $mealPlan = $params['meal_plan'];
        $numRooms = $params['num_rooms'];
        $roomsData = $params['rooms_data'];

        $this->log("=== SEARCH DEBUG ===");
        $this->log("Hotel ID: {$hotelId}");
        $this->log("Check-in: {$checkIn}, Check-out: {$checkOut}, Nights: {$nights}");
        $this->log("Adults: {$adults}, Children: " . json_encode($children));
        $this->log("Meal Plan: " . ($mealPlan ?: 'ALL'));

        $this->logCircuitStatus();

        // ── Cached hotel info ────────────────────────────────────────
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);

        $this->logHotelInfo($hotelInfo);

        if (!$hotelInfo || !isset($hotelInfo->rooms)) {
            return $this->emptyResult();
        }

        // ── Rooms / boards / packages from XML ──────────────────────
        $rooms      = $this->extractRooms($hotelInfo);
        $boardTypes = $this->extractBoardTypes($hotelInfo, $mealPlan);
        $packages   = $this->extractPackages($hotelInfo);
        $roomTypeMap = $this->buildRoomTypeMap($rooms);

        // ── API client ──────────────────────────────────────────────
        $api = fn_novoton_holidays_get_api();
        if (!$api) {
            fn_set_notification('W', __('warning'),
                __('novoton_holidays.api_unavailable',
                    ['[default]' => 'API is temporarily unavailable. Please try again later.']
                )
            );
            return $this->emptyResult();
        }

        // ── Single vs multi-room dispatch ────────────────────────────
        if ($numRooms > 1 && count($roomsData) > 1) {
            return $this->searchMultiRoom(
                $api, $hotelId, $checkIn, $checkOut, $nights,
                $mealPlan, $roomsData, $roomTypeMap, $rooms
            );
        }

        return $this->searchSingleRoom(
            $api, $hotelId, $checkIn, $checkOut, $nights,
            $adults, $children, $mealPlan, $roomsData, $roomTypeMap
        );
    }

    /**
     * @return string[]
     */
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * @return \SimpleXMLElement[]  The rooms XML nodes (for alternative search)
     */
    public function getRooms(string $hotelId): array
    {
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);
        if (!$hotelInfo || !isset($hotelInfo->rooms)) {
            return [];
        }
        return $this->extractRooms($hotelInfo);
    }

    /**
     * @return string[]  Board type identifiers
     */
    public function getBoardTypes(string $hotelId, string $mealPlan = ''): array
    {
        $hotelInfo = _nvt_get_cached_hotel_info($hotelId);
        if (!$hotelInfo) {
            return [];
        }
        return $this->extractBoardTypes($hotelInfo, $mealPlan);
    }

    // =====================================================================
    // Multi-room search
    // =====================================================================

    private function searchMultiRoom(
        $api,
        string $hotelId,
        string $checkIn,
        string $checkOut,
        int    $nights,
        string $mealPlan,
        array  $roomsData,
        array  $roomTypeMap,
        array  $rooms
    ): array {
        $this->log("=== MULTI-ROOM SEARCH MODE ===");
        $this->log("Making " . count($roomsData) . " separate API calls, one per room occupancy");

        $allRoomResults = [];

        foreach ($roomsData as $roomIdx => $roomOccupancy) {
            $roomNum       = $roomIdx + 1;
            $roomAdults    = (int) ($roomOccupancy['adults'] ?? 2);
            $roomChildrenCount = (int) ($roomOccupancy['children'] ?? 0);
            $roomChildrenAges = $this->cleanChildrenAges($roomOccupancy['childrenAges'] ?? []);

            $this->log("--- Room #{$roomNum}: {$roomAdults} adults, {$roomChildrenCount} children ---");
            if (!empty($roomChildrenAges)) {
                $this->log("Children ages: " . implode(', ', $roomChildrenAges));
            }

            $priceParams = [
                'hotel_id'    => $hotelId,
                'room_id'     => '',
                'board_id'    => '',
                'star_rating' => '',
                'check_in'    => $checkIn,
                'check_out'   => $checkOut,
                'adults'      => $roomAdults,
                'children'    => $roomChildrenAges,
            ];

            $priceData = $api->getRoomPrice($priceParams);

            $roomResults  = [];
            $occupancyStr = "{$roomAdults} adults"
                . ($roomChildrenCount > 0 ? ", {$roomChildrenCount} children" : '');

            if ($priceData) {
                $rawXml = $api->getLastResponse();
                $this->log("  API Response received (parsing...)");
                $roomResults = $this->searchService->parseRoomPriceResponse(
                    $rawXml, $nights, $checkIn, $checkOut,
                    $mealPlan, [], $roomTypeMap, $roomNum, $occupancyStr
                );
            } else {
                $this->logApiError($api, "  ");
            }

            $allRoomResults[$roomNum] = $roomResults;
            $this->log("  Found " . count($roomResults) . " options for Room #{$roomNum}");
        }

        // ── Aggregate results ────────────────────────────────────────
        $totalOptions = 0;
        $firstResults = [];
        foreach ($allRoomResults as $rr) {
            $totalOptions += count($rr);
            if (empty($firstResults) && !empty($rr)) {
                $firstResults = $rr;
            }
        }

        // Early booking discounts
        $earlyBookingDiscounts = SearchService::getEarlyBookingDiscounts($hotelId, $checkIn, $checkOut);
        $discountRange         = SearchService::getDiscountRange($earlyBookingDiscounts);

        return [
            'results'                => $firstResults,
            'all_room_results'       => $allRoomResults,
            'is_multi_room'          => true,
            'multi_room_total_options' => $totalOptions,
            'no_availability'        => ($totalOptions === 0),
            'max_room_capacity'      => $this->calculateMaxCapacity($firstResults),
            'early_booking_discounts' => $earlyBookingDiscounts,
            'early_booking_range'    => $discountRange,
        ];
    }

    // =====================================================================
    // Single-room search
    // =====================================================================

    private function searchSingleRoom(
        $api,
        string $hotelId,
        string $checkIn,
        string $checkOut,
        int    $nights,
        int    $adults,
        array  $children,
        string $mealPlan,
        array  $roomsData,
        array  $roomTypeMap
    ): array {
        $singleRoomChildren = $children;
        if (empty($singleRoomChildren) && !empty($roomsData[0]['childrenAges'])) {
            $singleRoomChildren = $roomsData[0]['childrenAges'];
        }

        $this->log("=== SINGLE ROOM SEARCH MODE ===");
        $this->log("Adults: {$adults}, Children count: " . count($singleRoomChildren));

        $priceParams = [
            'hotel_id'    => $hotelId,
            'room_id'     => '',
            'board_id'    => '',
            'star_rating' => '',
            'check_in'    => $checkIn,
            'check_out'   => $checkOut,
            'adults'      => $adults,
            'children'    => $singleRoomChildren,
        ];

        $priceData = $api->getRoomPrice($priceParams);

        $this->logSingleRoomDebug($api, $hotelId, $priceParams);

        $results = [];
        if ($priceData) {
            $rawXml = $api->getLastResponse();
            $this->log("=== PARSING ROOM_PRICE RESPONSE ===");

            // Fetch room quota for all rooms
            $quotaMap = [];
            try {
                $quotaMap = $api->getHotelQuotaAll($hotelId, $checkIn, $checkOut);
                $this->log("=== ROOM QUOTA (hotel_quota API) ===");
                foreach ($quotaMap as $qRoom => $qValue) {
                    $this->log("  {$qRoom}: {$qValue}");
                }
            } catch (\Exception $e) {
                $this->log("=== QUOTA FETCH ERROR: " . $e->getMessage() . " ===");
            }

            $results = $this->searchService->parseRoomPriceResponse(
                $rawXml, $nights, $checkIn, $checkOut,
                $mealPlan, $quotaMap, $roomTypeMap
            );

            foreach ($results as $r) {
                $status = $r['is_on_request']
                    ? 'ON REQUEST'
                    : ($r['rooms_available'] !== null ? "{$r['rooms_available']} rooms" : 'available');
                $this->log("  -> ADDED: Room={$r['room_id']}, Board={$r['board_id']}, Price={$r['total_price']}€, {$status}");
            }
        } else {
            $this->logApiError($api, "  ");
        }

        $results = SearchService::deduplicateResults($results);

        $this->log("=== RESULTS SUMMARY ===");
        $this->log("Total results found: " . count($results));

        $earlyBookingDiscounts = SearchService::getEarlyBookingDiscounts($hotelId, $checkIn, $checkOut);

        return [
            'results'                => $results,
            'all_room_results'       => [],
            'is_multi_room'          => false,
            'multi_room_total_options' => 0,
            'no_availability'        => empty($results),
            'max_room_capacity'      => $this->calculateMaxCapacity($results),
            'early_booking_discounts' => $earlyBookingDiscounts,
            'early_booking_range'    => SearchService::getDiscountRange($earlyBookingDiscounts),
        ];
    }

    // =====================================================================
    // Hotel-info extraction helpers
    // =====================================================================

    private function extractRooms(\SimpleXMLElement $hotelInfo): array
    {
        return $hotelInfo->xpath('//rooms') ?: [];
    }

    private function extractBoardTypes(\SimpleXMLElement $hotelInfo, string $mealPlan): array
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
            $boardMapping    = Constants::BOARD_MAPPING;
            $preferredBoards = $boardMapping[$mealPlan] ?? [$mealPlan];

            $reordered = [];
            foreach ($preferredBoards as $pb) {
                foreach ($boardTypes as $bt) {
                    if (stripos($bt, $pb) !== false || stripos($pb, $bt) !== false) {
                        $reordered[] = $bt;
                    }
                }
            }
            foreach ($boardTypes as $bt) {
                if (!in_array($bt, $reordered)) {
                    $reordered[] = $bt;
                }
            }
            $boardTypes = array_unique($reordered);
        }

        return $boardTypes;
    }

    private function extractPackages(\SimpleXMLElement $hotelInfo): array
    {
        $packages = [];
        $packageElements = $hotelInfo->xpath('//packages') ?: [];

        foreach ($packageElements as $pkg) {
            $pkgName   = (string) $pkg->PackageName;
            $pkgIdCont = (string) $pkg->IdCont;
            if (!empty($pkgName)) {
                $packages[] = ['name' => $pkgName, 'id_cont' => $pkgIdCont];
            }
        }

        if (empty($packages)) {
            $packages[] = ['name' => '', 'id_cont' => ''];
        }

        return $packages;
    }

    private function buildRoomTypeMap(array $rooms): array
    {
        $map = [];
        foreach ($rooms as $roomNode) {
            $id   = trim((string) ($roomNode->IdRoom ?? ''));
            $type = trim((string) ($roomNode->Type ?? ''));
            if (!empty($id) && !empty($type)) {
                $map[$id] = $type;
            }
        }

        if ($this->debug && !empty($map)) {
            $this->log("=== ROOM TYPE MAP ===");
            foreach ($map as $rtId => $rtType) {
                $this->log("  {$rtId}: {$rtType}");
            }
        }

        return $map;
    }

    // =====================================================================
    // Utility helpers
    // =====================================================================

    private function calculateMaxCapacity(array $results): array
    {
        $maxAdults   = 0;
        $maxChildren = 0;

        foreach ($results as $result) {
            if (preg_match('/(\d+)\+(\d+)/', $result['room_id'], $m)) {
                $maxAdults   = max($maxAdults, (int) $m[1]);
                $maxChildren = max($maxChildren, (int) $m[2]);
            }
        }

        if ($maxAdults === 0) {
            $maxAdults   = 2;
            $maxChildren = 2;
        }

        return [
            'adults'   => $maxAdults,
            'children' => $maxChildren,
            'total'    => $maxAdults + $maxChildren,
        ];
    }

    private function cleanChildrenAges(array $raw): array
    {
        $clean = [];
        foreach ($raw as $age) {
            if ($age !== null && $age !== '' && $age !== 'age_needed') {
                $clean[] = (int) $age;
            }
        }
        return $clean;
    }

    private function emptyResult(): array
    {
        return [
            'results'                  => [],
            'all_room_results'         => [],
            'is_multi_room'            => false,
            'multi_room_total_options' => 0,
            'no_availability'          => true,
            'max_room_capacity'        => ['adults' => 2, 'children' => 2, 'total' => 4],
            'early_booking_discounts'  => [],
            'early_booking_range'      => [],
        ];
    }

    // =====================================================================
    // Debug logging
    // =====================================================================

    private function log(string $message): void
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
    }

    private function logCircuitStatus(): void
    {
        if (!$this->debug) {
            return;
        }
        $api = fn_novoton_holidays_get_api();
        if ($api && method_exists($api, 'getCircuitStatus')) {
            $cs = $api->getCircuitStatus();
            $this->log("=== API CIRCUIT BREAKER STATUS ===");
            $this->log("Circuit Open: " . ($cs['is_open'] ? 'YES (BLOCKING REQUESTS!)' : 'NO'));
            $this->log("Failure Count: " . $cs['failure_count'] . "/" . $cs['threshold']);
            if ($cs['last_failure']) {
                $this->log("Last Failure: " . $cs['last_failure']);
            }
            if ($cs['is_open']) {
                $this->log("Seconds Until Retry: " . $cs['seconds_until_retry']);
            }
        }
    }

    private function logHotelInfo(?\SimpleXMLElement $hotelInfo): void
    {
        if (!$this->debug) {
            return;
        }
        $this->log("=== HOTEL INFO RESPONSE (hotelinfo) ===");
        if ($hotelInfo) {
            $this->log("Has rooms: " . (isset($hotelInfo->rooms) ? 'YES' : 'NO'));
            $this->log("Has board: " . (isset($hotelInfo->board) ? 'YES' : 'NO'));
            $this->log("Has packages: " . (isset($hotelInfo->packages) ? 'YES' : 'NO'));
            if ($hotelInfo instanceof \SimpleXMLElement) {
                $rawXml = $hotelInfo->asXML();
                $this->log("=== RAW HOTELINFO XML (truncated) ===");
                $this->log(substr(htmlspecialchars($rawXml), 0, 2000));
            }
        } else {
            $this->log("ERROR: No hotel info returned from API");
        }
    }

    private function logApiError($api, string $prefix = ''): void
    {
        if (!$this->debug) {
            return;
        }
        $this->log($prefix . "API Response: EMPTY or FALSE");
        if ($api) {
            $lastError = $api->getLastError();
            if ($lastError) {
                $this->log($prefix . "API Error: " . $lastError);
            }
            if (method_exists($api, 'getCircuitStatus')) {
                $cs = $api->getCircuitStatus();
                if ($cs['is_open']) {
                    $this->log($prefix . "CIRCUIT BREAKER IS OPEN!");
                }
            }
        }
    }

    private function logSingleRoomDebug($api, string $hotelId, array $priceParams): void
    {
        if (!$this->debug || !$api) {
            return;
        }

        $lastReq = $api->getLastRequestFormatted();
        $this->log("  -> API Request Params: hotel_id={$hotelId}, check_in="
            . ($lastReq['check_in'] ?? '') . ", check_out=" . ($lastReq['check_out'] ?? '')
            . ", adults=" . ($priceParams['adults'] ?? 2));
        $this->log("  -> Children ages: " . json_encode($priceParams['children'] ?? []));

        $fullRequest = $api->getLastRequest();
        if ($fullRequest) {
            $masked = preg_replace('/<psw>[^<]*<\/psw>/', '<psw>***</psw>', $fullRequest);
            $this->log("  -> Full XML Request: " . substr(htmlspecialchars($masked), 0, 1500));
        }

        $rawResponse = $api->getLastResponse();
        if ($rawResponse) {
            $this->log("  -> Raw Response (first 2000 chars): " . substr(htmlspecialchars($rawResponse), 0, 2000));
        } else {
            $this->logApiError($api, "  -> ");
        }
    }
}
