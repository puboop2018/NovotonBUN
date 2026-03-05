<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Calculator
 *
 * Applies pricing formulas: base price, fees, discounts, reductions,
 * priority rules, and commission.
 *
 * Extracted from PriceInfoCalculation to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class PriceInfoCalculator
{
    /** @var float Commission percentage */
    private $commission;

    /** @var PriceInfoParser */
    private $parser;

    /** @var callable|null Logger function */
    private $logger;

    public function __construct(PriceInfoParser $parser, float $commission, ?callable $logger = null)
    {
        $this->parser = $parser;
        $this->commission = max(0.0, $commission);
        $this->logger = $logger;
    }

    /**
     * Calculate base price from season_price rows
     */
    public function calculateBasePrice(array $occupancy, array $seasonsByNight, string $roomId, string $boardId, int $nights): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $total = 0;
        $byNight = [];
        $byPerson = [];

        foreach ($seasonsByNight as $nightIdx => $nightInfo) {
            $nightTotal = 0;
            $seasonNum = $nightInfo['season'];
            $priceKey = 'Price' . $seasonNum;

            $roomPriceCharged = false;

            // Adults
            foreach ($occupancy['adults'] as $adult) {
                $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $adult['age_type'], $adult['acc_type'], $nights);

                if ($row) {
                    $isRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';
                    $isExtraBed = stripos($adult['acc_type'], 'EXTRA') !== false;

                    // RoomPrice dedup: only skip regular-bed occupants covered by
                    // the per-room charge.  Extra-bed guests always have their own
                    // supplement and must never be blocked.
                    if ($isRoomPrice && !$isExtraBed) {
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
                $row = null;
                if ($child['by_1_ad']) {
                    $ageTypeBy1Ad = $child['age_type'] . ' BY 1 AD';
                    $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $ageTypeBy1Ad, $child['acc_type'], $nights);

                    if (!$row) {
                        $ageTypeBy1AdLower = $child['age_type'] . ' by 1 ad';
                        $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $ageTypeBy1AdLower, $child['acc_type'], $nights);
                    }
                }

                if (!$row) {
                    $row = $this->findSeasonPriceRow($seasonPrices, $roomId, $boardId, $child['age_type'], $child['acc_type'], $nights);
                }

                if ($row) {
                    $childRoomPrice = ($row['RoomPrice'] ?? 'No') === 'Yes';
                    $isExtraBed = stripos($child['acc_type'], 'EXTRA') !== false;

                    // Same RoomPrice dedup logic: only skip regular-bed children
                    // (rare — a child occupying a regular bed in the room).
                    // Extra-bed children always have their own supplement.
                    if ($childRoomPrice && !$isExtraBed) {
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
    public function findBaseCodeRow(array $seasonPrices, string $roomId, string $boardId): ?array
    {
        foreach ($seasonPrices as $row) {
            $rowRoom = is_string($row['IdRoom'] ?? '') ? ($row['IdRoom'] ?? '') : '';
            $rowBoard = is_string($row['IdBoard'] ?? '') ? ($row['IdBoard'] ?? '') : '';
            $code = is_string($row['Code'] ?? '') ? ($row['Code'] ?? '') : '';

            if (PriceInfoFormatter::matchRoom($rowRoom, $roomId) && PriceInfoFormatter::matchBoard($rowBoard, $boardId) && $code === 'Base') {
                return $row;
            }
        }
        return null;
    }

    /**
     * Find season_price row matching criteria
     *
     * Picks the most specific match (largest FromDays value).
     */
    public function findSeasonPriceRow(array $seasonPrices, string $roomId, string $boardId, string $ageType, string $accType, int $nights): ?array
    {
        static $ageTypeMap = [
            '1' => 'ADULT',
            '2' => 'CHD 0-1.99',
            '3' => 'CHD 2-11.99',
            '4' => 'CHD 12-17.99',
        ];

        $candidates = [];

        foreach ($seasonPrices as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');
            $rowAcc = PriceInfoFormatter::toScalar($row['IdAcc'] ?? '');
            $rowAge = '';
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $rowAge = $row['fAge'];
            } else {
                $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
                $rowAge = $ageTypeMap[$rawIdAge] ?? $rawIdAge;
            }

            if ($rowAcc === '') {
                $rowAcc = 'REGULAR';
            }

            $rawFromDays = PriceInfoFormatter::toScalar($row['FromDays'] ?? '');
            $rawToDays = PriceInfoFormatter::toScalar($row['ToDays'] ?? '');
            $fromDays = ($rawFromDays !== '') ? (int) (preg_replace('/\D+/', '', $rawFromDays) ?: '1') : 1;
            $toDays = ($rawToDays !== '') ? (int) (preg_replace('/\D+/', '', $rawToDays) ?: '9999') : 9999;
            if ($fromDays <= 0) $fromDays = 1;
            if ($toDays <= 0) $toDays = 9999;

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) continue;
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) continue;
            if (!PriceInfoFormatter::matchAgeType($rowAge, $ageType)) continue;
            if (!PriceInfoFormatter::matchAccType($rowAcc, $accType)) continue;

            if ($nights < $fromDays || $nights > $toDays) continue;

            $candidates[] = ['row' => $row, 'fromDays' => $fromDays];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function($a, $b) {
            return $b['fromDays'] <=> $a['fromDays'];
        });

        return $candidates[0]['row'];
    }

    /**
     * Get price from row, handling percentages via recursive Code/Base resolution
     */
    public function getPriceFromRow(array $row, string $priceKey): float
    {
        $visited = [];
        return $this->resolvePrice($row, $priceKey, $visited);
    }

    /**
     * Recursively resolve price from a season_price row
     */
    private function resolvePrice(array $row, string $priceKey, array &$visited): float
    {
        $code = PriceInfoFormatter::toScalar($row['Code'] ?? '');
        $memoKey = $code . ':' . $priceKey;

        if (isset($visited[$memoKey])) return 0;
        $visited[$memoKey] = true;

        $rawPrice = $row[$priceKey] ?? $row['Price1'] ?? 0;

        if (is_array($rawPrice) || is_object($rawPrice)) {
            return 0;
        }

        if (is_string($rawPrice) && strpos($rawPrice, '%') !== false) {
            $percentValue = (float) str_replace('%', '', $rawPrice);
            $baseRef = PriceInfoFormatter::toScalar($row['Base'] ?? '');
            $codeIndex = $this->parser->getCodeIndex();

            if ($baseRef !== '' && isset($codeIndex[$baseRef])) {
                $baseRow = $codeIndex[$baseRef][0];
                $basePrice = $this->resolvePrice($baseRow, $priceKey, $visited);
                return round($basePrice * ($percentValue / 100), 4);
            }
            return 0;
        }

        return (float) $rawPrice;
    }

    /**
     * Calculate fees (extras_daily, handling_fee, company_fee, etc.)
     */
    public function calculateFees(array $occupancy, string $checkIn, int $nights, string $roomId, string $boardId): array
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

        $fees['extras_daily'] = $this->calculateExtrasDaily($occupancy, $checkIn, $nights);
        if ($fees['extras_daily'] > 0) {
            $fees['details'][] = ['type' => 'extras_daily', 'amount' => $fees['extras_daily']];
        }

        $fees['handling_fee'] = $this->calculateHandlingFee($occupancy, $checkIn, $nights);
        if ($fees['handling_fee'] > 0) {
            $fees['details'][] = ['type' => 'handling_fee', 'amount' => $fees['handling_fee']];
        }

        $fees['extras_single'] = $this->calculateExtrasSingle($occupancy, $checkIn, $nights, $roomId);
        if ($fees['extras_single'] > 0) {
            $fees['details'][] = ['type' => 'extras_single', 'amount' => $fees['extras_single']];
        }

        $fees['extras_rooms'] = $this->calculateExtrasRooms($occupancy, $checkIn, $nights, $roomId);
        if ($fees['extras_rooms'] > 0) {
            $fees['details'][] = ['type' => 'extras_rooms', 'amount' => $fees['extras_rooms']];
        }

        $fees['extras_board'] = $this->calculateExtrasBoard($occupancy, $checkIn, $nights, $boardId);
        if ($fees['extras_board'] > 0) {
            $fees['details'][] = ['type' => 'extras_board', 'amount' => $fees['extras_board']];
        }

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
        $priceinfo = $this->parser->getPriceinfo();
        $extrasDaily = $priceinfo['extras_daily'] ?? [];
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
            $idAge = PriceInfoFormatter::toScalar($extra['IdAge'] ?? '');
            $price = (float) ($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day';

            if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                continue;
            }

            $feeIdAge = PriceInfoFormatter::feeKey($idAge);
            $count = PriceInfoFormatter::countMatchingPersons($occupancy, $feeIdAge);

            if ($count > 0) {
                if ($type === 'Arrival') {
                    if ($checkIn >= $fromDate && $checkIn <= $toDate) {
                        $total += $price * $count * $nights;
                    }
                } elseif ($type === 'Stay') {
                    $overlappingNights = PriceInfoFormatter::countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $count * $overlappingNights;
                } else {
                    $overlappingNights = PriceInfoFormatter::countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $count * $overlappingNights;
                }
            }
        }

        return $total;
    }

    /**
     * Calculate handling_fee
     */
    private function calculateHandlingFee(array $occupancy, string $checkIn, int $nights): float
    {
        $priceinfo = $this->parser->getPriceinfo();
        $handlingFees = $priceinfo['handling_fee'] ?? [];
        if (empty($handlingFees)) return 0;

        if (isset($handlingFees['Price1']) || isset($handlingFees['ToDays'])) {
            $handlingFees = [$handlingFees];
        }

        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        $total = 0;
        $entryDetails = [];

        foreach ($handlingFees as $idx => $fee) {
            $fromDate = PriceInfoFormatter::toScalar($fee['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($fee['ToDate'] ?? '');
            $idAge = PriceInfoFormatter::toScalar($fee['IdAge'] ?? '');
            $rawToDays = PriceInfoFormatter::toScalar($fee['ToDays'] ?? '');
            $rawFromDays = PriceInfoFormatter::toScalar($fee['FromDays'] ?? '');
            $toDays = ($rawToDays !== '') ? (int) $rawToDays : 3;
            $fromDays = ($rawFromDays !== '') ? (int) $rawFromDays : 4;
            $price1 = (float) PriceInfoFormatter::toScalar($fee['Price1'] ?? '0');
            $price2 = (float) PriceInfoFormatter::toScalar($fee['Price2'] ?? '0');

            if (!empty($fromDate) && !empty($toDate)) {
                if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    $entryDetails[] = ['entry' => $idx, 'idAge' => $idAge, 'skipped' => 'date_range', 'fromDate' => $fromDate, 'toDate' => $toDate];
                    continue;
                }
            }

            $price = 0;
            $tierUsed = '';
            if ($nights <= $toDays) {
                $price = $price1;
                $tierUsed = "Price1 (nights={$nights} <= toDays={$toDays})";
            } elseif ($nights >= $fromDays) {
                $price = $price2;
                $tierUsed = "Price2 (nights={$nights} >= fromDays={$fromDays})";
            } else {
                $price = $price1;
                $tierUsed = "Price1 (fallback, nights={$nights} between toDays={$toDays} and fromDays={$fromDays})";
            }

            $count = 1;
            $countMethod = '';
            $feeIdAge = PriceInfoFormatter::feeKey($idAge);
            if (!empty($feeIdAge)) {
                $count = PriceInfoFormatter::countMatchingPersons($occupancy, $feeIdAge);
                $countMethod = "matched '{$feeIdAge}'";
            } else {
                $count = count($occupancy['adults']) + count($occupancy['children']);
                $countMethod = 'all_persons (empty IdAge)';
            }

            $entryTotal = $price * $count;
            $total += $entryTotal;

            $entryDetails[] = [
                'entry' => $idx,
                'idAge' => $idAge,
                'feeKey' => $feeIdAge,
                'tier' => $tierUsed,
                'price' => $price,
                'price1' => $price1,
                'price2' => $price2,
                'count' => $count,
                'count_method' => $countMethod,
                'subtotal' => $entryTotal,
                'fromDate' => $fromDate,
                'toDate' => $toDate,
            ];
        }

        $this->log('Handling fee entries', $entryDetails);

        return $total;
    }

    /**
     * Calculate extras_single (single supplement)
     */
    private function calculateExtrasSingle(array $occupancy, string $checkIn, int $nights, string $roomId = ''): float
    {
        $priceinfo = $this->parser->getPriceinfo();
        $extrasSingle = $priceinfo['extras_single'] ?? [];
        if (empty($extrasSingle)) return 0;

        if (count($occupancy['adults']) !== 1) return 0;

        if (!empty($roomId) && stripos($roomId, 'SGL') !== false) {
            return 0;
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
            $price = (float) ($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Stay';
            $idRoom = $extra['IdRoom'] ?? '';

            if (!empty($idRoom) && !empty($roomId)) {
                if (!PriceInfoFormatter::matchRoom($idRoom, $roomId)) {
                    continue;
                }
            }

            if (!empty($fromDate) && !empty($toDate)) {
                if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            if ($type === 'Day' || $type === 'Night') {
                if (!empty($fromDate) && !empty($toDate)) {
                    $overlappingNights = PriceInfoFormatter::countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $overlappingNights;
                } else {
                    $total += $price * $nights;
                }
            } else {
                $total += $price;
            }
        }

        return $total;
    }

    /**
     * Calculate extras_rooms
     */
    private function calculateExtrasRooms(array $occupancy, string $checkIn, int $nights, string $roomId = ''): float
    {
        $priceinfo = $this->parser->getPriceinfo();
        $extrasRooms = $priceinfo['extras_rooms'] ?? [];
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
            $price = (float) ($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day';
            $idRoom = $extra['IdRoom'] ?? '';

            if (!empty($idRoom) && !empty($roomId)) {
                if (!PriceInfoFormatter::matchRoom($idRoom, $roomId)) {
                    continue;
                }
            }

            if (!empty($fromDate) && !empty($toDate)) {
                if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            if ($type === 'Stay') {
                $total += $price;
            } elseif ($type === 'Night' || $type === 'Day') {
                if (!empty($fromDate) && !empty($toDate)) {
                    $overlappingNights = PriceInfoFormatter::countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $overlappingNights;
                } else {
                    $total += $price * $nights;
                }
            } else {
                $total += $price;
            }
        }

        return $total;
    }

    /**
     * Calculate extras_board
     */
    private function calculateExtrasBoard(array $occupancy, string $checkIn, int $nights, string $boardId): float
    {
        // No board supplement when booking the base board (empty boardId).
        // extras_board entries are supplements for board upgrades (e.g. HB, ALL INCL).
        // The base board is already included in the room/person base price.
        if (empty($boardId)) return 0;

        $priceinfo = $this->parser->getPriceinfo();
        $extrasBoard = $priceinfo['extras_board'] ?? [];
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
            $price = (float) ($extra['Price'] ?? 0);
            $type = $extra['Type'] ?? 'Day';
            $idBoard = $extra['IdBoard'] ?? '';
            $idAge = $extra['IdAge'] ?? '';

            if (!empty($idBoard) && !empty($boardId)) {
                if (strcasecmp($idBoard, $boardId) !== 0) {
                    continue;
                }
            }

            if (!empty($fromDate) && !empty($toDate)) {
                if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate->format('Y-m-d'), $fromDate, $toDate)) {
                    continue;
                }
            }

            $personCount = 1;
            if (!empty($idAge)) {
                $personCount = PriceInfoFormatter::countMatchingPersons($occupancy, $idAge);
                if ($personCount === 0) continue;
            } else {
                $personCount = count($occupancy['adults']) + count($occupancy['children']);
            }

            if ($type === 'Stay') {
                $total += $price * $personCount;
            } elseif ($type === 'Night' || $type === 'Day') {
                if (!empty($fromDate) && !empty($toDate)) {
                    $overlappingNights = PriceInfoFormatter::countOverlappingNights($checkIn, $nights, $fromDate, $toDate);
                    $total += $price * $personCount * $overlappingNights;
                } else {
                    $total += $price * $personCount * $nights;
                }
            } else {
                $total += $price * $personCount;
            }
        }

        return $total;
    }

    /**
     * Calculate company_fee (per room)
     */
    private function calculateCompanyFee(string $roomId): float
    {
        $priceinfo = $this->parser->getPriceinfo();
        $companyFees = $priceinfo['company_fee'] ?? [];
        if (empty($companyFees)) return 0;

        if (isset($companyFees['Price']) || isset($companyFees['IdRoom'])) {
            $companyFees = [$companyFees];
        }

        foreach ($companyFees as $fee) {
            $feeRoomId = $fee['IdRoom'] ?? '';
            $price = (float) ($fee['Price'] ?? 0);

            if (empty($feeRoomId) || PriceInfoFormatter::matchRoom($feeRoomId, $roomId)) {
                return $price;
            }
        }

        return 0;
    }

    /**
     * Calculate Early Booking discount
     */
    public function calculateEarlyBookingDiscount(string $bookingDate, string $checkIn, int $nights, array $basePrice, array $fees): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $ebData = $priceinfo['EB'] ?? $priceinfo['early_booking'] ?? [];
        if (empty($ebData)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'discount_breakdown' => []];
        }

        if (isset($ebData['Discount']) || isset($ebData['Reduction'])) {
            $ebData = [$ebData];
        }

        $ebToDaily = ($priceinfo['EBToDaily'] ?? 'No') === 'Yes';
        $ebToRooms = ($priceinfo['EBToRooms'] ?? 'No') === 'Yes';
        $ebToBoard = ($priceinfo['EBToBoard'] ?? 'No') === 'Yes';

        $bestDiscount = 0;
        $bestPercent = 0;
        $bestBreakdown = [];
        $applicable = false;

        foreach ($ebData as $eb) {
            $bookFrom = $eb['BookingFrom'] ?? $eb['BookFrom'] ?? '';
            $bookTo = $eb['BookingTo'] ?? $eb['BookTo'] ?? '';
            $travelFrom = $eb['TravelTimeFrom'] ?? $eb['StayFrom'] ?? '';
            $travelTo = $eb['TravelTimeTo'] ?? $eb['StayTo'] ?? '';
            $discount = (float) ($eb['Discount'] ?? $eb['Reduction'] ?? 0);
            $minStay = (int) ($eb['MinimumStay'] ?? $eb['MinStay'] ?? 0);

            if (!empty($bookFrom) && $bookingDate < $bookFrom) {
                continue;
            }
            if (!empty($bookTo) && $bookingDate > $bookTo) {
                continue;
            }

            if (!empty($travelFrom) && $checkIn < $travelFrom) {
                continue;
            }
            if (!empty($travelTo) && $checkIn > $travelTo) {
                continue;
            }

            if ($minStay > 0 && $nights < $minStay) {
                continue;
            }

            $applicable = true;
            if ($discount > $bestPercent) {
                $bestPercent = $discount;
                $discountRate = $discount / 100;

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
     */
    public function calculateReduction(string $checkIn, int $nights, array $seasonsByNight, array $occupancy, string $roomId, string $boardId, array $basePrice = [], array $fees = []): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $reductions = $priceinfo['reduction'] ?? [];
        if (empty($reductions)) {
            return ['applicable' => false, 'discount' => 0, 'free_nights' => 0, 'free_night_indices' => [], 'discount_breakdown' => []];
        }

        if (isset($reductions['FreeNights'])) {
            $reductions = [$reductions];
        }

        $extToDaily = ($priceinfo['EXTToDaily'] ?? 'No') === 'Yes';
        $extToRooms = ($priceinfo['EXTToRooms'] ?? 'No') === 'Yes';
        $extToBoard = ($priceinfo['EXTToBoard'] ?? 'No') === 'Yes';

        $bestDiscount = 0;
        $bestFreeNights = 0;
        $bestFreeNightIndices = [];
        $bestBreakdown = [];
        $applicable = false;

        foreach ($reductions as $red) {
            $fromNights = (int) ($red['FromNights'] ?? 0);
            $toNights = (int) ($red['ToNights'] ?? 999);
            $checkInFrom = $red['CheckInFrom'] ?? '';
            $checkInTo = $red['CheckInTo'] ?? '';
            $freeNights = (int) ($red['FreeNights'] ?? 0);
            $type = $red['Type'] ?? 'End';
            $validFor = PriceInfoFormatter::toScalar($red['ValidFor'] ?? '');

            if ($nights < $fromNights || $nights > $toNights) {
                continue;
            }

            if (!empty($checkInFrom) && !empty($checkInTo)) {
                if (strcasecmp($validFor, 'Stay') === 0) {
                    // 'Stay': the stay period must overlap with the CheckIn range
                    $checkOutDate = date('Y-m-d', strtotime($checkIn . ' + ' . $nights . ' days'));
                    if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate, $checkInFrom, $checkInTo)) {
                        continue;
                    }
                } else {
                    // 'Arrival' (default): check-in date must fall within range
                    if ($checkIn < $checkInFrom || $checkIn > $checkInTo) {
                        continue;
                    }
                }
            }

            if ($freeNights <= 0) {
                continue;
            }

            $applicable = true;

            $freeNightIndices = [];
            if ($type === 'End') {
                for ($i = $nights - $freeNights; $i < $nights; $i++) {
                    if ($i >= 0) $freeNightIndices[] = $i;
                }
            } else {
                for ($i = 0; $i < $freeNights && $i < $nights; $i++) {
                    $freeNightIndices[] = $i;
                }
            }

            $basePriceDiscount = 0;
            if (!empty($basePrice['by_night'])) {
                foreach ($freeNightIndices as $nightIdx) {
                    if (isset($basePrice['by_night'][$nightIdx])) {
                        $basePriceDiscount += $basePrice['by_night'][$nightIdx]['price'] ?? 0;
                    }
                }
            } else {
                $avgNightPrice = $nights > 0 ? $basePrice['total'] / $nights : 0;
                $basePriceDiscount = $avgNightPrice * count($freeNightIndices);
            }

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
     * Calculate reduction_period (MaxDays cap)
     *
     * If the stay length falls between FromDays and ToDays, and overlaps the
     * FromDate-ToDate range, the guest only pays for MaxDays nights instead
     * of the actual nights. The discount is the value of the excess nights.
     */
    public function calculateReductionPeriod(string $checkIn, int $nights, array $basePrice): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_period'] ?? [];
        if (empty($entries)) {
            return ['applicable' => false, 'discount' => 0, 'max_days' => 0, 'capped_nights' => 0];
        }

        if (isset($entries['FromDays'])) {
            $entries = [$entries];
        }

        $checkOut = date('Y-m-d', strtotime($checkIn . ' + ' . $nights . ' days'));

        foreach ($entries as $entry) {
            $fromDays = (int) ($entry['FromDays'] ?? 0);
            $toDays = (int) ($entry['ToDays'] ?? 999);
            $maxDays = (int) ($entry['MaxDays'] ?? 0);
            $fromDate = PriceInfoFormatter::toScalar($entry['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($entry['ToDate'] ?? '');

            if ($maxDays <= 0 || $maxDays >= $nights) {
                continue;
            }

            if ($nights < $fromDays || $nights > $toDays) {
                continue;
            }

            if (!empty($fromDate) && !empty($toDate)) {
                if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOut, $fromDate, $toDate)) {
                    continue;
                }
            }

            // Calculate discount: sum of nights beyond MaxDays
            $discount = 0;
            if (!empty($basePrice['by_night'])) {
                // Remove the most expensive excess nights (from the end by default)
                $excessCount = $nights - $maxDays;
                for ($i = $nights - $excessCount; $i < $nights; $i++) {
                    if (isset($basePrice['by_night'][$i])) {
                        $discount += $basePrice['by_night'][$i]['price'] ?? 0;
                    }
                }
            } else {
                $avgNightPrice = $nights > 0 ? $basePrice['total'] / $nights : 0;
                $discount = $avgNightPrice * ($nights - $maxDays);
            }

            $this->log('reduction_period', [
                'from_days' => $fromDays,
                'to_days' => $toDays,
                'max_days' => $maxDays,
                'nights' => $nights,
                'discount' => $discount
            ]);

            return [
                'applicable' => true,
                'discount' => $discount,
                'max_days' => $maxDays,
                'capped_nights' => $nights - $maxDays
            ];
        }

        return ['applicable' => false, 'discount' => 0, 'max_days' => 0, 'capped_nights' => 0];
    }

    /**
     * Calculate reduction_perc_additional (percentage promo discount)
     *
     * Applied as a flat percentage off the subtotal (base + fees - EB/reduction).
     * Per the API spec: reduction_perc_additional has Perc and Name fields.
     */
    public function calculateReductionPercAdditional(float $subtotal): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_perc_additional'] ?? [];
        if (empty($entries)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'name' => ''];
        }

        if (isset($entries['Perc'])) {
            $entries = [$entries];
        }

        $totalPercent = 0;
        $names = [];

        foreach ($entries as $entry) {
            $perc = (float) ($entry['Perc'] ?? 0);
            $name = PriceInfoFormatter::toScalar($entry['Name'] ?? '');

            if ($perc <= 0) {
                continue;
            }

            $totalPercent += $perc;
            if (!empty($name)) {
                $names[] = $name;
            }
        }

        if ($totalPercent <= 0) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'name' => ''];
        }

        $discount = $subtotal * ($totalPercent / 100);

        $this->log('reduction_perc_additional', [
            'percent' => $totalPercent,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'names' => $names
        ]);

        return [
            'applicable' => true,
            'discount' => $discount,
            'percent' => $totalPercent,
            'name' => implode(', ', $names)
        ];
    }

    /**
     * Calculate reduction_perc_marketing (marketing percentage discount)
     *
     * More complex than reduction_perc_additional: supports date restrictions
     * (BookingFrom/To, TravelTimeFrom/To), room type filtering, minimum stay,
     * and Type (Stay/Arrival).
     */
    public function calculateReductionPercMarketing(
        string $bookingDate,
        string $checkIn,
        int $nights,
        string $roomId,
        float $subtotal
    ): array {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_perc_marketing'] ?? [];
        if (empty($entries)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'is_surcharge' => false, 'name' => '', 'details' => []];
        }

        if (isset($entries['Perc'])) {
            $entries = [$entries];
        }

        $bestPercent = 0;
        $bestName = '';
        $applicable = false;
        $details = [];

        foreach ($entries as $entry) {
            $perc = (float) ($entry['Perc'] ?? 0);
            $name = PriceInfoFormatter::toScalar($entry['Name'] ?? '');
            $bookFrom = PriceInfoFormatter::toScalar($entry['BookingFrom'] ?? '');
            $bookTo = PriceInfoFormatter::toScalar($entry['BookingTo'] ?? '');
            $travelFrom = PriceInfoFormatter::toScalar($entry['TravelTimeFrom'] ?? '');
            $travelTo = PriceInfoFormatter::toScalar($entry['TravelTimeTo'] ?? '');
            $roomTypes = PriceInfoFormatter::toScalar($entry['RoomTypes'] ?? '');
            $minStay = (int) ($entry['MinimumStay'] ?? 0);
            $type = PriceInfoFormatter::toScalar($entry['Type'] ?? '');

            // Skip zero values; negative values are treated as surcharges
            if ($perc == 0) {
                continue;
            }

            // Check booking date range
            if (!empty($bookFrom) && $bookingDate < $bookFrom) {
                continue;
            }
            if (!empty($bookTo) && $bookingDate > $bookTo) {
                continue;
            }

            // Check travel time range (check-in must fall within)
            if (!empty($travelFrom) && $checkIn < $travelFrom) {
                continue;
            }
            if (!empty($travelTo) && $checkIn > $travelTo) {
                continue;
            }

            // Check room type restriction
            if (!empty($roomTypes) && !empty($roomId)) {
                $allowedRooms = array_map('trim', explode(',', $roomTypes));
                $roomMatch = false;
                foreach ($allowedRooms as $allowed) {
                    if (PriceInfoFormatter::matchRoom($allowed, $roomId)) {
                        $roomMatch = true;
                        break;
                    }
                }
                if (!$roomMatch) {
                    continue;
                }
            }

            // Check minimum stay
            if ($minStay > 0 && $nights < $minStay) {
                continue;
            }

            // Type handling: 'Arrival' means discount only if check-in falls in travel period
            // 'Stay' means the entire stay must overlap (already handled by travelFrom/To above)
            // Both are effectively handled by the date checks above

            $applicable = true;

            // For discounts (positive): pick highest percentage
            // For surcharges (negative): pick most negative (largest surcharge)
            // Discounts take priority over surcharges
            $isBestDiscount = ($bestPercent > 0);
            $isCurrentDiscount = ($perc > 0);

            if ($isCurrentDiscount && (!$isBestDiscount || $perc > $bestPercent)) {
                // Current is a discount and either best was a surcharge or current is better
                $bestPercent = $perc;
                $bestName = $name;
            } elseif (!$isCurrentDiscount && !$isBestDiscount && $perc < $bestPercent) {
                // Both are surcharges: pick the most negative (largest surcharge)
                $bestPercent = $perc;
                $bestName = $name;
            }

            $details[] = [
                'name' => $name,
                'percent' => $perc,
                'booking_range' => $bookFrom . ' - ' . $bookTo,
                'travel_range' => $travelFrom . ' - ' . $travelTo,
                'room_types' => $roomTypes,
                'min_stay' => $minStay,
                'type' => $type,
                'matched' => true
            ];
        }

        if (!$applicable) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'is_surcharge' => false, 'name' => '', 'details' => $details];
        }

        $discount = $subtotal * ($bestPercent / 100);
        $isSurcharge = $bestPercent < 0;

        $this->log('reduction_perc_marketing', [
            'best_percent' => $bestPercent,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'is_surcharge' => $isSurcharge,
            'name' => $bestName,
            'details' => $details
        ]);

        return [
            'applicable' => $applicable,
            'discount' => $discount,
            'percent' => $bestPercent,
            'is_surcharge' => $isSurcharge,
            'name' => $bestName,
            'details' => $details
        ];
    }

    /**
     * Apply priority rules to select best discount scenario
     */
    public function applyPriorityRules(array $basePrice, array $fees, array $ebDiscount, array $reduction, array $reductionPeriod = []): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $priority = $priceinfo['Priority'] ?? 'No';
        $priorityEB = $priceinfo['PriorityEB'] ?? 'No';
        $priorityEXT = $priceinfo['PriorityEXT'] ?? 'No';

        $basePlusFees = $basePrice['total'] + $fees['total'];

        // Apply reduction_period (MaxDays cap) to the base if applicable
        $reductionPeriodDiscount = ($reductionPeriod['applicable'] ?? false) ? ($reductionPeriod['discount'] ?? 0) : 0;
        $basePlusFees -= $reductionPeriodDiscount;

        $totalNone = $basePlusFees;
        $totalEB = $ebDiscount['applicable'] ? ($basePlusFees - $ebDiscount['discount']) : $basePlusFees;
        $totalReduction = $reduction['applicable'] ? ($basePlusFees - $reduction['discount']) : $basePlusFees;

        $totalCombined = $basePlusFees;
        if ($ebDiscount['applicable']) $totalCombined -= $ebDiscount['discount'];
        if ($reduction['applicable']) $totalCombined -= $reduction['discount'];

        $appliedDiscount = 'none';
        $discountAmount = $reductionPeriodDiscount;
        $finalTotal = $totalNone;

        if ($priority === 'Yes') {
            if ($priorityEB === 'Yes' && $ebDiscount['applicable']) {
                $finalTotal = $totalEB;
                $appliedDiscount = 'early_booking';
                $discountAmount += $ebDiscount['discount'];
            } elseif ($priorityEXT === 'Yes' && $reduction['applicable']) {
                $finalTotal = $totalReduction;
                $appliedDiscount = 'reduction';
                $discountAmount += $reduction['discount'];
            } else {
                if ($totalEB <= $totalReduction && $ebDiscount['applicable']) {
                    $finalTotal = $totalEB;
                    $appliedDiscount = 'early_booking';
                    $discountAmount += $ebDiscount['discount'];
                } elseif ($reduction['applicable']) {
                    $finalTotal = $totalReduction;
                    $appliedDiscount = 'reduction';
                    $discountAmount += $reduction['discount'];
                }
            }
        } else {
            if ($ebDiscount['applicable'] || $reduction['applicable']) {
                $finalTotal = $totalCombined;
                $appliedDiscount = 'combined';
                $discountAmount += ($ebDiscount['applicable'] ? $ebDiscount['discount'] : 0) +
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
                'combined' => $totalCombined,
                'reduction_period_discount' => $reductionPeriodDiscount
            ]
        ];
    }

    /**
     * Apply commission to price
     */
    public function applyCommission(float $price): float
    {
        if ($this->commission <= 0) {
            return $price;
        }
        return $price * (1 + ($this->commission / 100));
    }

    private function log(string $message, $data = null): void
    {
        if ($this->logger) {
            ($this->logger)($message, $data);
        }
    }
}
