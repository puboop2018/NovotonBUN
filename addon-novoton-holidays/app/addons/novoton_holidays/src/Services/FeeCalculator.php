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

class FeeCalculator implements FeeCalculatorInterface
{
    /** @var array<int, string> */
    private const AGE_TYPE_MAP = [
        1 => 'ADULT',
        2 => 'CHD 0-1.99',
        3 => 'CHD 2-11.99',
        4 => 'CHD 12-17.99',
    ];

    private PriceInfoParser $parser;

    private ?\Closure $logger;

    private readonly ExtrasFeeCalculator $extrasFeeCalculator;

    public function __construct(PriceInfoParser $parser, ?callable $logger = null)
    {
        $this->parser = $parser;
        $this->logger = $logger !== null ? $logger(...) : null;
        $this->extrasFeeCalculator = new ExtrasFeeCalculator($parser);
    }

    /**
     * Calculate fees (extras_daily, handling_fee, company_fee, etc.)
     * @param array<string, mixed> $occupancy
     * @return array<string, mixed>
     */
    #[\Override]
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
            'details' => [],
        ];

        // Collect the set of distinct IdAge values present in season_price for
        // this room/board.  Fee entries (extras_daily, handling_fee) are only
        // considered when their IdAge correlates with an age type that actually
        // exists in season_price for the booked room (room-specific correlation).
        $seasonAgeTypes = $this->collectSeasonPriceAgeTypes($roomId, $boardId);

        $fees['extras_daily'] = $this->extrasFeeCalculator->calculateDaily($occupancy, $checkIn, $nights, $seasonAgeTypes);
        if ($fees['extras_daily'] > 0) {
            $fees['details'][] = ['type' => 'extras_daily', 'amount' => $fees['extras_daily']];
        }

        $handlingResult = $this->calculateHandlingFee($occupancy, $checkIn, $nights, $seasonAgeTypes);
        $fees['handling_fee'] = PriceInfoFormatter::toFloat($handlingResult['total']);
        $fees['handling_fee_entries'] = $handlingResult['entries'];
        if ($fees['handling_fee'] > 0) {
            $fees['details'][] = ['type' => 'handling_fee', 'amount' => $fees['handling_fee']];
        }

        $fees['extras_single'] = $this->extrasFeeCalculator->calculateSingle($occupancy, $checkIn, $nights, $roomId);
        if ($fees['extras_single'] > 0) {
            $fees['details'][] = ['type' => 'extras_single', 'amount' => $fees['extras_single']];
        }

        $fees['extras_rooms'] = $this->extrasFeeCalculator->calculateRooms($occupancy, $checkIn, $nights, $roomId);
        if ($fees['extras_rooms'] > 0) {
            $fees['details'][] = ['type' => 'extras_rooms', 'amount' => $fees['extras_rooms']];
        }

        $fees['extras_board'] = $this->extrasFeeCalculator->calculateBoard($occupancy, $checkIn, $nights, $boardId);
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
    #[\Override]
    public function collectSeasonPriceAgeTypes(string $roomId, string $boardId): array
    {
        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $seasonPrices = $priceinfo['season_price'] ?? [];
        if (!is_array($seasonPrices)) {
            $seasonPrices = [];
        }
        if (isset($seasonPrices['IdRoom'])) {
            $seasonPrices = [$seasonPrices];
        }

        $ageTypes = [];
        foreach ($seasonPrices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowRoom = PriceInfoFormatter::toScalar($row['IdRoom'] ?? '');
            $rowBoard = PriceInfoFormatter::toScalar($row['IdBoard'] ?? '');

            if (!PriceInfoFormatter::matchRoom($rowRoom, $roomId)) {
                continue;
            }
            if (!PriceInfoFormatter::matchBoard($rowBoard, $boardId)) {
                continue;
            }

            $rawIdAge = PriceInfoFormatter::toScalar($row['IdAge'] ?? '');
            if (!empty($row['fAge']) && is_string($row['fAge'])) {
                $resolvedAge = $row['fAge'];
            } else {
                $resolvedAge = self::AGE_TYPE_MAP[PriceInfoFormatter::toInt($rawIdAge)] ?? $rawIdAge;
            }
            $resolvedAge = strtoupper(trim((string) preg_replace('/\s+/', ' ', $resolvedAge)));
            if ($resolvedAge !== '') {
                $ageTypes[$resolvedAge] = true;
            }
        }

        return array_keys($ageTypes);
    }

    /**
     * Calculate handling_fee
     *
     * @param array<string> $seasonAgeTypes Resolved IdAge values from season_price for the booked room/board.
     *                                      Only handling_fee entries whose IdAge correlates with one of these
     *                                      are considered.
     * @param array<string, mixed> $occupancy
     * @return array<string, mixed>
     */
    private function calculateHandlingFee(array $occupancy, string $checkIn, int $nights, array $seasonAgeTypes = []): array
    {
        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $handlingFees = $priceinfo['handling_fee'] ?? [];
        if (empty($handlingFees) || !is_array($handlingFees)) {
            return ['total' => 0, 'entries' => []];
        }

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
            if (!is_array($fee)) {
                continue;
            }
            $fromDate = PriceInfoFormatter::toScalar($fee['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($fee['ToDate'] ?? '');
            $idAge = PriceInfoFormatter::toScalar($fee['IdAge'] ?? '');
            $rawToDays = PriceInfoFormatter::toScalar($fee['ToDays'] ?? '');
            $rawFromDays = PriceInfoFormatter::toScalar($fee['FromDays'] ?? '');
            // Thresholds are optional in the API payload. Do NOT fabricate
            // defaults (a stay straddling an invented boundary would silently
            // get the wrong fee tier) — treat a missing threshold as "no
            // threshold" and fall back to the flat Price1 tier.
            $toDays = ($rawToDays !== '') ? (int) $rawToDays : null;
            $fromDays = ($rawFromDays !== '') ? (int) $rawFromDays : null;
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
            if ($toDays === null && $fromDays === null) {
                $price = $price1;
                $tierUsed = "Price1 (no thresholds provided by API, nights={$nights})";
            } elseif ($toDays !== null && $nights <= $toDays) {
                $price = $price1;
                $tierUsed = "Price1 (nights={$nights} <= toDays={$toDays})";
            } elseif ($fromDays !== null && $nights >= $fromDays) {
                $price = $price2;
                $tierUsed = "Price2 (nights={$nights} >= fromDays={$fromDays})";
            } else {
                $price = $price1;
                $tierUsed = "Price1 (fallback, nights={$nights} between toDays="
                    . ($toDays ?? 'n/a') . ' and fromDays=' . ($fromDays ?? 'n/a') . ')';
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
                $occAdults = is_array($occupancy['adults'] ?? null) ? $occupancy['adults'] : [];
                $occChildren = is_array($occupancy['children'] ?? null) ? $occupancy['children'] : [];
                $count = count($occAdults) + count($occChildren);
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
     * Calculate company_fee (per room)
     */
    private function calculateCompanyFee(string $roomId): float
    {
        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $companyFees = $priceinfo['company_fee'] ?? [];
        if (empty($companyFees) || !is_array($companyFees)) {
            return 0.0;
        }

        if (isset($companyFees['Price']) || isset($companyFees['IdRoom'])) {
            $companyFees = [$companyFees];
        }

        foreach ($companyFees as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            $feeRoomId = PriceInfoFormatter::toScalar($fee['IdRoom'] ?? '');
            $price = PriceInfoFormatter::toFloat($fee['Price'] ?? 0);

            if (empty($feeRoomId) || PriceInfoFormatter::matchRoom($feeRoomId, $roomId)) {
                return $price;
            }
        }

        return 0;
    }

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $data);
        }
    }
}
