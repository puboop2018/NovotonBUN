<?php
/**
 * Novoton PriceInfo Calculation Service
 *
 * Calculates prices from priceinfo data to match room_price API response.
 *
 * Formula:
 * Price - EB* + extras_single + (extras_daily - EB|reduction**) + (extras_rooms - EB|reduction**)
 *       + (extras_board - EB|reduction**) - reduction* - reduction_perc_additional + company_fee
 *
 * * If Priority='Yes', EB and reduction are NOT combinable - check PriorityEB and PriorityEXT
 * ** Check tags for EB and reductions to extras_daily, extras_rooms, extras_board
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;

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

    /**
     * How to handle extras_daily with Type=Stay
     * 'per_night' = apply for every night
     * 'per_stay' = apply once if any date overlaps
     */
    const EXTRAS_DAILY_STAY_MODE = 'per_night';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->commission = floatval(Registry::get('addons.novoton_holidays.commission') ?? 0);
        $this->debug = (Registry::get('addons.novoton_holidays.debug_logging') ?? 'N') === 'Y';
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

        // Load hotelinfo for room capacities
        $this->hotelinfo = $this->loadHotelInfo($hotelId);

        // Extract parameters
        $checkIn = $params['check_in'] ?? date('Y-m-d', strtotime('+30 days'));
        $nights = intval($params['nights'] ?? 7);
        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';
        $adults = intval($params['adults'] ?? 2);
        $childrenAges = $params['children_ages'] ?? [];
        $bookingDate = $params['booking_date'] ?? date('Y-m-d');

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
        $occupancy = $this->buildOccupancyStructure($adults, $childrenAges, $roomCapacity);
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

        // Step 6: Get Early Booking discount
        $ebDiscount = $this->calculateEarlyBookingDiscount($bookingDate, $checkIn, $nights, $basePrice);
        $this->log('Early Booking discount', $ebDiscount);

        // Step 7: Get Reduction (free nights)
        $reduction = $this->calculateReduction($checkIn, $nights, $seasonsByNight, $occupancy, $roomId, $boardId);
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
                'fees' => [
                    'extras_daily' => round($fees['extras_daily'] ?? 0, 2),
                    'extras_single' => round($fees['extras_single'] ?? 0, 2),
                    'extras_rooms' => round($fees['extras_rooms'] ?? 0, 2),
                    'extras_board' => round($fees['extras_board'] ?? 0, 2),
                    'handling_fee' => round($fees['handling_fee'] ?? 0, 2),
                    'company_fee' => round($fees['company_fee'] ?? 0, 2),
                    'total_fees' => round($fees['total'] ?? 0, 2)
                ],
                'discounts' => [
                    'early_booking' => $ebDiscount,
                    'reduction' => $reduction
                ],
                'applied_discount' => $finalPrice['applied_discount'],
                'discount_amount' => round($finalPrice['discount_amount'] ?? 0, 2)
            ],
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
     * Children fill remaining REGULAR beds, then go to EXTRA BED
     */
    private function buildOccupancyStructure(int $adults, array $childrenAges, array $capacity): array
    {
        $occupancy = [
            'adults' => [],
            'children' => [],
            'total_rb_used' => 0,
            'total_eb_used' => 0
        ];

        $rbAvailable = $capacity['RB'];
        $ebAvailable = $capacity['EB'];
        $rbUsed = 0;
        $ebUsed = 0;

        // Place adults
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

        // Place children
        foreach ($childrenAges as $idx => $age) {
            $childNum = $idx + 1;
            $ageBand = $this->getAgeBand($age);
            $ordinal = $this->getChildOrdinal($childNum);

            if ($rbUsed < $rbAvailable) {
                // Regular bed
                $occupancy['children'][] = [
                    'index' => $childNum,
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
                    'index' => $childNum,
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
     * Get age band string
     */
    private function getAgeBand(float $age): string
    {
        if ($age < 2.0) {
            return '0-1,99';
        }
        return '2-11,99';
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

        // Get base code row for percentage calculations
        $baseCodeRow = $this->findBaseCodeRow($seasonPrices, $roomId, $boardId);

        // Calculate for each night
        foreach ($seasonsByNight as $nightIdx => $nightInfo) {
            $nightTotal = 0;
            $seasonNum = $nightInfo['season'];
            $priceKey = 'Price' . $seasonNum;

            // Check if this is per-room pricing
            $isRoomPrice = false;

            // Adults
            foreach ($occupancy['adults'] as $adult) {
                $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $adult['age_type'], $adult['acc_type'], $nights);

                if ($row) {
                    $price = $this->getPriceFromRow($row, $priceKey, $baseCodeRow, $seasonPrices);
                    $isRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';

                    // If RoomPrice=Yes, only add once per room (not per person)
                    if ($isRoomPrice && $adult['index'] > 1) {
                        // Skip additional adults for room-priced rates
                        continue;
                    }

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
                    $price = $this->getPriceFromRow($row, $priceKey, $baseCodeRow, $seasonPrices);
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
            $rowRoom = $row['IdRoom'] ?? '';
            $rowBoard = $row['IdBoard'] ?? '';
            $code = $row['Code'] ?? '';

            if ($this->matchRoom($rowRoom, $roomId) && $this->matchBoard($rowBoard, $boardId) && $code === 'Base') {
                return $row;
            }
        }
        return null;
    }

    /**
     * Find season_price row matching criteria
     */
    private function findSeasonPriceRow(array $seasonPrices, string $roomId, string $boardId, string $ageType, string $accType, int $nights): ?array
    {
        foreach ($seasonPrices as $row) {
            $rowRoom = $row['IdRoom'] ?? '';
            $rowBoard = $row['IdBoard'] ?? '';
            $rowAge = $row['IdAge'] ?? '';
            $rowAcc = $row['IdAcc'] ?? '';
            $fromDays = intval($row['FromDays'] ?? 1);
            $toDays = intval($row['ToDays'] ?? 999);

            // Match room, board, age, acc
            if (!$this->matchRoom($rowRoom, $roomId)) continue;
            if (!$this->matchBoard($rowBoard, $boardId)) continue;
            if (!$this->matchAgeType($rowAge, $ageType)) continue;
            if (!$this->matchAccType($rowAcc, $accType)) continue;

            // Validate stay length
            if ($nights < $fromDays || $nights > $toDays) continue;

            return $row;
        }

        return null;
    }

    /**
     * Get price from row, handling percentages
     *
     * Percentages can be:
     * - String with % sign: "80%", "50%"
     * - Numeric value <= 100 when Code references Base
     */
    private function getPriceFromRow(array $row, string $priceKey, ?array $baseCodeRow, array $allSeasonPrices = []): float
    {
        $rawPrice = $row[$priceKey] ?? $row['Price1'] ?? 0;
        $code = $row['Code'] ?? 'Base';
        $baseRef = $row['Base'] ?? '';  // Reference to which Code is the base
        $isRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';

        // Handle string percentage like "80%", "50%"
        if (is_string($rawPrice) && strpos($rawPrice, '%') !== false) {
            $percentValue = floatval(str_replace('%', '', $rawPrice));

            // Find the base row to calculate from
            $basePrice = 0;
            if (!empty($baseRef) && !empty($allSeasonPrices)) {
                // Find row with Code matching our Base reference
                foreach ($allSeasonPrices as $baseRow) {
                    if (($baseRow['Code'] ?? '') == $baseRef) {
                        $basePrice = floatval($baseRow[$priceKey] ?? $baseRow['Price1'] ?? 0);
                        break;
                    }
                }
            } elseif ($baseCodeRow) {
                $basePrice = floatval($baseCodeRow[$priceKey] ?? $baseCodeRow['Price1'] ?? 0);
            }

            if ($basePrice > 0) {
                return $basePrice * ($percentValue / 100);
            }
            // If no base found, return 0 (should not happen in valid data)
            return 0;
        }

        // If Code is not 'Base' and we have a Base reference, it might be a numeric percentage
        if ($code !== 'Base' && !empty($baseRef) && is_numeric($rawPrice)) {
            $numericPrice = floatval($rawPrice);
            // If the value is small (0-100), it's likely a percentage
            if ($numericPrice > 0 && $numericPrice <= 100 && !empty($allSeasonPrices)) {
                foreach ($allSeasonPrices as $baseRow) {
                    if (($baseRow['Code'] ?? '') == $baseRef) {
                        $basePrice = floatval($baseRow[$priceKey] ?? $baseRow['Price1'] ?? 0);
                        if ($basePrice > 0) {
                            return $basePrice * ($numericPrice / 100);
                        }
                        break;
                    }
                }
            }
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
     */
    private function matchAgeType(string $rowAge, string $ageType): bool
    {
        // Normalize spaces and trim
        $rowAge = trim(preg_replace('/\s+/', ' ', $rowAge));
        $ageType = trim(preg_replace('/\s+/', ' ', $ageType));

        return strcasecmp($rowAge, $ageType) === 0;
    }

    /**
     * Match accommodation type
     */
    private function matchAccType(string $rowAcc, string $accType): bool
    {
        $rowAcc = trim($rowAcc);
        $accType = trim($accType);

        return strcasecmp($rowAcc, $accType) === 0;
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
        $fees['handling_fee'] = $this->calculateHandlingFee($occupancy, $nights);
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
            $idAge = $extra['IdAge'] ?? '';
            $price = floatval($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day';

            // Check date overlap
            if (!$this->datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                continue;
            }

            // Count matching persons
            $count = $this->countMatchingPersons($occupancy, $idAge);

            if ($count > 0) {
                if ($type === 'Stay' && self::EXTRAS_DAILY_STAY_MODE === 'per_stay') {
                    // Apply once per stay
                    $total += $price * $count;
                } else {
                    // Apply per night (or per_night mode for Stay)
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
     *   <ToDays>3</ToDays>      <!-- Use Price1 for stays <= 3 days -->
     *   <Price1>5.5</Price1>
     *   <FromDays>4</FromDays>  <!-- Use Price2 for stays >= 4 days -->
     *   <Price2>7.5</Price2>
     * </handling_fee>
     */
    private function calculateHandlingFee(array $occupancy, int $nights): float
    {
        $handlingFees = $this->priceinfo['handling_fee'] ?? [];
        if (empty($handlingFees)) return 0;

        // Normalize to array
        if (isset($handlingFees['Price1']) || isset($handlingFees['ToDays'])) {
            $handlingFees = [$handlingFees];
        }

        $total = 0;

        foreach ($handlingFees as $fee) {
            $idAge = $fee['IdAge'] ?? '';
            $toDays = intval($fee['ToDays'] ?? 3);     // Use Price1 for stays <= this
            $fromDays = intval($fee['FromDays'] ?? 4); // Use Price2 for stays >= this
            $price1 = floatval($fee['Price1'] ?? 0);
            $price2 = floatval($fee['Price2'] ?? 0);

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

            // Count matching persons (if IdAge specified, otherwise per person)
            $count = 1;
            if (!empty($idAge)) {
                $count = $this->countMatchingPersons($occupancy, $idAge);
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
    private function calculateExtrasRooms(array $occupancy, string $checkIn, int $nights): float
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

            // Skip if room doesn't match (if specified)
            // Note: We don't have roomId in this context, so apply to all rooms
            // TODO: Pass roomId to this function if needed

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
     * Count persons matching an age type
     */
    private function countMatchingPersons(array $occupancy, string $idAge): int
    {
        $count = 0;
        $idAge = strtoupper(trim($idAge));

        // Adults
        if (strpos($idAge, 'ADULT') !== false || $idAge === 'ADT') {
            $count += count($occupancy['adults']);
        }

        // Children - check age bands
        if (strpos($idAge, 'CHD') !== false || strpos($idAge, 'CHILD') !== false) {
            foreach ($occupancy['children'] as $child) {
                // Check if age band matches
                if (strpos($idAge, '0-1') !== false && $child['age'] < 2) {
                    $count++;
                } elseif (strpos($idAge, '2-11') !== false && $child['age'] >= 2) {
                    $count++;
                } elseif (strpos($idAge, '0-1') === false && strpos($idAge, '2-11') === false) {
                    // Generic child
                    $count++;
                }
            }
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
     */
    private function calculateEarlyBookingDiscount(string $bookingDate, string $checkIn, int $nights, array $basePrice): array
    {
        $ebData = $this->priceinfo['EB'] ?? $this->priceinfo['early_booking'] ?? [];
        if (empty($ebData)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0];
        }

        if (isset($ebData['Discount']) || isset($ebData['Reduction'])) {
            $ebData = [$ebData];
        }

        $bestDiscount = 0;
        $bestPercent = 0;
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
                $bestDiscount = $basePrice['total'] * ($discount / 100);
            }
        }

        return [
            'applicable' => $applicable,
            'discount' => $bestDiscount,
            'percent' => $bestPercent
        ];
    }

    /**
     * Calculate Reduction (free nights)
     */
    private function calculateReduction(string $checkIn, int $nights, array $seasonsByNight, array $occupancy, string $roomId, string $boardId): array
    {
        $reductions = $this->priceinfo['reduction'] ?? [];
        if (empty($reductions)) {
            return ['applicable' => false, 'discount' => 0, 'free_nights' => 0];
        }

        if (isset($reductions['FreeNights'])) {
            $reductions = [$reductions];
        }

        $bestDiscount = 0;
        $bestFreeNights = 0;
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

            // Calculate discount as sum of free nights' prices
            // This would require recalculating prices for those specific nights
            // For simplicity, estimate as (freeNights / nights) * basePrice
            // TODO: Implement exact calculation

            if ($freeNights > $bestFreeNights) {
                $bestFreeNights = $freeNights;
                // Rough estimate - should be calculated from actual night prices
            }
        }

        return [
            'applicable' => $applicable,
            'discount' => $bestDiscount,
            'free_nights' => $bestFreeNights
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
            // Discounts not combinable
            if ($priorityEXT === 'Yes' && $reduction['applicable']) {
                // Reduction has priority
                $finalTotal = $totalReduction;
                $appliedDiscount = 'reduction';
                $discountAmount = $reduction['discount'];
            } elseif ($priorityEB === 'Yes' && $ebDiscount['applicable']) {
                // EB has priority
                $finalTotal = $totalEB;
                $appliedDiscount = 'early_booking';
                $discountAmount = $ebDiscount['discount'];
            } else {
                // Pick the best
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
