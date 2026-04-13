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

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Constants;

class HotelAvailabilitySearcher implements HotelAvailabilitySearcherInterface
{
    /** @var SearchServiceInterface */
    private readonly SearchServiceInterface $searchService;

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
     * @param array<string, mixed> $params Normalized params from SearchParameterNormalizer
     * @return array{
     *   results: list<array<string, mixed>>,
     *   all_room_results: array<int, list<array<string, mixed>>>,
     *   is_multi_room: bool,
     *   multi_room_total_options: int,
     *   no_availability: bool,
     *   max_room_capacity: array<string, int>,
     *   early_booking_discounts: list<array<string, mixed>>,
     *   early_booking_range: array<string, mixed>
     * }
     */
    #[\Override]
    public function search(array $params): array
    {
        $hotelId  = PriceInfoFormatter::toScalar($params['hotel_id']);
        $checkIn  = PriceInfoFormatter::toScalar($params['check_in']);
        $checkOut = PriceInfoFormatter::toScalar($params['check_out']);
        $nights   = PriceInfoFormatter::toInt($params['nights']);
        $adults   = PriceInfoFormatter::toInt($params['adults']);
        /** @var list<int> $children */
        $children = is_array($params['children']) ? $params['children'] : [];
        $mealPlan = PriceInfoFormatter::toScalar($params['meal_plan']);
        $numRooms = PriceInfoFormatter::toInt($params['num_rooms']);
        /** @var array<string, mixed> $roomsData */
        $roomsData = is_array($params['rooms_data']) ? $params['rooms_data'] : [];

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
        // Typed as the narrow kit interface so this method can only
        // reach the five domain sub-clients (+ debugInfo) and never
        // falls back to the deprecated NovotonApi facade methods.
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
    #[\Override]
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * @return \SimpleXMLElement[]  The rooms XML nodes (for alternative search)
     */
    #[\Override]
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
    #[\Override]
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

    /**
     * @param array<string, mixed> $roomsData
     * @param array<string, mixed> $roomTypeMap
     * @param list<\SimpleXMLElement> $rooms
     * @return array{results: list<array<string, mixed>>, all_room_results: array<int, list<array<string, mixed>>>, is_multi_room: bool, multi_room_total_options: int, no_availability: bool, max_room_capacity: array<string, int>, early_booking_discounts: list<array<string, mixed>>, early_booking_range: array<string, mixed>}
     */
    private function searchMultiRoom(
        NovotonApiKitInterface $api,
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
        $this->log("Sending " . count($roomsData) . " room requests in parallel via curl_multi");

        // Build all room requests upfront for batch execution
        $batchRequests = [];
        $roomMeta      = []; // roomKey => occupancy metadata

        foreach ($roomsData as $roomIdx => $roomOccupancy) {
            $roomNum       = PriceInfoFormatter::toInt($roomIdx) + 1;
            /** @var array<string, mixed> $roomOccupancy */
            $roomOccupancy = is_array($roomOccupancy) ? $roomOccupancy : [];
            $roomAdults    = PriceInfoFormatter::toInt($roomOccupancy['adults'] ?? 2);
            $roomChildrenCount = PriceInfoFormatter::toInt($roomOccupancy['children'] ?? 0);
            /** @var list<mixed> $rawChildrenAges */
            $rawChildrenAges = is_array($roomOccupancy['childrenAges'] ?? null) ? $roomOccupancy['childrenAges'] : [];
            $roomChildrenAges = $this->cleanChildrenAges($rawChildrenAges);

            $this->log("--- Room #{$roomNum}: {$roomAdults} adults, {$roomChildrenCount} children ---");
            if (!empty($roomChildrenAges)) {
                $this->log("Children ages: " . implode(', ', $roomChildrenAges));
            }

            $roomKey = "room_{$roomNum}";
            $batchRequests[$roomKey] = [
                'hotel_id'    => $hotelId,
                'room_id'     => '',
                'board_id'    => '',
                'star_rating' => '',
                'check_in'    => $checkIn,
                'check_out'   => $checkOut,
                'adults'      => $roomAdults,
                'children'    => $roomChildrenAges,
            ];
            $roomMeta[$roomKey] = [
                'roomNum' => $roomNum,
                'occupancy' => "{$roomAdults} adults"
                    . ($roomChildrenCount > 0 ? ", {$roomChildrenCount} children" : ''),
            ];
        }

        // Execute ALL room requests in parallel via curl_multi
        $batchResponses = $api->pricing()->getRoomPriceBatch($batchRequests, count($batchRequests));

        // Process batch results
        $allRoomResults = [];
        foreach ($batchResponses as $roomKey => $response) {
            $meta       = $roomMeta[$roomKey] ?? null;
            if (!$meta) {
                continue;
            }
            $roomNum      = $meta['roomNum'];
            $occupancyStr = $meta['occupancy'];
            $roomResults  = [];

            $priceData = $response['data'];
            $rawXml    = $response['rawXml'];

            if ($priceData && !empty($rawXml)) {
                $this->log("  Room #{$roomNum}: API response received (parsing...)");
                $roomResults = $this->searchService->parseRoomPriceResponse(
                    $rawXml, $nights, $checkIn, $checkOut,
                    $mealPlan, [], $roomTypeMap, $roomNum, $occupancyStr
                );
            } else {
                $this->log("  Room #{$roomNum}: No response or empty data");
            }

            $allRoomResults[$roomNum] = $roomResults;
            $this->log("  Found " . count($roomResults) . " options for Room #{$roomNum}");
        }

        // Ensure all room numbers are present (in order)
        ksort($allRoomResults);

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

    /**
     * @param list<int> $children
     * @param array<string, mixed> $roomsData
     * @param array<string, mixed> $roomTypeMap
     * @return array{results: list<array<string, mixed>>, all_room_results: array<int, list<array<string, mixed>>>, is_multi_room: bool, multi_room_total_options: int, no_availability: bool, max_room_capacity: array<string, int>, early_booking_discounts: list<array<string, mixed>>, early_booking_range: array<string, mixed>}
     */
    private function searchSingleRoom(
        NovotonApiKitInterface $api,
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
        $firstRoom = reset($roomsData);
        if (empty($singleRoomChildren) && is_array($firstRoom) && !empty($firstRoom['childrenAges'])) {
            /** @var list<mixed> $rawAges0 */
            $rawAges0 = is_array($firstRoom['childrenAges']) ? $firstRoom['childrenAges'] : [];
            $singleRoomChildren = $this->cleanChildrenAges($rawAges0);
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

        $pricing = $api->pricing();
        $priceData = $pricing->getRoomPrice($priceParams);

        $this->logSingleRoomDebug($api, $hotelId, $priceParams);

        $results = [];
        if ($priceData) {
            $rawXml = $api->debugInfo()->lastResponse;
            $this->log("=== PARSING ROOM_PRICE RESPONSE ===");

            // Fetch room quota for all rooms
            $quotaMap = [];
            try {
                $quotaMap = $api->availability()->getHotelQuotaAll($hotelId, $checkIn, $checkOut);
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
                    : ($r['rooms_available'] !== null ? PriceInfoFormatter::toScalar($r['rooms_available']) . ' rooms' : 'available');
                $rRoomId  = PriceInfoFormatter::toScalar($r['room_id']);
                $rBoardId = PriceInfoFormatter::toScalar($r['board_id']);
                $rPrice   = PriceInfoFormatter::toScalar($r['total_price']);
                $this->log("  -> ADDED: Room={$rRoomId}, Board={$rBoardId}, Price={$rPrice}€, {$status}");
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

    /** @return list<\SimpleXMLElement> */
    private function extractRooms(\SimpleXMLElement $hotelInfo): array
    {
        /** @var list<\SimpleXMLElement> */
        return array_values($hotelInfo->xpath('//rooms') ?: []);
    }

    /** @return list<string> */
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
                    if (str_contains(strtolower($bt), strtolower($pb)) || str_contains(strtolower($pb), strtolower($bt))) {
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

        return array_values($boardTypes);
    }

    /** @return list<array{name: string, id_cont: string}> */
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

    /**
     * @return array<string, mixed>
     * @param list<\SimpleXMLElement> $rooms
     */
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

    /**
     * @return array<string, int>
     * @param list<array<string, mixed>> $results
     */
    private function calculateMaxCapacity(array $results): array
    {
        $maxAdults   = 0;
        $maxChildren = 0;

        foreach ($results as $result) {
            $roomId = PriceInfoFormatter::toScalar($result['room_id']);
            if (preg_match('/(\d+)\+(\d+)/', $roomId, $m)) {
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

    /**
     * @param list<mixed> $raw
     * @return list<int>
     */
    private function cleanChildrenAges(array $raw): array
    {
        $clean = [];
        foreach ($raw as $age) {
            if ($age !== null && $age !== '' && $age !== 'age_needed') {
                $clean[] = PriceInfoFormatter::toInt($age);
            }
        }
        return $clean;
    }

    /**
     * @return array{
     *   results: list<array<string, mixed>>,
     *   all_room_results: array<int, list<array<string, mixed>>>,
     *   is_multi_room: bool,
     *   multi_room_total_options: int,
     *   no_availability: bool,
     *   max_room_capacity: array<string, int>,
     *   early_booking_discounts: list<array<string, mixed>>,
     *   early_booking_range: array<string, mixed>
     * }
     */
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
        if ($api) {
            $cs = $api->getCircuitStatus();
            $this->log("=== API CIRCUIT BREAKER STATUS ===");
            $this->log("Circuit Open: " . ($cs['is_open'] ? 'YES (BLOCKING REQUESTS!)' : 'NO'));
            $this->log("Failure Count: " . PriceInfoFormatter::toScalar($cs['failure_count']) . "/" . PriceInfoFormatter::toScalar($cs['threshold']));
            if ($cs['last_failure']) {
                $this->log("Last Failure: " . PriceInfoFormatter::toScalar($cs['last_failure']));
            }
            if ($cs['is_open']) {
                $this->log("Seconds Until Retry: " . PriceInfoFormatter::toScalar($cs['seconds_until_retry']));
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
            $rawXml = (string) $hotelInfo->asXML();
            $this->log("=== RAW HOTELINFO XML (truncated) ===");
            $this->log(substr(htmlspecialchars($rawXml), 0, 2000));
        } else {
            $this->log("ERROR: No hotel info returned from API");
        }
    }

    private function logApiError(NovotonApiKitInterface $api, string $prefix = ''): void
    {
        if (!$this->debug) {
            return;
        }
        $this->log($prefix . "API Response: EMPTY or FALSE");
        $lastError = $api->debugInfo()->lastError;
        if ($lastError !== '') {
            $this->log($prefix . "API Error: " . $lastError);
        }
        // Circuit-breaker state isn't on the narrow kit interface — re-query
        // the concrete facade singleton for diagnostic output only.
        $concrete = fn_novoton_holidays_get_api();
        if ($concrete) {
            $cs = $concrete->getCircuitStatus();
            if ($cs['is_open']) {
                $this->log($prefix . "CIRCUIT BREAKER IS OPEN!");
            }
        }
    }

    /**
     * @param array<string, mixed> $priceParams
     */
    private function logSingleRoomDebug(NovotonApiKitInterface $api, string $hotelId, array $priceParams): void
    {
        if (!$this->debug) {
            return;
        }

        $debugInfo = $api->debugInfo();
        $lastReq = $debugInfo->lastRequestFormatted;
        $this->log("  -> API Request Params: hotel_id={$hotelId}, check_in="
            . PriceInfoFormatter::toScalar($lastReq['check_in'] ?? '') . ", check_out=" . PriceInfoFormatter::toScalar($lastReq['check_out'] ?? '')
            . ", adults=" . PriceInfoFormatter::toScalar($priceParams['adults'] ?? 2));
        $this->log("  -> Children ages: " . json_encode($priceParams['children'] ?? []));

        $fullRequest = $debugInfo->lastRequest;
        if ($fullRequest !== '') {
            $masked = (string) preg_replace('/<psw>[^<]*<\/psw>/', '<psw>***</psw>', $fullRequest);
            $this->log("  -> Full XML Request: " . substr(htmlspecialchars($masked), 0, 1500));
        }

        $rawResponse = $debugInfo->lastResponse;
        if ($rawResponse !== '') {
            $this->log("  -> Raw Response (first 2000 chars): " . substr(htmlspecialchars($rawResponse), 0, 2000));
        } else {
            $this->logApiError($api, "  -> ");
        }
    }
}