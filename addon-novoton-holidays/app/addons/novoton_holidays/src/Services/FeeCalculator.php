<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Fee Calculator
 *
 * Calculates supplementary fees: extras_daily, handling_fee, extras_single,
 * extras_rooms, extras_board, and company_fee.
 *
 * Extracted from PriceInfoCalculator to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class FeeCalculator
{
    /** @var PriceInfoParser */
    private $parser;

    /** @var callable|null Logger function */
    private $logger;

    public function __construct(PriceInfoParser $parser, ?callable $logger = null)
    {
        $this->parser = $parser;
        $this->logger = $logger;
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

        // Collect the set of distinct IdAge values present in season_price for
        // this room/board.  Fee entries (extras_daily, handling_fee) are only
        // considered when their IdAge correlates with an age type that actually
        // exists in season_price for the booked room (room-specific correlation).
        $seasonAgeTypes = $this->collectSeasonPriceAgeTypes($roomId, $boardId);

        $fees['extras_daily'] = $this->calculateExtrasDaily($occupancy, $checkIn, $nights, $seasonAgeTypes);
        if ($fees['extras_daily'] > 0) {
            $fees['details'][] = ['type' => 'extras_daily', 'amount' => $fees['extras_daily']];
        }

        $handlingResult = $this->calculateHandlingFee($occupancy, $checkIn, $nights, $seasonAgeTypes);
        $fees['handling_fee'] = $handlingResult['total'];
        $fees['handling_fee_entries'] = $handlingResult['entries'];
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
     * Collect the distinct resolved IdAge values from season_price rows
     * that match the given room and board.
     *
     * @return array<string> Upper-cased, trimmed age-type strings (e.g. "ADULT", "3 RD ADULT", "CHD 2-11.99")
     */
    public function collectSeasonPriceAgeTypes(string $roomId, string $boardId): array
    {
        static $ageTypeMap = [
            '1' => 'ADULT',
            '2' => 'CHD 0-1.99',
            '3' => 'CHD 2-11.99',
            '4' => 'CHD 12-17.99',
        ];

        $priceinfo = $this->parser->getPriceinfo();
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $ageTypes = [];
        foreach ($seasonPrices as $row) {
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) continue;
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) continue;

            $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $resolvedAge = $row['fAge'];
            } else {
                $resolvedAge = $ageTypeMap[$rawIdAge] ?? $rawIdAge;
            }
            $resolvedAge = strtoupper(trim(preg_replace('/\s+/', ' ', $resolvedAge)));
            if ($resolvedAge !== '') {
                $ageTypes[$resolvedAge] = true;
            }
        }

        return array_keys($ageTypes);
    }

    /**
     * Calculate extras_daily fees
     *
     * @param array $seasonAgeTypes Resolved IdAge values from season_price for the booked room/board.
     *                              Entries whose IdAge does not correlate are skipped.
     */
    private function calculateExtrasDaily(array $occupancy, string $checkIn, int $nights, array $seasonAgeTypes = []): float
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

            // Season-price correlation: skip extras_daily entries whose IdAge
            // does not exist in the season_price for the booked room.
            if (!empty($feeIdAge) && !empty($seasonAgeTypes)) {
                if (!PriceInfoFormatter::correlatesWithSeasonAgeTypes($feeIdAge, $seasonAgeTypes)) {
                    continue;
                }
            }

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
     *
     * @param array $seasonAgeTypes Resolved IdAge values from season_price for the booked room/board.
     *                              Only handling_fee entries whose IdAge correlates with one of these
     *                              are considered.
     */
    private function calculateHandlingFee(array $occupancy, string $checkIn, int $nights, array $seasonAgeTypes = []): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $handlingFees = $priceinfo['handling_fee'] ?? [];
        if (empty($handlingFees)) return ['total' => 0, 'entries' => []];

        if (isset($handlingFees['Price1']) || isset($handlingFees['ToDays'])) {
            $handlingFees = [$handlingFees];
        }

        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        $total = 0;
        $entryDetails = [];
        $checkOutStr = $checkOutDate->format('Y-m-d');

        // Build a lookup set of season_price age types for fast matching.
        // Handling-fee entries are only considered when their IdAge correlates
        // with an age type present in the season_price for the booked room.
        $seasonAgeSet = array_map('strtoupper', array_map('trim', $seasonAgeTypes));

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
                if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutStr, $fromDate, $toDate)) {
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
                if (!empty($seasonAgeSet)) {
                    $correlates = PriceInfoFormatter::correlatesWithSeasonAgeTypes($feeIdAge, $seasonAgeSet);
                    if (!$correlates) {
                        $entryDetails[] = [
                            'entry' => $idx,
                            'idAge' => $idAge,
                            'feeKey' => $feeIdAge,
                            'skipped' => 'no_season_price_correlation',
                            'season_age_types' => $seasonAgeTypes,
                        ];
                        continue;
                    }
                }

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

        return ['total' => $total, 'entries' => $entryDetails];
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

        if (!empty($roomId) && str_contains(strtolower($roomId), strtolower('SGL'))) {
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

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger) {
            ($this->logger)($message, $data);
        }
    }
}
