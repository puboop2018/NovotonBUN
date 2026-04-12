<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Calculation Service
 *
 * Calculates prices from priceinfo data to match room_price API response.
 *
 * Formula:
 * Price = BasePrice - EB* - reduction_period + extras_single + (extras_daily - EB|reduction**)
 *         + (extras_rooms - EB|reduction**) + (extras_board - EB|reduction**)
 *         - reduction* - reduction_perc_marketing - reduction_perc_additional + company_fee
 *
 * Base Price — season_price Code/Base rule:
 *   - Code == Base  → Price1..Price20 are absolute amounts (EUR)
 *   - Code != Base  → Price1..Price20 are PERCENTAGES of the base row's price,
 *                      where the base row is the season_price row whose Code == this row's Base
 *   This applies to all person types including children on extra beds.
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
 * This class is a slim orchestrator that delegates to:
 *   - PriceInfoParser    — loads and parses raw price data
 *   - PriceInfoCalculator — applies pricing formulas
 *   - PriceInfoFormatter  — matching, normalization, and debug formatting
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;

class PriceInfoCalculation implements PriceInfoCalculationInterface
{
    /** @var float Commission percentage */
    private $commission;

    /** @var bool Debug mode */
    private $debug = false;

    /** @var array<string, mixed> Debug log */
    private $debugLog = [];

    /** @var PriceInfoParser */
    private $parser;

    /** @var PriceInfoCalculator */
    private $calculator;

    /** @var FeeCalculator */
    private $feeCalculator;

    /** @var DiscountCalculator */
    private $discountCalculator;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->commission = ConfigProvider::getCommission();
        $this->debug = ConfigProvider::isDebugLogging();

        $logger = [$this, 'log'];
        $this->parser = new PriceInfoParser($logger);
        $this->calculator = new PriceInfoCalculator($this->parser, $this->commission, $logger);
        $this->feeCalculator = new FeeCalculator($this->parser, $logger);
        $this->discountCalculator = new DiscountCalculator($this->parser, $logger);
    }

    /**
     * Calculate price for a booking
     *
     * @param array<string, mixed> $params Calculation parameters:
     *   - hotel_id: Hotel ID
     *   - package_name: Package name
     *   - check_in: Check-in date (Y-m-d)
     *   - nights: Number of nights
     *   - room_id: Room type ID
     *   - board_id: Board type ID
     *   - adults: Number of adults
     *   - children_ages: Array of children ages (e.g., [1.5, 7])
     *   - booking_date: Date of booking for EB check (default: today)
     * @return array<string, mixed> Calculation result with price breakdowns
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
            return PriceInfoFormatter::errorResult('Missing hotel_id or package_name', $this->debugLog, $this->debug);
        }

        // Step 0: Load and parse data
        $priceinfo = $this->parser->loadPriceInfo($hotelId, $packageName);
        if (empty($priceinfo)) {
            return PriceInfoFormatter::errorResult('Priceinfo not found for hotel/package', $this->debugLog, $this->debug);
        }

        $this->parser->buildCodeIndex();
        $this->parser->loadHotelInfo($hotelId);
        $this->parser->parseChildAgeBands();

        // Extract parameters
        $checkIn = $params['check_in'] ?? date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days'));
        $nights = (int)($params['nights'] ?? 7);
        $roomId = $params['room_id'] ?? '';
        $boardId = $params['board_id'] ?? '';
        $adults = (int)($params['adults'] ?? 2);
        $childrenAges = $params['children_ages'] ?? [];
        $bookingDate = $params['booking_date'] ?? date('Y-m-d');


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
        $roomCapacity = $this->parser->getRoomCapacity($roomId);
        $this->log('Room capacity', $roomCapacity);

        $occupancyValid = $this->parser->validateOccupancy($adults, $numChildren, $roomCapacity);
        if (!$occupancyValid['valid']) {
            return PriceInfoFormatter::errorResult('Invalid occupancy: ' . $occupancyValid['reason'], $this->debugLog, $this->debug);
        }

        // Step 2: Build occupancy structure
        $occupancy = $this->parser->buildOccupancyStructure($adults, $childrenAges, $roomCapacity, $roomId, $boardId);
        $this->log('Occupancy structure', $occupancy);

        // Step 3: Get season mapping for each night
        $seasonsByNight = $this->parser->getSeasonsByNight($checkIn, $nights);
        $this->log('Seasons by night', $seasonsByNight);

        // Step 4: Calculate base price
        $basePrice = $this->calculator->calculateBasePrice($occupancy, $seasonsByNight, $roomId, $boardId, $nights);
        $this->log('Base price', $basePrice);

        // Step 5: Calculate fees
        $fees = $this->feeCalculator->calculateFees($occupancy, $checkIn, $nights, $roomId, $boardId);
        $this->log('Fees', $fees);

        // Step 6: Get Early Booking discount
        $ebDiscount = $this->discountCalculator->calculateEarlyBookingDiscount($bookingDate, $checkIn, $nights, $basePrice, $fees);
        $this->log('Early Booking discount', $ebDiscount);

        // Step 7: Get Reduction (free nights)
        $reduction = $this->discountCalculator->calculateReduction($checkIn, $nights, $seasonsByNight, $occupancy, $roomId, $boardId, $basePrice, $fees);
        $this->log('Reduction', $reduction);

        // Step 7b: Get Reduction Period (MaxDays cap)
        $reductionPeriod = $this->discountCalculator->calculateReductionPeriod($checkIn, $nights, $basePrice);
        $this->log('Reduction Period', $reductionPeriod);

        // Step 8: Apply Priority rules and pick best scenario
        $finalPrice = $this->discountCalculator->applyPriorityRules($basePrice, $fees, $ebDiscount, $reduction, $reductionPeriod);
        $this->log('Final price calculation', $finalPrice);

        // Step 8b: Apply reduction_perc_marketing (booking/travel date restricted)
        $percMarketing = $this->discountCalculator->calculateReductionPercMarketing(
            $bookingDate, $checkIn, $nights, $roomId, $finalPrice['total']
        );
        $this->log('Reduction Perc Marketing', $percMarketing);

        // Step 8c: Apply reduction_perc_additional (flat promo discount)
        $subtotalAfterMarketing = $finalPrice['total'] - ($percMarketing['applicable'] ? $percMarketing['discount'] : 0);
        $percAdditional = $this->discountCalculator->calculateReductionPercAdditional(max(0, $subtotalAfterMarketing));
        $this->log('Reduction Perc Additional', $percAdditional);

        // Step 8d: Compute final total after all percentage discounts
        $totalAfterPercDiscounts = $finalPrice['total'];
        if ($percMarketing['applicable']) {
            $totalAfterPercDiscounts -= $percMarketing['discount'];
        }
        if ($percAdditional['applicable']) {
            $totalAfterPercDiscounts -= $percAdditional['discount'];
        }
        $totalAfterPercDiscounts = max(0, $totalAfterPercDiscounts);

        // Step 9: Apply commission
        $priceWithCommission = $this->calculator->applyCommission($totalAfterPercDiscounts);

        $priceinfo = $this->parser->getPriceinfo();
        $result = [
            'success' => true,
            'price' => round($priceWithCommission, 2),
            'price_without_commission' => round($totalAfterPercDiscounts, 2),
            'commission' => $this->commission,
            'breakdown' => [
                'base_price' => round($basePrice['total'], 2),
                'base_per_night' => $basePrice['by_night'] ?? [],
                'base_per_person' => $basePrice['by_person'] ?? [],
                'base_per_person_per_night' => $basePrice['by_person_by_night'] ?? [],
                'matched_rows' => $basePrice['matched_rows'] ?? [],
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
                    'reduction' => $reduction,
                    'reduction_period' => $reductionPeriod,
                    'reduction_perc_additional' => $percAdditional,
                    'reduction_perc_marketing' => $percMarketing
                ],
                'priority_rules' => [
                    'priority' => $priceinfo['Priority'] ?? 'No',
                    'priority_eb' => $priceinfo['PriorityEB'] ?? 'No',
                    'priority_ext' => $priceinfo['PriorityEXT'] ?? 'No',
                    'scenarios' => $finalPrice['scenarios'] ?? []
                ],
                'applied_discount' => $finalPrice['applied_discount'],
                'discount_amount' => round(
                    ($finalPrice['discount_amount'] ?? 0) +
                    ($percMarketing['applicable'] ? $percMarketing['discount'] : 0) +
                    ($percAdditional['applicable'] ? $percAdditional['discount'] : 0),
                    2
                )
            ],
            'room_capacity' => $roomCapacity,
            'child_age_bands' => $this->parser->getChildAgeBands(),
            'seasons_by_night' => $seasonsByNight,
            'occupancy' => $occupancy,
            'params' => $params,
            'debug_log' => $this->debug ? $this->debugLog : null
        ];

        $this->log('=== PRICE CALCULATION END ===', $result);

        return $result;
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
     * @return list<string>
     */
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * Verify season-to-price correlation (debug helper)
     *
     * Delegates to PriceInfoFormatter. The priceinfo must be loaded first
     * (either via calculate() or by setting it directly on the parser).
     * @return array<string, mixed>
     */
    public function verifySeasonPriceMapping(string $checkIn, int $nights): array
    {
        return PriceInfoFormatter::verifySeasonPriceMapping(
            $this->parser->getPriceinfo() ?? [],
            $checkIn,
            $nights
        );
    }

    /**
     * Get sample prices for verification (debug helper)
     * @return array<string, mixed>
     */
    public function getSamplePrices(string $roomId, string $boardId): array
    {
        return PriceInfoFormatter::getSamplePrices(
            $this->parser->getPriceinfo() ?? [],
            $roomId,
            $boardId
        );
    }

    /**
     * Collect distinct IdAge values from season_price for a room/board.
     *
     * Used by the handling-fee correlation logic: handling_fee entries are
     * only considered when their IdAge matches an age type present in the
     * season_price for the booked room.
     * @return array<string, mixed>
     */
    public function collectSeasonPriceAgeTypes(string $roomId, string $boardId): array
    {
        return $this->feeCalculator->collectSeasonPriceAgeTypes($roomId, $boardId);
    }

    /**
     * Get the parser instance (for direct priceinfo access by debug tools)
     */
    public function getParser(): PriceInfoParser
    {
        return $this->parser;
    }

    /**
     * Log debug message
     */
    public function log(string $message, mixed $data = null): void
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
}