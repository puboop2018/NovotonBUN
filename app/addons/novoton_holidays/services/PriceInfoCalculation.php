<?php
/**
 * Novoton PriceInfo Calculation Service
 *
 * Calculates prices from priceinfo data to match room_price API response.
 *
 * Formula:
 * Price = BasePrice - EB* + extras_single + (extras_daily - EB|reduction**) + (extras_rooms - EB|reduction**)
 *         + (extras_board - EB|reduction**) - reduction* - reduction_perc_additional + company_fee
 *
 * Priority Rules:
 * * If Priority='Yes', EB and reduction are NOT combinable
 *   - PriorityEB='Yes': Early Booking has priority
 *   - PriorityEXT='Yes': Extras/Reduction has priority
 *   - If neither, pick the one with better savings
 *
 * ** EB/EXT application to supplements (check flags):
 *   - EBToDaily='Yes': Apply EB discount to extras_daily
 *   - EBToRooms='Yes': Apply EB discount to extras_rooms
 *   - EBToBoard='Yes': Apply EB discount to extras_board
 *   - EXTToDaily='Yes': Apply EXT reduction to extras_daily
 *   - EXTToRooms='Yes': Apply EXT reduction to extras_rooms
 *   - EXTToBoard='Yes': Apply EXT reduction to extras_board
 *
 * extras_daily Type handling:
 *   - 'Stay': Charged for each day in FromDate-ToDate range (per overlapping night)
 *   - 'Arrival': If check-in is within period, charge applies to whole stay (all nights)
 *   - 'Day'/'Night' (default): Charged per overlapping night
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class PriceInfoCalculation
{
    /** @var array Priceinfo data */
    private $priceinfo;

    /** @var array Hotel info (rooms with RB/EB/maxADT/maxCHD/minPAX) */
    private $hotelinfo;

    /** @var float Commission percentage */
    private $commission;

    /** @var bool Debug mode */
    private $debug = false;

    /** @var array Debug log */
    private $debugLog = [];

    /** @var array Code index for Code/Base resolution: code_number => [rows] */
    private $codeIndex = [];

    /** @var string IdStar for season_price matching */
    private $idStar = '4*';

    /** @var array Hotel-specific child age bands parsed from hotelinfo ages, sorted by from_year */
    private $childAgeBands = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->commission = ConfigService::getCommission();
        $this->debug = ConfigService::isDebugLogging();
    }

    /**
     * Calculate price for a booking
     *
     * @param array $params Calculation parameters:
     *   - hotel_id: Hotel ID
     *   - package_name: Package name
     *   - check_in: Check-in date (Y-m-d)
     *   - nights: Number of nights
     *   - room_id: Room type ID
     *   - board_id: Board type ID
     *   - adults: Number of adults
     *   - children_ages: Array of children ages (e.g., [1.5, 7])
     *   - booking_date: Date of booking for EB check (default: today)
     * @return array Calculation result with price breakdowns
     */
    public function calculate(array $params): array
    {
        $this->debugLog = [];
        $this->log('=== PRICE CALCULATION START ===');
        $this->log('Params', $params);

        // Load data
        $hotelId = $params['hotel_id'] ?? '';
        $packageName = $params['package_name'] ?? '';

        if (empty($hotelId) || empty($packageName)) {
            return $this->errorResult('Missing hotel_id or package_name');
        }

        // Load priceinfo from database
        $this->priceinfo = $this->loadPriceInfo($hotelId, $packageName);
        if (empty($this->priceinfo)) {
            return $this->errorResult('Priceinfo not found for hotel/package');
        }

        // Build code index for Code/Base resolution (Code is numeric ID, Base references another Code)
        $this->buildCodeIndex();

        // Load hotelinfo for room capacities
        $this->hotelinfo = $this->loadHotelInfo($hotelId);

        // Parse hotel-specific child age bands from hotelinfo
        $this->parseChildAgeBands();

        // Extract parameters
        $checkIn = $params['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
        $nights = intval($params['nights'] ?? 7);
        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';
        $adults = intval($params['adults'] ?? 2);
        $childrenAges = $params['children_ages'] ?? [];
        $bookingDate = $params['booking_date'] ?? date('Y-m-d');
        $this->idStar = $params['id_star'] ?? '4*';

        // Ensure children_ages is array
        if (!is_array($childrenAges)) {
            $childrenAges = !empty($childrenAges) ? explode(',', $childrenAges) : [];
        }
        $childrenAges = array_map('floatval', $childrenAges);
        $numChildren = count($childrenAges);

        $checkOut = date('Y-m-d', strtotime($checkIn . ' + ' . $nights . ' days'));

        $this->log('Parsed input', [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'room_id' => $roomId,
            'board_id' => $boardId,
            'adults' => $adults,
            'children_ages' => $childrenAges,
            'booking_date' => $bookingDate
        ]);

        // Step 1: Validate occupancy against room capacity
        $roomCapacity = $this->getRoomCapacity($roomId);
        $this->log('Room capacity', $roomCapacity);

        $occupancyValid = $this->validateOccupancy($adults, $numChildren, $roomCapacity);
        if (!$occupancyValid['valid']) {
            return $this->errorResult('Invalid occupancy: ' . $occupancyValid['reason']);
        }

        // Step 2: Build occupancy structure (who uses RB vs EB)
        // Also checks if each child's age band has pricing in this room;
        // children without matching child rates are reclassified as additional adults.
        $occupancy = $this->buildOccupancyStructure($adults, $childrenAges, $roomCapacity, $roomId, $boardId);
        $this->log('Occupancy structure', $occupancy);

        // Step 3: Get season mapping for each night
        $seasonsByNight = $this->getSeasonsByNight($checkIn, $nights);
        $this->log('Seasons by night', $seasonsByNight);

        // Step 4: Calculate base price
        $basePrice = $this->calculateBasePrice($occupancy, $seasonsByNight, $roomId, $boardId, $nights);
        $this->log('Base price', $basePrice);

        // Step 5: Calculate fees
        $fees = $this->calculateFees($occupancy, $checkIn, $nights, $roomId, $boardId);
        $this->log('Fees', $fees);

        // Step 6: Get Early Booking discount (now includes extras based on EBToDaily/EBToRooms/EBToBoard)
        $ebDiscount = $this->calculateEarlyBookingDiscount($bookingDate, $checkIn, $nights, $basePrice, $fees);
        $this->log('Early Booking discount', $ebDiscount);

        // Step 7: Get Reduction (free nights) - pass basePrice and fees for accurate multi-season calculation
        // Now includes extras based on EXTToDaily/EXTToRooms/EXTToBoard
        $reduction = $this->calculateReduction($checkIn, $nights, $seasonsByNight, $occupancy, $roomId, $boardId, $basePrice, $fees);
        $this->log('Reduction', $reduction);

        // Step 8: Apply Priority rules and pick best scenario
        $finalPrice = $this->applyPriorityRules($basePrice, $fees, $ebDiscount, $reduction);
        $this->log('Final price calculation', $finalPrice);

        // Step 9: Apply commission
        $priceWithCommission = $this->applyCommission($finalPrice['total']);

        $result = [
            'success' => true,
            'price' => round($priceWithCommission, 2),
            'price_without_commission' => round($finalPrice['total'], 2),
            'commission' => $this->commission,
            'breakdown' => [
                'base_price' => round($basePrice['total'], 2),
                'base_per_night' => $basePrice['by_night'] ?? [],
                'base_per_person' => $basePrice['by_person'] ?? [],
                'fees' => [
                    'extras_daily' => round($fees['extras_daily'] ?? 0, 2),
                    'extras_single' => round($fees['extras_single'] ?? 0, 2),
                    'extras_rooms' => round($fees['extras_rooms'] ?? 0, 2),
                    'extras_board' => round($fees['extras_board'] ?? 0, 2),
                    'handling_fee' => round($fees['handling_fee'] ?? 0, 2),
                    'company_fee' => round($fees['company_fee'] ?? 0, 2),
                    'total_fees' => round($fees['total'] ?? 0, 2)
                ],
                'fees_detail' => $fees,
                'discounts' => [
                    'early_booking' => $ebDiscount,
                    'reduction' => $reduction
                ],
                'priority_rules' => [
                    'priority' => $this->priceinfo['Priority'] ?? 'No',
                    'priority_eb' => $this->priceinfo['PriorityEB'] ?? 'No',
                    'priority_ext' => $this->priceinfo['PriorityEXT'] ?? 'No',
                    'scenarios' => $finalPrice['scenarios'] ?? []
                ],
                'applied_discount' => $finalPrice['applied_discount'],
                'discount_amount' => round($finalPrice['discount_amount'] ?? 0, 2)
            ],
            'room_capacity' => $roomCapacity,
            'child_age_bands' => $this->childAgeBands,
            'seasons_by_night' => $seasonsByNight,
            'occupancy' => $occupancy,
            'params' => $params,
            'debug_log' => $this->debug ? $this->debugLog : null
        ];

        $this->log('=== PRICE CALCULATION END ===', $result);

        return $result;
    }

    /**
     * Load priceinfo data from database
     */
    private function loadPriceInfo(string $hotelId, string $packageName): ?array
    {
        $json = db_get_field(
            "SELECT priceinfo_data FROM ?:novoton_hotel_packages
             WHERE hotel_id = ?s AND package_name = ?s",
            $hotelId,
            $packageName
        );

        if (empty($json)) {
            return null;
        }

        return json_decode($json, true);
    }

    /**
     * Load hotel info for room capacities
     */
    private function loadHotelInfo(string $hotelId): ?array
    {
        $json = db_get_field(
            "SELECT hotel_data FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotelId
        );

        if (empty($json)) {
            return null;
        }

        return json_decode($json, true);
    }

    /**
     * Parse child age bands from hotelinfo ages data.
     *
     * Each hotel defines its own age categories with FromYear/ToYear ranges.
     * This method extracts the child categories (fAge=1) and builds a sorted
     * list of age bands for use in getAgeBand().
     *
     * The band label is formatted to match what appears in season_price IdAge/fAge
     * fields (e.g., "0-1,99", "2-11,99", "12-17,99").
     *
     * Falls back to standard bands if hotelinfo has no age data.
     */
    private function parseChildAgeBands(): void
    {
        $this->childAgeBands = [];

        if (empty($this->hotelinfo)) {
            return;
        }

        $ages = $this->hotelinfo['ages'] ?? [];

        // Handle nested structure: ages.age
        if (isset($ages['age'])) {
            $ages = $ages['age'];
        }

        // Normalize single entry to array
        if (isset($ages['IdAge'])) {
            $ages = [$ages];
        }

        if (empty($ages) || !is_array($ages)) {
            return;
        }

        foreach ($ages as $age) {
            // fAge=1 means child, fAge=0 means adult
            $isChild = ($age['fAge'] ?? '0') === '1' || ($age['fAge'] ?? 0) === 1;
            if (!$isChild) {
                continue;
            }

            $fromYear = floatval($age['FromYear'] ?? 0);
            $toYear = floatval($age['ToYear'] ?? 0);
            if ($toYear <= 0) {
                continue;
            }

            // Build the band label in the format used by season_price data
            // Convention: use comma as decimal separator (e.g., "2-11,99")
            $label = $this->formatAgeBandLabel($fromYear, $toYear);

            $this->childAgeBands[] = [
                'from' => $fromYear,
                'to' => $toYear,
                'label' => $label,
                'id_age' => $age['IdAge'] ?? ''
            ];
        }

        // Sort by from_year ascending
        usort($this->childAgeBands, function ($a, $b) {
            return $a['from'] <=> $b['from'];
        });

        $this->log('Parsed hotel child age bands', $this->childAgeBands);
    }

    /**
     * Format age band label to match season_price conventions.
     *
     * Produces labels like "0-1,99", "2-11,99", "12-17,99"
     * using comma as decimal separator (matching API data format).
     */
    private function formatAgeBandLabel(float $from, float $to): string
    {
        // Integer part: no decimals (e.g., 0, 2, 12)
        $fromStr = ($from == floor($from)) ? (string)intval($from) : str_replace('.', ',', rtrim(rtrim(number_format($from, 2, ',', ''), '0'), ','));
        // ToYear usually has decimals (e.g., 1.99, 11.99)
        $toStr = ($to == floor($to)) ? (string)intval($to) : str_replace('.', ',', rtrim(rtrim(number_format($to, 2, ',', ''), '0'), ','));

        return $fromStr . '-' . $toStr;
    }

    /**
     * Build code index for Code/Base resolution
     *
     * In the API, Code is a numeric ID and Base references another Code's ID.
     * This allows recursive price resolution (e.g., child price = 80% of Code 1's price).
     */
    private function buildCodeIndex(): void
    {
        $this->codeIndex = [];
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            $code = $this->toScalar($row['Code'] ?? '');
            if ($code === '') continue;
            if (!isset($this->codeIndex[$code])) {
                $this->codeIndex[$code] = [];
            }
            $this->codeIndex[$code][] = $row;
        }
    }

    /**
     * Safely extract a scalar string value from a field that may be array/object
     * (SimpleXML json_encode of empty elements produces [])
     */
    private function toScalar($value): string
    {
        if (is_array($value) || is_object($value)) return '';
        return trim((string)$value);
    }

    /**
     * Normalize IdAge for fee matching: strip trailing "BY X AD" suffix
     * Fees (extras_daily, handling_fee) often use IdAge without the "BY X AD" part.
     */
    private function feeKey(string $idAge): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $idAge));
        $s = preg_replace('/\s+BY\s+\d+\s+AD\s*$/i', '', $s);
        return trim($s);
    }

    /**
     * Get room capacity (RB, EB, maxADT, maxCHD, minPAX)
     */
    private function getRoomCapacity(string $roomId): array
    {
        $default = [
            'RB' => 2,      // Regular beds
            'EB' => 1,      // Extra beds
            'maxADT' => 2,  // Max adults
            'maxCHD' => 2,  // Max children
            'minPAX' => 1   // Min persons
        ];

        if (empty($this->hotelinfo) || !isset($this->hotelinfo['rooms'])) {
            return $default;
        }

        $rooms = $this->hotelinfo['rooms'];
        // Normalize single room
        if (isset($rooms['IdRoom'])) {
            $rooms = [$rooms];
        }

        foreach ($rooms as $room) {
            $rid = $room['IdRoom'] ?? '';
            if ($rid === $roomId || rawurldecode($rid) === $roomId) {
                return [
                    'RB' => intval($room['RegularBeds'] ?? $room['RB'] ?? 2),
                    'EB' => intval($room['ExtraBeds'] ?? $room['EB'] ?? 1),
                    'maxADT' => intval($room['maxADT'] ?? $room['MaxAdults'] ?? 2),
                    'maxCHD' => intval($room['maxCHD'] ?? $room['MaxChildren'] ?? 2),
                    'minPAX' => intval($room['minPAX'] ?? $room['MinPax'] ?? 1)
                ];
            }
        }

        return $default;
    }

    /**
     * Validate occupancy against room capacity
     */
    private function validateOccupancy(int $adults, int $children, array $capacity): array
    {
        if ($adults < $capacity['minPAX']) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) less than minPAX ({$capacity['minPAX']})"];
        }

        if ($adults > $capacity['maxADT']) {
            return ['valid' => false, 'reason' => "Adults ({$adults}) exceeds maxADT ({$capacity['maxADT']})"];
        }

        if ($children > $capacity['maxCHD']) {
            return ['valid' => false, 'reason' => "Children ({$children}) exceeds maxCHD ({$capacity['maxCHD']})"];
        }

        $totalPax = $adults + $children;
        $totalCapacity = $capacity['RB'] + $capacity['EB'];
        if ($totalPax > $totalCapacity) {
            return ['valid' => false, 'reason' => "Total pax ({$totalPax}) exceeds capacity ({$totalCapacity})"];
        }

        return ['valid' => true];
    }

    /**
     * Build occupancy structure - who uses Regular Beds vs Extra Beds
     *
     * Adults take REGULAR beds first, extras become "3RD ADULT", "4TH ADULT"
     * Children fill remaining REGULAR beds, then go to EXTRA BED.
     *
     * If a child's age band (e.g., 12-17,99) has no matching price row in this room,
     * the child is reclassified as an additional adult on EXTRA BED. This handles
     * hotels that only define 0-1,99 and 2-11,99 child bands — older children (12+)
     * are effectively charged at adult rates.
     */
    private function buildOccupancyStructure(int $adults, array $childrenAges, array $capacity, string $roomId = '', string $boardId = ''): array
    {
        $occupancy = [
            'adults' => [],
            'children' => [],
            'children_as_adults' => [],  // Children reclassified as adults (age band not in room)
            'total_rb_used' => 0,
            'total_eb_used' => 0
        ];

        $rbAvailable = $capacity['RB'];
        $ebAvailable = $capacity['EB'];
        $rbUsed = 0;
        $ebUsed = 0;

        // Get available child age bands for this room (if room/board specified)
        $availableBands = [];
        if (!empty($roomId) && !empty($boardId) && !empty($this->priceinfo)) {
            $availableBands = $this->getAvailableChildAgeBands($roomId, $boardId);
            $this->log('Available child age bands for room', [
                'room_id' => $roomId,
                'board_id' => $boardId,
                'bands' => $availableBands
            ]);
        }

        // Place adults
        $adultCount = $adults;
        for ($i = 0; $i < $adults; $i++) {
            if ($rbUsed < $rbAvailable) {
                // Regular bed
                $occupancy['adults'][] = [
                    'index' => $i + 1,
                    'bed_type' => 'REGULAR',
                    'age_type' => 'ADULT ',  // Note: trailing space matches API
                    'acc_type' => 'REGULAR'
                ];
                $rbUsed++;
            } else {
                // Extra bed - becomes "3 RD ADULT", "4 TH ADULT", etc.
                $ordinal = $this->getOrdinal($i + 1);
                $occupancy['adults'][] = [
                    'index' => $i + 1,
                    'bed_type' => 'EXTRA BED',
                    'age_type' => $ordinal . ' ADULT',
                    'acc_type' => 'EXTRA BED'
                ];
                $ebUsed++;
            }
        }

        // Place children — check if each child's age band has pricing in this room
        $childOrdinalCounter = 0;
        foreach ($childrenAges as $idx => $age) {
            $ageBand = $this->getAgeBand($age);

            // Check if this age band has pricing in the room
            $bandHasPricing = true;
            if (!empty($availableBands)) {
                // Normalize for comparison (comma vs dot)
                $bandNorm = str_replace(',', '.', $ageBand);
                $hasBand = false;
                foreach ($availableBands as $ab) {
                    if (str_replace(',', '.', $ab) === $bandNorm) {
                        $hasBand = true;
                        break;
                    }
                }
                $bandHasPricing = $hasBand;
            }

            if (!$bandHasPricing) {
                // No child rate for this age band — reclassify as additional adult
                $adultCount++;
                $ordinal = $this->getOrdinal($adultCount);

                $this->log('Child reclassified as adult (no pricing for age band)', [
                    'child_age' => $age,
                    'age_band' => $ageBand,
                    'reclassified_as' => $ordinal . ' ADULT'
                ]);

                if ($rbUsed < $rbAvailable) {
                    $entry = [
                        'index' => $adultCount,
                        'bed_type' => 'REGULAR',
                        'age_type' => 'ADULT ',
                        'acc_type' => 'REGULAR',
                        'original_child_age' => $age,
                        'reclassified' => true
                    ];
                    $rbUsed++;
                } else {
                    $entry = [
                        'index' => $adultCount,
                        'bed_type' => 'EXTRA BED',
                        'age_type' => $ordinal . ' ADULT',
                        'acc_type' => 'EXTRA BED',
                        'original_child_age' => $age,
                        'reclassified' => true
                    ];
                    $ebUsed++;
                }

                $occupancy['adults'][] = $entry;
                $occupancy['children_as_adults'][] = $entry;
                continue;
            }

            // Normal child placement
            $childOrdinalCounter++;
            $ordinal = $this->getChildOrdinal($childOrdinalCounter);

            if ($rbUsed < $rbAvailable) {
                // Regular bed
                $occupancy['children'][] = [
                    'index' => $childOrdinalCounter,
                    'age' => $age,
                    'age_band' => $ageBand,
                    'bed_type' => 'REGULAR',
                    'age_type' => $ordinal . ' CHD ' . $ageBand,
                    'acc_type' => 'REGULAR',
                    'by_1_ad' => ($adults == 1)  // Flag for "BY 1 AD" rate lookup
                ];
                $rbUsed++;
            } else {
                // Extra bed
                $occupancy['children'][] = [
                    'index' => $childOrdinalCounter,
                    'age' => $age,
                    'age_band' => $ageBand,
                    'bed_type' => 'EXTRA BED',
                    'age_type' => $ordinal . ' CHD ' . $ageBand,
                    'acc_type' => 'EXTRA BED',
                    'by_1_ad' => ($adults == 1)
                ];
                $ebUsed++;
            }
        }

        $occupancy['total_rb_used'] = $rbUsed;
        $occupancy['total_eb_used'] = $ebUsed;

        return $occupancy;
    }

    /**
     * Get ordinal for adult (3 RD, 4 TH, etc.)
     */
    private function getOrdinal(int $num): string
    {
        $ordinals = [
            1 => '1 ST',
            2 => '2 ND',
            3 => '3 RD',
            4 => '4 TH',
            5 => '5 TH'
        ];
        return $ordinals[$num] ?? $num . ' TH';
    }

    /**
     * Get ordinal for child (1 ST, 2 ND, etc.)
     */
    private function getChildOrdinal(int $num): string
    {
        return $this->getOrdinal($num);
    }

    /**
     * Get age band string for a child's age.
     *
     * Uses the hotel's own age definitions (parsed from hotelinfo) to find
     * the matching age band. Different hotels may define different ranges
     * (e.g., one hotel may use 0-1.99/2-13.99/14-17.99 while another uses 0-2.99/3-11.99).
     *
     * Falls back to standard bands if no hotel-specific data is available:
     * - 0-1,99: Infants (age 0 to <2)
     * - 2-11,99: Children (age 2 to <12)
     * - 12-17,99: Teens (age 12 to <18)
     */
    private function getAgeBand(float $age): string
    {
        // Use hotel-specific age bands if available
        if (!empty($this->childAgeBands)) {
            foreach ($this->childAgeBands as $band) {
                if ($age >= $band['from'] && $age <= $band['to']) {
                    return $band['label'];
                }
            }
            // Age didn't match any defined child band — return a synthetic label
            // This typically means the child is above all defined child ranges
            // and should be treated as adult (handled by buildOccupancyStructure)
            return $this->formatAgeBandLabel(floor($age), 17.99);
        }

        // Fallback to standard bands
        if ($age < 2.0) {
            return '0-1,99';
        }
        if ($age < 12.0) {
            return '2-11,99';
        }
        return '12-17,99';
    }

    /**
     * Get available child age bands for a specific room+board from season_price data.
     *
     * Scans season_price rows to find which child age bands (0-1,99 / 2-11,99 / 12-17,99)
     * have pricing entries for the given room. This is used to determine whether a child
     * of a certain age can be priced in this room, or if they need a different room.
     *
     * @param string $roomId Room ID
     * @param string $boardId Board ID
     * @return array Available age bands, e.g. ['0-1,99', '2-11,99']
     */
    private function getAvailableChildAgeBands(string $roomId, string $boardId): array
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $bands = [];
        foreach ($seasonPrices as $row) {
            $rowRoom = $this->toScalar($row['IdRoom'] ?? '');
            $rowBoard = $this->toScalar($row['IdBoard'] ?? '');

            if (!$this->matchRoom($rowRoom, $roomId)) continue;
            if (!$this->matchBoard($rowBoard, $boardId)) continue;

            // Check age type for child bands
            $rowAge = '';
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $rowAge = $row['fAge'];
            } else {
                $rawIdAge = $this->toScalar($row['IdAge'] ?? '');
                static $ageTypeMap = ['1' => 'ADULT', '2' => 'CHD 0-1.99', '3' => 'CHD 2-11.99', '4' => 'CHD 12-17.99'];
                $rowAge = $ageTypeMap[$rawIdAge] ?? $rawIdAge;
            }

            $rowAge = strtoupper(trim($rowAge));
            if (strpos($rowAge, 'CHD') === false && strpos($rowAge, 'CHILD') === false) {
                continue;
            }

            // Extract age band pattern from age type
            if (preg_match('/(\d+[\-\.]\d+[,\.]?\d*)/', $rowAge, $m)) {
                // Normalize to comma format (2-11,99)
                $band = str_replace('.', ',', $m[1]);
                // Normalize common variations
                $band = preg_replace('/(\d+)-(\d+),(\d+)/', '$1-$2,$3', $band);
                if (!in_array($band, $bands)) {
                    $bands[] = $band;
                }
            }
        }

        return $bands;
    }

    /**
     * Check if a room has adult extra bed pricing (e.g., 3RD ADULT on EXTRA BED).
     *
     * Used when a child's age band has no matching child rate — the child
     * may need to be charged as an additional adult on extra bed.
     *
     * @param string $roomId Room ID
     * @param string $boardId Board ID
     * @return bool True if 3RD+ ADULT EXTRA BED pricing exists
     */
    private function hasAdultExtraBedPricing(string $roomId, string $boardId): bool
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $row) {
            $rowRoom = $this->toScalar($row['IdRoom'] ?? '');
            $rowBoard = $this->toScalar($row['IdBoard'] ?? '');

            if (!$this->matchRoom($rowRoom, $roomId)) continue;
            if (!$this->matchBoard($rowBoard, $boardId)) continue;

            $rowAge = '';
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $rowAge = $row['fAge'];
            } else {
                $rawIdAge = $this->toScalar($row['IdAge'] ?? '');
                static $ageMap = ['1' => 'ADULT', '2' => 'CHD 0-1.99', '3' => 'CHD 2-11.99', '4' => 'CHD 12-17.99'];
                $rowAge = $ageMap[$rawIdAge] ?? $rawIdAge;
            }

            $rowAge = strtoupper(trim($rowAge));
            $rowAcc = strtoupper(trim($this->toScalar($row['IdAcc'] ?? '')));

            // Check for "3 RD ADULT" or "3RD ADULT" or similar on EXTRA BED
            if (preg_match('/\d+\s*(ST|ND|RD|TH)\s*ADULT/i', $rowAge) &&
                ($rowAcc === 'EXTRA BED' || $rowAcc === 'EB' || $rowAcc === 'EXTRABED')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get season number for each night of the stay
     */
    private function getSeasonsByNight(string $checkIn, int $nights): array
    {
        // Seasons can be in different formats:
        // 1. Array of seasons with 'Season', 'FromDate', 'ToDate'
        // 2. Nested under 'seasons' key
        $seasons = $this->priceinfo['seasons'] ?? [];

        // Handle if seasons is directly an array of season objects
        if (isset($seasons['Season'])) {
            // Single season
            $seasons = [$seasons];
        } elseif (isset($seasons[0]['Season'])) {
            // Already an array
        } elseif (isset($seasons['season'])) {
            // Nested format
            $seasons = $seasons['season'];
            if (isset($seasons['Season'])) {
                $seasons = [$seasons];
            }
        }

        $result = [];
        $checkInDate = new \DateTime($checkIn);

        for ($night = 0; $night < $nights; $night++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$night} days");
            $dateStr = $currentDate->format('Y-m-d');

            $seasonNum = 1; // Default
            foreach ($seasons as $season) {
                $from = $season['FromDate'] ?? '';
                $to = $season['ToDate'] ?? '';
                // Field can be 'Season' or 'IdSeason'
                $id = intval($season['Season'] ?? $season['IdSeason'] ?? 1);

                if ($dateStr >= $from && $dateStr <= $to) {
                    $seasonNum = $id;
                    break;
                }
            }

            $result[$night] = [
                'date' => $dateStr,
                'season' => $seasonNum
            ];
        }

        return $result;
    }

    /**
     * Calculate base price from season_price rows
     */
    private function calculateBasePrice(array $occupancy, array $seasonsByNight, string $roomId, string $boardId, int $nights): array
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $total = 0;
        $byNight = [];
        $byPerson = [];

        // Calculate for each night
        foreach ($seasonsByNight as $nightIdx => $nightInfo) {
            $nightTotal = 0;
            $seasonNum = $nightInfo['season'];
            $priceKey = 'Price' . $seasonNum;

            // Track if a RoomPrice=Yes row already charged this night (per-room = once only)
            $roomPriceCharged = false;

            // Adults
            foreach ($occupancy['adults'] as $adult) {
                $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $adult['age_type'], $adult['acc_type'], $nights);

                if ($row) {
                    $isRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';

                    // If RoomPrice=Yes, only charge once per room (skip subsequent persons)
                    if ($isRoomPrice) {
                        if ($roomPriceCharged) continue;
                        $roomPriceCharged = true;
                    }

                    $price = $this->getPriceFromRow($row, $priceKey);
                    $nightTotal += $price;

                    if (!isset($byPerson['adult_' . $adult['index']])) {
                        $byPerson['adult_' . $adult['index']] = 0;
                    }
                    $byPerson['adult_' . $adult['index']] += $price;
                }
            }

            // Children
            foreach ($occupancy['children'] as $child) {
                // Try with "BY 1 AD" first if applicable
                $row = null;
                if ($child['by_1_ad']) {
                    // Try both formats: "BY 1 AD" and "by 1 ad"
                    $ageTypeBy1Ad = $child['age_type'] . ' BY 1 AD';
                    $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $ageTypeBy1Ad, $child['acc_type'], $nights);

                    if (!$row) {
                        // Try lowercase variant
                        $ageTypeBy1AdLower = $child['age_type'] . ' by 1 ad';
                        $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $ageTypeBy1AdLower, $child['acc_type'], $nights);
                    }
                }

                // Fallback to regular rate
                if (!$row) {
                    $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $child['age_type'], $child['acc_type'], $nights);
                }

                if ($row) {
                    $childRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';

                    // If RoomPrice=Yes and already charged, skip
                    if ($childRoomPrice) {
                        if ($roomPriceCharged) continue;
                        $roomPriceCharged = true;
                    }

                    $price = $this->getPriceFromRow($row, $priceKey);
                    $nightTotal += $price;

                    if (!isset($byPerson['child_' . $child['index']])) {
                        $byPerson['child_' . $child['index']] = 0;
                    }
                    $byPerson['child_' . $child['index']] += $price;
                }
            }

            $byNight[$nightIdx] = [
                'date' => $nightInfo['date'],
                'season' => $seasonNum,
                'price' => $nightTotal
            ];

            $total += $nightTotal;
        }

        return [
            'total' => $total,
            'by_night' => $byNight,
            'by_person' => $byPerson
        ];
    }

    /**
     * Find the base code row for percentage calculations
     */
    private function findBaseCodeRow(array $seasonPrices, string $roomId, string $boardId): ?array
    {
        foreach ($seasonPrices as $row) {
            $rowRoom = is_string($row['IdRoom'] ?? '') ? ($row['IdRoom'] ?? '') : '';
            $rowBoard = is_string($row['IdBoard'] ?? '') ? ($row['IdBoard'] ?? '') : '';
            $code = is_string($row['Code'] ?? '') ? ($row['Code'] ?? '') : '';

            if ($this->matchRoom($rowRoom, $roomId) && $this->matchBoard($rowBoard, $boardId) && $code === 'Base') {
                return $row;
            }
        }
        return null;
    }

    /**
     * Find season_price row matching criteria
     *
     * Collects all matching candidates then picks the most specific one
     * (largest FromDays value = most specific stay-length match).
     */
    private function findSeasonPriceRow(array $seasonPrices, string $roomId, string $boardId, string $ageType, string $accType, int $nights): ?array
    {
        // Map numeric IdAge to descriptive age types (same as hotels.php)
        static $ageTypeMap = [
            '1' => 'ADULT',
            '2' => 'CHD 0-1.99',
            '3' => 'CHD 2-11.99',
            '4' => 'CHD 12-17.99',
        ];

        $candidates = [];

        foreach ($seasonPrices as $row) {
            $rowRoom = $this->toScalar($row['IdRoom'] ?? '');
            $rowBoard = $this->toScalar($row['IdBoard'] ?? '');
            $rowAcc = $this->toScalar($row['IdAcc'] ?? '');
            $rowStar = $this->toScalar($row['IdStar'] ?? '');

            // Get age type: prefer fAge (descriptive), fallback to mapped IdAge
            $rowAge = '';
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $rowAge = $row['fAge'];
            } else {
                $rawIdAge = $this->toScalar($row['IdAge'] ?? '');
                $rowAge = $ageTypeMap[$rawIdAge] ?? $rawIdAge;
            }

            // Default empty IdAcc to 'REGULAR' (consistent with hotels.php)
            if ($rowAcc === '') {
                $rowAcc = 'REGULAR';
            }

            // Handle FromDays/ToDays: extract digits, default 1/9999
            $rawFromDays = $this->toScalar($row['FromDays'] ?? '');
            $rawToDays = $this->toScalar($row['ToDays'] ?? '');
            $fromDays = ($rawFromDays !== '') ? intval(preg_replace('/\D+/', '', $rawFromDays) ?: '1') : 1;
            $toDays = ($rawToDays !== '') ? intval(preg_replace('/\D+/', '', $rawToDays) ?: '9999') : 9999;
            if ($fromDays <= 0) $fromDays = 1;
            if ($toDays <= 0) $toDays = 9999;

            // Match room, board, age, acc
            if (!$this->matchRoom($rowRoom, $roomId)) continue;
            if (!$this->matchBoard($rowBoard, $boardId)) continue;
            if (!$this->matchAgeType($rowAge, $ageType)) continue;
            if (!$this->matchAccType($rowAcc, $accType)) continue;

            // Match IdStar if present in both data and params
            if ($rowStar !== '' && $this->idStar !== '' && strcasecmp($rowStar, $this->idStar) !== 0) continue;

            // Validate stay length
            if ($nights < $fromDays || $nights > $toDays) continue;

            $candidates[] = ['row' => $row, 'fromDays' => $fromDays];
        }

        if (empty($candidates)) {
            return null;
        }

        // Pick most specific match (largest FromDays = most specific stay-length range)
        usort($candidates, function($a, $b) {
            return $b['fromDays'] <=> $a['fromDays'];
        });

        return $candidates[0]['row'];
    }

    /**
     * Get price from row, handling percentages via recursive Code/Base resolution
     *
     * Percentages are ONLY values with explicit % sign: "80%", "50%"
     * Numeric values are always treated as absolute prices (EUR).
     *
     * Code/Base resolution: Code is a numeric ID, Base references another Code's ID.
     * E.g., Code=2 Base=1 Price="80%" means 80% of Code 1's price.
     * Resolution is recursive (Code 3 → Code 2 → Code 1).
     */
    private function getPriceFromRow(array $row, string $priceKey, ?array $baseCodeRow = null, array $allSeasonPrices = []): float
    {
        $visited = [];
        return $this->resolvePrice($row, $priceKey, $visited);
    }

    /**
     * Recursively resolve price from a season_price row
     *
     * Handles percentage prices by looking up the Base Code in codeIndex
     * and resolving recursively (with loop protection).
     */
    private function resolvePrice(array $row, string $priceKey, array &$visited): float
    {
        $code = $this->toScalar($row['Code'] ?? '');
        $memoKey = $code . ':' . $priceKey;

        // Prevent infinite recursion
        if (isset($visited[$memoKey])) return 0;
        $visited[$memoKey] = true;

        $rawPrice = $row[$priceKey] ?? $row['Price1'] ?? 0;

        // Handle empty arrays from SimpleXML json_encode of empty elements
        if (is_array($rawPrice) || is_object($rawPrice)) {
            return 0;
        }

        // Handle explicit percentage (e.g., "80%", "50%")
        if (is_string($rawPrice) && strpos($rawPrice, '%') !== false) {
            $percentValue = floatval(str_replace('%', '', $rawPrice));
            $baseRef = $this->toScalar($row['Base'] ?? '');

            if ($baseRef !== '' && isset($this->codeIndex[$baseRef])) {
                $baseRow = $this->codeIndex[$baseRef][0];
                $basePrice = $this->resolvePrice($baseRow, $priceKey, $visited);
                return round($basePrice * ($percentValue / 100), 4);
            }
            return 0;
        }

        return floatval($rawPrice);
    }

    /**
     * Match room ID (handle URL encoding)
     */
    private function matchRoom(string $rowRoom, string $roomId): bool
    {
        if (empty($roomId)) return true;
        return $rowRoom === $roomId ||
               rawurldecode($rowRoom) === $roomId ||
               $rowRoom === rawurlencode($roomId);
    }

    /**
     * Match board ID
     */
    private function matchBoard(string $rowBoard, string $boardId): bool
    {
        if (empty($boardId)) return true;
        return strcasecmp($rowBoard, $boardId) === 0;
    }

    /**
     * Match age type (with fuzzy matching)
     *
     * Handles multiple IdAge formats:
     * - Descriptive: 'ADULT ', '1 ST CHD 2-11,99'
     * - Numeric mapped: '1' → 'ADULT' (handled in findSeasonPriceRow)
     * - Age band variations: '2-11,99' vs '2-11.99'
     */
    private function matchAgeType(string $rowAge, string $ageType): bool
    {
        // Normalize spaces and trim
        $rowAge = trim(preg_replace('/\s+/', ' ', $rowAge));
        $ageType = trim(preg_replace('/\s+/', ' ', $ageType));

        if (strcasecmp($rowAge, $ageType) === 0) {
            return true;
        }

        // Try matching with comma/dot variation in age bands (2-11,99 vs 2-11.99)
        $rowAgeNorm = str_replace(',', '.', $rowAge);
        $ageTypeNorm = str_replace(',', '.', $ageType);
        if (strcasecmp($rowAgeNorm, $ageTypeNorm) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Match accommodation type
     *
     * Handles value variations: RB/REGULAR, EB/EXTRA BED/EXTRA_BED
     */
    private function matchAccType(string $rowAcc, string $accType): bool
    {
        $rowAcc = strtoupper(trim($rowAcc));
        $accType = strtoupper(trim($accType));

        if ($rowAcc === $accType) {
            return true;
        }

        // Normalize both values to canonical form for comparison
        $rowNorm = $this->normalizeAccType($rowAcc);
        $accNorm = $this->normalizeAccType($accType);

        return $rowNorm === $accNorm;
    }

    /**
     * Normalize accommodation type to canonical form
     */
    private function normalizeAccType(string $acc): string
    {
        $acc = strtoupper(trim($acc));

        // Map all regular bed variants to 'REGULAR'
        if (in_array($acc, ['RB', 'REGULAR', 'REGULAR BED', 'REGULAR_BED'], true)) {
            return 'REGULAR';
        }

        // Map all extra bed variants to 'EXTRA BED'
        if (in_array($acc, ['EB', 'EXTRA BED', 'EXTRA_BED', 'EXTRABED'], true)) {
            return 'EXTRA BED';
        }

        return $acc;
    }

    /**
     * Calculate fees (extras_daily, handling_fee, company_fee, etc.)
     */
    private function calculateFees(array $occupancy, string $checkIn, int $nights, string $roomId, string $boardId): array
    {
        $fees = [
            'extras_daily' => 0,
            'extras_single' => 0,
            'extras_rooms' => 0,
            'extras_board' => 0,
            'handling_fee' => 0,
            'company_fee' => 0,
            'total' => 0,
            'details' => []
        ];

        // Calculate extras_daily
        $fees['extras_daily'] = $this->calculateExtrasDaily($occupancy, $checkIn, $nights);
        if ($fees['extras_daily'] > 0) {
            $fees['details'][] = ['type' => 'extras_daily', 'amount' => $fees['extras_daily']];
        }

        // Calculate handling_fee
        $fees['handling_fee'] = $this->calculateHandlingFee($occupancy, $checkIn, $nights);
        if ($fees['handling_fee'] > 0) {
            $fees['details'][] = ['type' => 'handling_fee', 'amount' => $fees['handling_fee']];
        }

        // Calculate extras_single (if applicable)
        $fees['extras_single'] = $this->calculateExtrasSingle($occupancy, $checkIn, $nights, $roomId);
        if ($fees['extras_single'] > 0) {
            $fees['details'][] = ['type' => 'extras_single', 'amount' => $fees['extras_single']];
        }

        // Calculate extras_rooms
        $fees['extras_rooms'] = $this->calculateExtrasRooms($occupancy, $checkIn, $nights, $roomId);
        if ($fees['extras_rooms'] > 0) {
            $fees['details'][] = ['type' => 'extras_rooms', 'amount' => $fees['extras_rooms']];
        }

        // Calculate extras_board
        $fees['extras_board'] = $this->calculateExtrasBoard($occupancy, $checkIn, $nights, $boardId);
        if ($fees['extras_board'] > 0) {
            $fees['details'][] = ['type' => 'extras_board', 'amount' => $fees['extras_board']];
        }

        // Calculate company_fee (per room)
        $fees['company_fee'] = $this->calculateCompanyFee($roomId);
        if ($fees['company_fee'] > 0) {
            $fees['details'][] = ['type' => 'company_fee', 'amount' => $fees['company_fee']];
        }

        $fees['total'] = $fees['extras_daily'] + $fees['extras_single'] +
                         $fees['extras_rooms'] + $fees['extras_board'] +
                         $fees['handling_fee'] + $fees['company_fee'];

        return $fees;
    }

    /**
     * Calculate extras_daily fees
     */
    private function calculateExtrasDaily(array $occupancy, string $checkIn, int $nights): float
    {
        $extrasDaily = $this->priceinfo['extras_daily'] ?? [];
        if (empty($extrasDaily)) return 0;

        if (isset($extrasDaily['IdAge'])) {
            $extrasDaily = [$extrasDaily];
        }

        $total = 0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasDaily as $extra) {
            $fromDate = $extra['FromDate'] ?? '';
            $toDate = $extra['ToDate'] ?? '';
            $idAge = $this->toScalar($extra['IdAge'] ?? '');
            $price = floatval($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day';

            // Check date overlap
            if (!$this->datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                continue;
            }

            // Count matching persons (strip "BY X AD" suffix for fee matching)
            $feeIdAge = $this->feeKey($idAge);
            $count = $this->countMatchingPersons($occupancy, $feeIdAge);

            if ($count > 0) {
                if ($type === 'Arrival') {
                    // Arrival type: If check-in is within the period, charge applies to WHOLE stay (all nights)
                    // Check if check-in date falls within FromDate-ToDate
                    if ($checkIn >= $fromDate && $checkIn <= $toDate) {
                        $total += $price * $count * $nights;
                    }
                } elseif ($type === 'Stay') {
                    // Stay type: Charged for each day that falls within the date range (per overlapping night)
                    $overlappingNights = $this->countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $count * $overlappingNights;
                } else {
                    // Default (Day/Night): Apply per overlapping night
                    $overlappingNights = $this->countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $count * $overlappingNights;
                }
            }
        }

        return $total;
    }

    /**
     * Calculate handling_fee
     *
     * Structure from XML:
     * <handling_fee>
     *   <FromDate>2026-01-01</FromDate>
     *   <ToDate>2026-12-31</ToDate>
     *   <IdAge>ADULT</IdAge>
     *   <ToDays>3</ToDays>      <!-- Use Price1 for stays <= 3 days -->
     *   <Price1>5.5</Price1>
     *   <FromDays>4</FromDays>  <!-- Use Price2 for stays >= 4 days -->
     *   <Price2>7.5</Price2>
     * </handling_fee>
     *
     * For each handling_fee entry:
     * - Check if stay overlaps with FromDate-ToDate
     * - Choose Price1 if nights <= ToDays
     * - Choose Price2 if nights >= FromDays
     * - Multiply by number of people matching IdAge
     */
    private function calculateHandlingFee(array $occupancy, string $checkIn, int $nights): float
    {
        $handlingFees = $this->priceinfo['handling_fee'] ?? [];
        if (empty($handlingFees)) return 0;

        // Normalize to array
        if (isset($handlingFees['Price1']) || isset($handlingFees['ToDays'])) {
            $handlingFees = [$handlingFees];
        }

        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        $total = 0;

        foreach ($handlingFees as $fee) {
            $fromDate = $fee['FromDate'] ?? '';
            $toDate = $fee['ToDate'] ?? '';
            $idAge = $this->toScalar($fee['IdAge'] ?? '');
            $toDays = intval($fee['ToDays'] ?? 3);     // Use Price1 for stays <= this
            $fromDays = intval($fee['FromDays'] ?? 4); // Use Price2 for stays >= this
            $price1 = floatval($fee['Price1'] ?? 0);
            $price2 = floatval($fee['Price2'] ?? 0);

            // Check date range overlap (if dates specified)
            if (!empty($fromDate) && !empty($toDate)) {
                if (!$this->datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            // Choose price based on stay length
            // Price1: stays <= ToDays
            // Price2: stays >= FromDays
            $price = 0;
            if ($nights <= $toDays) {
                $price = $price1;
            } elseif ($nights >= $fromDays) {
                $price = $price2;
            } else {
                // Between ToDays and FromDays - use Price1 as fallback
                $price = $price1;
            }

            // Count matching persons (strip "BY X AD" suffix for fee matching)
            $count = 1;
            $feeIdAge = $this->feeKey($idAge);
            if (!empty($feeIdAge)) {
                $count = $this->countMatchingPersons($occupancy, $feeIdAge);
            } else {
                // Apply per person (adults + children)
                $count = count($occupancy['adults']) + count($occupancy['children']);
            }

            $total += $price * $count;
        }

        return $total;
    }

    /**
     * Calculate extras_single (single supplement)
     *
     * extras_single typically applies when 1 adult in a double room.
     * It compensates for the unused bed in the room.
     */
    private function calculateExtrasSingle(array $occupancy, string $checkIn, int $nights, string $roomId = ''): float
    {
        // Single supplement typically applies when 1 adult in double room
        $extrasSingle = $this->priceinfo['extras_single'] ?? [];
        if (empty($extrasSingle)) return 0;

        // Only applies if single adult
        if (count($occupancy['adults']) !== 1) return 0;

        // Check if room is a double (DBL) or larger - single supplement usually doesn't apply to SGL rooms
        if (!empty($roomId) && stripos($roomId, 'SGL') !== false) {
            return 0; // No single supplement for single rooms
        }

        if (isset($extrasSingle['Price'])) {
            $extrasSingle = [$extrasSingle];
        }

        $total = 0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasSingle as $extra) {
            $fromDate = $extra['FromDate'] ?? '';
            $toDate = $extra['ToDate'] ?? '';
            $price = floatval($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Stay';
            $idRoom = $extra['IdRoom'] ?? '';

            // Skip if room doesn't match (if specified)
            if (!empty($idRoom) && !empty($roomId)) {
                if (!$this->matchRoom($idRoom, $roomId)) {
                    continue;
                }
            }

            // Check date overlap
            if (!empty($fromDate) && !empty($toDate)) {
                if (!$this->datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            // Apply based on type
            if ($type === 'Day' || $type === 'Night') {
                if (!empty($fromDate) && !empty($toDate)) {
                    $overlappingNights = $this->countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $overlappingNights;
                } else {
                    $total += $price * $nights;
                }
            } else {
                // Stay - once per stay
                $total += $price;
            }
        }

        return $total;
    }

    /**
     * Calculate extras_rooms
     *
     * extras_rooms are typically additional fees per room (not per person)
     * E.g., sea view supplement, balcony fee, etc.
     */
    private function calculateExtrasRooms(array $occupancy, string $checkIn, int $nights, string $roomId = ''): float
    {
        $extrasRooms = $this->priceinfo['extras_rooms'] ?? [];
        if (empty($extrasRooms)) return 0;

        if (isset($extrasRooms['IdRoom']) || isset($extrasRooms['Price'])) {
            $extrasRooms = [$extrasRooms];
        }

        $total = 0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasRooms as $extra) {
            $fromDate = $extra['FromDate'] ?? '';
            $toDate = $extra['ToDate'] ?? '';
            $price = floatval($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day'; // Day, Stay, Night
            $idRoom = $extra['IdRoom'] ?? '';

            // Skip if room doesn't match (if specified in extras_rooms entry)
            if (!empty($idRoom) && !empty($roomId)) {
                if (!$this->matchRoom($idRoom, $roomId)) {
                    continue;
                }
            }

            // Check date overlap
            if (!empty($fromDate) && !empty($toDate)) {
                if (!$this->datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            // Apply based on type
            if ($type === 'Stay') {
                // Once per stay (per room)
                $total += $price;
            } elseif ($type === 'Night' || $type === 'Day') {
                // Per night (per room)
                if (!empty($fromDate) && !empty($toDate)) {
                    $overlappingNights = $this->countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $overlappingNights;
                } else {
                    $total += $price * $nights;
                }
            } else {
                // Default to per stay
                $total += $price;
            }
        }

        return $total;
    }

    /**
     * Calculate extras_board
     *
     * extras_board are typically board upgrades or supplements
     * E.g., upgrade from HB to FB, soft drinks package, etc.
     */
    private function calculateExtrasBoard(array $occupancy, string $checkIn, int $nights, string $boardId): float
    {
        $extrasBoard = $this->priceinfo['extras_board'] ?? [];
        if (empty($extrasBoard)) return 0;

        if (isset($extrasBoard['IdBoard']) || isset($extrasBoard['Price'])) {
            $extrasBoard = [$extrasBoard];
        }

        $total = 0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasBoard as $extra) {
            $fromDate = $extra['FromDate'] ?? '';
            $toDate = $extra['ToDate'] ?? '';
            $price = floatval($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day'; // Day, Stay, Night
            $idBoard = $extra['IdBoard'] ?? '';
            $idAge = $extra['IdAge'] ?? '';

            // Skip if board doesn't match (if specified)
            if (!empty($idBoard) && !empty($boardId)) {
                if (strcasecmp($idBoard, $boardId) !== 0) {
                    continue;
                }
            }

            // Check date overlap
            if (!empty($fromDate) && !empty($toDate)) {
                if (!$this->datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            // Count matching persons (if IdAge specified)
            $personCount = 1;
            if (!empty($idAge)) {
                $personCount = $this->countMatchingPersons($occupancy, $idAge);
                if ($personCount === 0) continue;
            } else {
                // Apply per person if no IdAge
                $personCount = count($occupancy['adults']) + count($occupancy['children']);
            }

            // Apply based on type
            if ($type === 'Stay') {
                // Once per stay per person
                $total += $price * $personCount;
            } elseif ($type === 'Night' || $type === 'Day') {
                // Per night per person
                if (!empty($fromDate) && !empty($toDate)) {
                    $overlappingNights = $this->countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $personCount * $overlappingNights;
                } else {
                    $total += $price * $personCount * $nights;
                }
            } else {
                // Default to per stay
                $total += $price * $personCount;
            }
        }

        return $total;
    }

    /**
     * Calculate company_fee (per room)
     *
     * Structure from XML:
     * <company_fee>
     *   <IdRoom>DBL</IdRoom>
     *   <Price>10</Price>
     * </company_fee>
     */
    private function calculateCompanyFee(string $roomId): float
    {
        $companyFees = $this->priceinfo['company_fee'] ?? [];
        if (empty($companyFees)) return 0;

        // Normalize to array
        if (isset($companyFees['Price']) || isset($companyFees['IdRoom'])) {
            $companyFees = [$companyFees];
        }

        foreach ($companyFees as $fee) {
            $feeRoomId = $fee['IdRoom'] ?? '';
            $price = floatval($fee['Price'] ?? 0);

            // Match room or apply if no room specified (applies to all)
            if (empty($feeRoomId) || $this->matchRoom($feeRoomId, $roomId)) {
                return $price;
            }
        }

        return 0;
    }

    /**
     * Count persons matching the IdAge specification
     *
     * IdAge examples from hotelinfo:
     * - "ADULT" = regular adults (1st, 2nd)
     * - "3RD ADULT" = only the 3rd adult if present
     * - "4TH ADULT" = only the 4th adult if present
     * - "CHD 0-1" or "CHILD 0-1" = infants (age 0-1)
     * - "CHD 2-11" or "CHILD 2-11" = children (age 2-11)
     * - "1ST CHD" = 1st child
     * - "2ND CHD" = 2nd child
     *
     * Age definitions from hotelinfo <age> element:
     * - IdAge: identifier
     * - FromYear/ToYear: age range
     */
    private function countMatchingPersons(array $occupancy, string $idAge): int
    {
        $count = 0;
        $idAge = strtoupper(trim($idAge));
        $numAdults = count($occupancy['adults']);
        $numChildren = count($occupancy['children']);

        // Check for specific adult positions (3RD ADULT, 4TH ADULT, etc.)
        if (preg_match('/(\d+)(ST|ND|RD|TH)\s*ADULT/i', $idAge, $matches)) {
            $position = intval($matches[1]);
            // Only count if we have that many adults
            if ($numAdults >= $position) {
                $count = 1; // Only 1 person at that position
            }
            return $count;
        }

        // Regular adults (not positional)
        if ($idAge === 'ADULT' || $idAge === 'ADT' || $idAge === 'ADULTS') {
            return $numAdults;
        }

        // Check for specific child positions (1ST CHD, 2ND CHD, etc.)
        if (preg_match('/(\d+)(ST|ND|RD|TH)\s*(CHD|CHILD)/i', $idAge, $matches)) {
            $position = intval($matches[1]);
            // Only count if we have that many children
            if ($numChildren >= $position) {
                $count = 1;
            }
            return $count;
        }

        // Children with age bands
        if (strpos($idAge, 'CHD') !== false || strpos($idAge, 'CHILD') !== false) {
            // Try to extract age range from IdAge (e.g., "CHD 2-11", "CHILD 0-1")
            $fromAge = 0;
            $toAge = 17; // Default max child age

            if (preg_match('/(\d+)\s*-\s*(\d+)/', $idAge, $ageMatches)) {
                $fromAge = intval($ageMatches[1]);
                $toAge = intval($ageMatches[2]);
            } elseif (strpos($idAge, '0-1') !== false || strpos($idAge, 'INFANT') !== false) {
                $fromAge = 0;
                $toAge = 1;
            } elseif (strpos($idAge, '2-11') !== false) {
                $fromAge = 2;
                $toAge = 11;
            }

            foreach ($occupancy['children'] as $child) {
                $childAge = $child['age'] ?? 0;
                if ($childAge >= $fromAge && $childAge <= $toAge) {
                    $count++;
                }
            }
            return $count;
        }

        // Infant specific
        if (strpos($idAge, 'INFANT') !== false || strpos($idAge, 'INF') !== false) {
            foreach ($occupancy['children'] as $child) {
                if (($child['age'] ?? 0) < 2) {
                    $count++;
                }
            }
            return $count;
        }

        return $count;
    }

    /**
     * Check if two date ranges overlap
     */
    private function datesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        return $start1 <= $end2 && $start2 <= $end1;
    }

    /**
     * Count nights overlapping with a date range
     */
    private function countOverlappingNights(string $checkIn, int $nights, string $fromDate, string $toDate): int
    {
        $count = 0;
        $checkInDate = new \DateTime($checkIn);

        for ($i = 0; $i < $nights; $i++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$i} days");
            $dateStr = $currentDate->format('Y-m-d');

            if ($dateStr >= $fromDate && $dateStr <= $toDate) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculate Early Booking discount
     *
     * EB discount can apply to:
     * - Base price (always)
     * - extras_daily (if EBToDaily = 'Yes')
     * - extras_rooms (if EBToRooms = 'Yes')
     * - extras_board (if EBToBoard = 'Yes')
     */
    private function calculateEarlyBookingDiscount(string $bookingDate, string $checkIn, int $nights, array $basePrice, array $fees): array
    {
        $ebData = $this->priceinfo['EB'] ?? $this->priceinfo['early_booking'] ?? [];
        if (empty($ebData)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'discount_breakdown' => []];
        }

        if (isset($ebData['Discount']) || isset($ebData['Reduction'])) {
            $ebData = [$ebData];
        }

        // Get EB application flags from priceinfo
        $ebToDaily = ($this->priceinfo['EBToDaily'] ?? 'No') === 'Yes';
        $ebToRooms = ($this->priceinfo['EBToRooms'] ?? 'No') === 'Yes';
        $ebToBoard = ($this->priceinfo['EBToBoard'] ?? 'No') === 'Yes';

        $bestDiscount = 0;
        $bestPercent = 0;
        $bestBreakdown = [];
        $applicable = false;

        foreach ($ebData as $eb) {
            $bookFrom = $eb['BookingFrom'] ?? $eb['BookFrom'] ?? '';
            $bookTo = $eb['BookingTo'] ?? $eb['BookTo'] ?? '';
            $travelFrom = $eb['TravelTimeFrom'] ?? $eb['StayFrom'] ?? '';
            $travelTo = $eb['TravelTimeTo'] ?? $eb['StayTo'] ?? '';
            $discount = floatval($eb['Discount'] ?? $eb['Reduction'] ?? 0);
            $minStay = intval($eb['MinimumStay'] ?? $eb['MinStay'] ?? 0);

            // Check booking date in range
            if ($bookingDate < $bookFrom || $bookingDate > $bookTo) {
                continue;
            }

            // Check travel date in range
            if ($checkIn < $travelFrom || $checkIn > $travelTo) {
                continue;
            }

            // Check minimum stay
            if ($minStay > 0 && $nights < $minStay) {
                continue;
            }

            $applicable = true;
            if ($discount > $bestPercent) {
                $bestPercent = $discount;
                $discountRate = $discount / 100;

                // Calculate discount for each applicable component
                $breakdown = [
                    'base_price' => $basePrice['total'] * $discountRate,
                    'extras_daily' => $ebToDaily ? ($fees['extras_daily'] ?? 0) * $discountRate : 0,
                    'extras_rooms' => $ebToRooms ? ($fees['extras_rooms'] ?? 0) * $discountRate : 0,
                    'extras_board' => $ebToBoard ? ($fees['extras_board'] ?? 0) * $discountRate : 0,
                ];

                $bestDiscount = array_sum($breakdown);
                $bestBreakdown = $breakdown;
            }
        }

        $this->log('EB flags', [
            'EBToDaily' => $ebToDaily,
            'EBToRooms' => $ebToRooms,
            'EBToBoard' => $ebToBoard,
            'breakdown' => $bestBreakdown
        ]);

        return [
            'applicable' => $applicable,
            'discount' => $bestDiscount,
            'percent' => $bestPercent,
            'discount_breakdown' => $bestBreakdown
        ];
    }

    /**
     * Calculate Reduction (free nights)
     *
     * For multi-season stays, this calculates the actual value of free nights
     * based on their season prices, not just an average.
     *
     * EXT discount can also apply to supplements based on:
     * - EXTToDaily: If Yes, proportional reduction applies to extras_daily
     * - EXTToRooms: If Yes, proportional reduction applies to extras_rooms
     * - EXTToBoard: If Yes, proportional reduction applies to extras_board
     */
    private function calculateReduction(string $checkIn, int $nights, array $seasonsByNight, array $occupancy, string $roomId, string $boardId, array $basePrice = [], array $fees = []): array
    {
        $reductions = $this->priceinfo['reduction'] ?? [];
        if (empty($reductions)) {
            return ['applicable' => false, 'discount' => 0, 'free_nights' => 0, 'free_night_indices' => [], 'discount_breakdown' => []];
        }

        if (isset($reductions['FreeNights'])) {
            $reductions = [$reductions];
        }

        // Get EXT application flags from priceinfo
        $extToDaily = ($this->priceinfo['EXTToDaily'] ?? 'No') === 'Yes';
        $extToRooms = ($this->priceinfo['EXTToRooms'] ?? 'No') === 'Yes';
        $extToBoard = ($this->priceinfo['EXTToBoard'] ?? 'No') === 'Yes';

        $bestDiscount = 0;
        $bestFreeNights = 0;
        $bestFreeNightIndices = [];
        $bestBreakdown = [];
        $applicable = false;

        foreach ($reductions as $red) {
            $fromNights = intval($red['FromNights'] ?? 0);
            $toNights = intval($red['ToNights'] ?? 999);
            $checkInFrom = $red['CheckInFrom'] ?? '';
            $checkInTo = $red['CheckInTo'] ?? '';
            $freeNights = intval($red['FreeNights'] ?? 0);
            $type = $red['Type'] ?? 'End'; // End = last nights free

            // Check nights in range
            if ($nights < $fromNights || $nights > $toNights) {
                continue;
            }

            // Check check-in in range
            if (!empty($checkInFrom) && !empty($checkInTo)) {
                if ($checkIn < $checkInFrom || $checkIn > $checkInTo) {
                    continue;
                }
            }

            if ($freeNights <= 0) {
                continue;
            }

            $applicable = true;

            // Calculate which nights are free
            $freeNightIndices = [];
            if ($type === 'End') {
                // Last N nights are free
                for ($i = $nights - $freeNights; $i < $nights; $i++) {
                    if ($i >= 0) $freeNightIndices[] = $i;
                }
            } else {
                // First N nights are free
                for ($i = 0; $i < $freeNights && $i < $nights; $i++) {
                    $freeNightIndices[] = $i;
                }
            }

            // Calculate actual discount from the free nights' base prices
            $basePriceDiscount = 0;
            if (!empty($basePrice['by_night'])) {
                foreach ($freeNightIndices as $nightIdx) {
                    if (isset($basePrice['by_night'][$nightIdx])) {
                        $basePriceDiscount += $basePrice['by_night'][$nightIdx]['price'] ?? 0;
                    }
                }
            } else {
                // Fallback: estimate as proportional if by_night not available
                $avgNightPrice = $nights > 0 ? $basePrice['total'] / $nights : 0;
                $basePriceDiscount = $avgNightPrice * count($freeNightIndices);
            }

            // Calculate proportional discount for extras (free_nights / total_nights)
            $freeNightsRatio = $nights > 0 ? count($freeNightIndices) / $nights : 0;

            $breakdown = [
                'base_price' => $basePriceDiscount,
                'extras_daily' => $extToDaily ? ($fees['extras_daily'] ?? 0) * $freeNightsRatio : 0,
                'extras_rooms' => $extToRooms ? ($fees['extras_rooms'] ?? 0) * $freeNightsRatio : 0,
                'extras_board' => $extToBoard ? ($fees['extras_board'] ?? 0) * $freeNightsRatio : 0,
            ];

            $totalDiscount = array_sum($breakdown);

            if ($totalDiscount > $bestDiscount) {
                $bestDiscount = $totalDiscount;
                $bestFreeNights = $freeNights;
                $bestFreeNightIndices = $freeNightIndices;
                $bestBreakdown = $breakdown;
            }
        }

        $this->log('EXT flags', [
            'EXTToDaily' => $extToDaily,
            'EXTToRooms' => $extToRooms,
            'EXTToBoard' => $extToBoard,
            'breakdown' => $bestBreakdown
        ]);

        return [
            'applicable' => $applicable,
            'discount' => $bestDiscount,
            'free_nights' => $bestFreeNights,
            'free_night_indices' => $bestFreeNightIndices,
            'discount_breakdown' => $bestBreakdown
        ];
    }

    /**
     * Apply priority rules to select best discount scenario
     */
    private function applyPriorityRules(array $basePrice, array $fees, array $ebDiscount, array $reduction): array
    {
        $priority = $this->priceinfo['Priority'] ?? 'No';
        $priorityEB = $this->priceinfo['PriorityEB'] ?? 'No';
        $priorityEXT = $this->priceinfo['PriorityEXT'] ?? 'No';

        $basePlusFees = $basePrice['total'] + $fees['total'];

        // Scenario 1: No discount
        $totalNone = $basePlusFees;

        // Scenario 2: Early Booking
        $totalEB = $ebDiscount['applicable'] ? ($basePlusFees - $ebDiscount['discount']) : $basePlusFees;

        // Scenario 3: Reduction
        $totalReduction = $reduction['applicable'] ? ($basePlusFees - $reduction['discount']) : $basePlusFees;

        // Scenario 4: Combined (if allowed)
        $totalCombined = $basePlusFees;
        if ($ebDiscount['applicable']) $totalCombined -= $ebDiscount['discount'];
        if ($reduction['applicable']) $totalCombined -= $reduction['discount'];

        // Apply priority rules
        $appliedDiscount = 'none';
        $discountAmount = 0;
        $finalTotal = $totalNone;

        if ($priority === 'Yes') {
            // Discounts not combinable - EB and Reduction are mutually exclusive
            // Case 1: PriorityEB='Yes', PriorityEXT='No' -> EB has forced priority
            // Case 2: PriorityEB='No', PriorityEXT='Yes' -> Can choose best discount
            if ($priorityEB === 'Yes' && $ebDiscount['applicable']) {
                // EB has forced priority - use EB regardless of which gives better savings
                $finalTotal = $totalEB;
                $appliedDiscount = 'early_booking';
                $discountAmount = $ebDiscount['discount'];
            } else {
                // PriorityEXT='Yes' or neither set - pick the best discount (lowest price)
                if ($totalEB <= $totalReduction && $ebDiscount['applicable']) {
                    $finalTotal = $totalEB;
                    $appliedDiscount = 'early_booking';
                    $discountAmount = $ebDiscount['discount'];
                } elseif ($reduction['applicable']) {
                    $finalTotal = $totalReduction;
                    $appliedDiscount = 'reduction';
                    $discountAmount = $reduction['discount'];
                }
            }
        } else {
            // Discounts are combinable - use combined total
            if ($ebDiscount['applicable'] || $reduction['applicable']) {
                $finalTotal = $totalCombined;
                $appliedDiscount = 'combined';
                $discountAmount = ($ebDiscount['applicable'] ? $ebDiscount['discount'] : 0) +
                                  ($reduction['applicable'] ? $reduction['discount'] : 0);
            }
        }

        return [
            'total' => max(0, $finalTotal),
            'applied_discount' => $appliedDiscount,
            'discount_amount' => $discountAmount,
            'scenarios' => [
                'none' => $totalNone,
                'early_booking' => $totalEB,
                'reduction' => $totalReduction,
                'combined' => $totalCombined
            ]
        ];
    }

    /**
     * Apply commission to price
     */
    private function applyCommission(float $price): float
    {
        if ($this->commission <= 0) {
            return $price;
        }
        return $price * (1 + ($this->commission / 100));
    }

    /**
     * Create error result
     */
    private function errorResult(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'price' => 0,
            'debug_log' => $this->debug ? $this->debugLog : null
        ];
    }

    /**
     * Log debug message
     */
    private function log(string $message, $data = null): void
    {
        $entry = [
            'time' => date('H:i:s'),
            'message' => $message
        ];
        if ($data !== null) {
            $entry['data'] = $data;
        }
        $this->debugLog[] = $entry;

        if ($this->debug) {
            fn_log_event('general', 'runtime', [
                'message' => 'PriceInfoCalculation: ' . $message,
                'data' => $data
            ]);
        }
    }

    /**
     * Enable/disable debug mode
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Get debug log
     */
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * Verify season-to-price correlation
     *
     * This method helps debug if seasons are correctly mapped to prices.
     * Call this to see which Price column is used for each date.
     *
     * @param string $checkIn Check-in date
     * @param int $nights Number of nights
     * @return array Debug info showing date → season → priceKey mapping
     */
    public function verifySeasonPriceMapping(string $checkIn, int $nights): array
    {
        $seasons = $this->priceinfo['seasons'] ?? [];
        $seasonPrices = $this->priceinfo['season_price'] ?? [];

        // Parse seasons
        $parsedSeasons = [];
        if (isset($seasons['Season'])) {
            $parsedSeasons = [$seasons];
        } elseif (isset($seasons[0]['Season'])) {
            $parsedSeasons = $seasons;
        } elseif (isset($seasons[0]) && isset($seasons[0]['Season'])) {
            $parsedSeasons = $seasons;
        }

        // Get season mapping for each night
        $mapping = [];
        $checkInDate = new \DateTime($checkIn);

        for ($night = 0; $night < $nights; $night++) {
            $currentDate = clone $checkInDate;
            $currentDate->modify("+{$night} days");
            $dateStr = $currentDate->format('Y-m-d');

            $seasonNum = 1;
            $matchedSeason = null;

            foreach ($parsedSeasons as $season) {
                $from = $season['FromDate'] ?? '';
                $to = $season['ToDate'] ?? '';
                $id = intval($season['Season'] ?? $season['IdSeason'] ?? 1);

                if ($dateStr >= $from && $dateStr <= $to) {
                    $seasonNum = $id;
                    $matchedSeason = $season;
                    break;
                }
            }

            $priceKey = 'Price' . $seasonNum;

            $mapping[] = [
                'night' => $night + 1,
                'date' => $dateStr,
                'season_num' => $seasonNum,
                'price_key' => $priceKey,
                'matched_range' => $matchedSeason ? ($matchedSeason['FromDate'] . ' to ' . $matchedSeason['ToDate']) : 'DEFAULT'
            ];
        }

        return [
            'total_seasons_found' => count($parsedSeasons),
            'seasons_raw' => $parsedSeasons,
            'night_mapping' => $mapping
        ];
    }

    /**
     * Get a sample price for verification
     *
     * Returns the raw price values from season_price for a specific room/board
     * to help verify the Price1, Price2, etc. values.
     *
     * @param string $roomId Room ID
     * @param string $boardId Board ID
     * @return array Price values by column
     */
    public function getSamplePrices(string $roomId, string $boardId): array
    {
        $seasonPrices = $this->priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $samples = [];
        foreach ($seasonPrices as $row) {
            $rowRoom = $row['IdRoom'] ?? '';
            $rowBoard = $row['IdBoard'] ?? '';

            if ($this->matchRoom($rowRoom, $roomId) && $this->matchBoard($rowBoard, $boardId)) {
                $sample = [
                    'IdAge' => $row['IdAge'] ?? '',
                    'IdAcc' => $row['IdAcc'] ?? '',
                    'Code' => $row['Code'] ?? '',
                    'Base' => $row['Base'] ?? '',
                    'RoomPrice' => $row['RoomPrice'] ?? 'No',
                ];

                // Add all Price columns
                for ($i = 1; $i <= 20; $i++) {
                    $key = 'Price' . $i;
                    if (isset($row[$key]) && $row[$key] !== '') {
                        $sample[$key] = $row[$key];
                    }
                }

                $samples[] = $sample;
            }
        }

        return $samples;
    }
}
