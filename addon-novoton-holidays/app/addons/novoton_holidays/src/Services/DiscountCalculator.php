<?php
declare(strict_types=1);
/**
 * Novoton PriceInfo Discount Calculator
 *
 * Calculates discounts: early booking, free nights (reduction), reduction_period,
 * percentage-based promos (additional and marketing), and priority rule selection.
 *
 * Extracted from PriceInfoCalculator to support single-responsibility.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class DiscountCalculator implements DiscountCalculatorInterface
{
    private PriceInfoParser $parser;

    private ?\Closure $logger;

    public function __construct(PriceInfoParser $parser, ?callable $logger = null)
    {
        $this->parser = $parser;
        $this->logger = $logger !== null ? $logger(...) : null;
    }

    /**
     * Calculate Early Booking discount
     * @param array<string, mixed> $basePrice
     * @param array<string, mixed> $fees
     * @return array<string, mixed>
     */
    #[\Override]
    public function calculateEarlyBookingDiscount(string $bookingDate, string $checkIn, int $nights, array $basePrice, array $fees): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $ebData = $priceinfo['EB'] ?? $priceinfo['early_booking'] ?? [];
        if (empty($ebData) || !is_array($ebData)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'discount_breakdown' => []];
        }

        if (isset($ebData['Discount']) || isset($ebData['Reduction'])) {
            $ebData = [$ebData];
        }

        $ebToDaily = ($priceinfo['EBToDaily'] ?? 'No') === 'Yes';
        $ebToRooms = ($priceinfo['EBToRooms'] ?? 'No') === 'Yes';
        $ebToBoard = ($priceinfo['EBToBoard'] ?? 'No') === 'Yes';

        $bestDiscount = 0.0;
        $bestPercent = 0.0;
        $bestBreakdown = [];
        $applicable = false;

        $basePriceTotal = PriceInfoFormatter::toFloat($basePrice['total'] ?? 0);

        foreach ($ebData as $eb) {
            if (!is_array($eb)) {
                continue;
            }
            $bookFrom = PriceInfoFormatter::toScalar($eb['BookingFrom'] ?? $eb['BookFrom'] ?? '');
            $bookTo = PriceInfoFormatter::toScalar($eb['BookingTo'] ?? $eb['BookTo'] ?? '');
            $travelFrom = PriceInfoFormatter::toScalar($eb['TravelTimeFrom'] ?? $eb['StayFrom'] ?? '');
            $travelTo = PriceInfoFormatter::toScalar($eb['TravelTimeTo'] ?? $eb['StayTo'] ?? '');
            $discount = PriceInfoFormatter::toFloat($eb['Discount'] ?? $eb['Reduction'] ?? 0);
            $minStay = PriceInfoFormatter::toInt($eb['MinimumStay'] ?? $eb['MinStay'] ?? 0);

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
                    'base_price' => $basePriceTotal * $discountRate,
                    'extras_daily' => $ebToDaily ? PriceInfoFormatter::toFloat($fees['extras_daily'] ?? 0) * $discountRate : 0,
                    'extras_rooms' => $ebToRooms ? PriceInfoFormatter::toFloat($fees['extras_rooms'] ?? 0) * $discountRate : 0,
                    'extras_board' => $ebToBoard ? PriceInfoFormatter::toFloat($fees['extras_board'] ?? 0) * $discountRate : 0,
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
     * @param list<array<string, int|string>> $seasonsByNight
     * @param array<string, mixed> $occupancy
     * @param array<string, mixed> $basePrice
     * @param array<string, mixed> $fees
     * @return array<string, mixed>
     */
    #[\Override]
    public function calculateReduction(string $checkIn, int $nights, array $seasonsByNight, array $occupancy, string $roomId, string $boardId, array $basePrice = [], array $fees = []): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $reductions = $priceinfo['reduction'] ?? [];
        if (empty($reductions) || !is_array($reductions)) {
            return ['applicable' => false, 'discount' => 0, 'free_nights' => 0, 'free_night_indices' => [], 'discount_breakdown' => []];
        }

        if (isset($reductions['FreeNights'])) {
            $reductions = [$reductions];
        }

        $extToDaily = ($priceinfo['EXTToDaily'] ?? 'No') === 'Yes';
        $extToRooms = ($priceinfo['EXTToRooms'] ?? 'No') === 'Yes';
        $extToBoard = ($priceinfo['EXTToBoard'] ?? 'No') === 'Yes';

        $bestDiscount = 0.0;
        $bestFreeNights = 0;
        $bestFreeNightIndices = [];
        $bestBreakdown = [];
        $applicable = false;

        foreach ($reductions as $red) {
            if (!is_array($red)) {
                continue;
            }
            $fromNights = PriceInfoFormatter::toInt($red['FromNights'] ?? 0);
            $toNights = PriceInfoFormatter::toInt($red['ToNights'] ?? 999);
            $checkInFrom = PriceInfoFormatter::toScalar($red['CheckInFrom'] ?? '');
            $checkInTo = PriceInfoFormatter::toScalar($red['CheckInTo'] ?? '');
            $freeNights = PriceInfoFormatter::toInt($red['FreeNights'] ?? 0);
            $type = PriceInfoFormatter::toScalar($red['Type'] ?? 'End');
            $validFor = PriceInfoFormatter::toScalar($red['ValidFor'] ?? '');

            if ($nights < $fromNights || $nights > $toNights) {
                continue;
            }

            if (!empty($checkInFrom) && !empty($checkInTo)) {
                if (strcasecmp($validFor, 'Stay') === 0) {
                    $checkOutDate = date('Y-m-d', (int) strtotime($checkIn . ' + ' . $nights . ' days'));
                    if (!PriceInfoFormatter::datesOverlap($checkIn, $checkOutDate, $checkInFrom, $checkInTo)) {
                        continue;
                    }
                } else {
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

            $basePriceDiscount = 0.0;
            $byNight = is_array($basePrice['by_night'] ?? null) ? $basePrice['by_night'] : [];
            if (!empty($byNight)) {
                foreach ($freeNightIndices as $nightIdx) {
                    if (isset($byNight[$nightIdx]) && is_array($byNight[$nightIdx])) {
                        $basePriceDiscount += PriceInfoFormatter::toFloat($byNight[$nightIdx]['price'] ?? 0);
                    }
                }
            } else {
                $bpTotal = PriceInfoFormatter::toFloat($basePrice['total'] ?? 0);
                $avgNightPrice = $nights > 0 ? $bpTotal / $nights : 0;
                $basePriceDiscount = $avgNightPrice * count($freeNightIndices);
            }

            $freeNightsRatio = $nights > 0 ? count($freeNightIndices) / $nights : 0;

            $breakdown = [
                'base_price' => $basePriceDiscount,
                'extras_daily' => $extToDaily ? PriceInfoFormatter::toFloat($fees['extras_daily'] ?? 0) * $freeNightsRatio : 0,
                'extras_rooms' => $extToRooms ? PriceInfoFormatter::toFloat($fees['extras_rooms'] ?? 0) * $freeNightsRatio : 0,
                'extras_board' => $extToBoard ? PriceInfoFormatter::toFloat($fees['extras_board'] ?? 0) * $freeNightsRatio : 0,
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
     * @param array<string, mixed> $basePrice
     * @return array<string, mixed>
     */
    #[\Override]
    public function calculateReductionPeriod(string $checkIn, int $nights, array $basePrice): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_period'] ?? [];
        if (empty($entries) || !is_array($entries)) {
            return ['applicable' => false, 'discount' => 0, 'max_days' => 0, 'capped_nights' => 0];
        }

        if (isset($entries['FromDays'])) {
            $entries = [$entries];
        }

        $checkOut = date('Y-m-d', (int) strtotime($checkIn . ' + ' . $nights . ' days'));

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fromDays = PriceInfoFormatter::toInt($entry['FromDays'] ?? 0);
            $toDays = PriceInfoFormatter::toInt($entry['ToDays'] ?? 999);
            $maxDays = PriceInfoFormatter::toInt($entry['MaxDays'] ?? 0);
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
            $discount = 0.0;
            $byNight = is_array($basePrice['by_night'] ?? null) ? $basePrice['by_night'] : [];
            if (!empty($byNight)) {
                $excessCount = $nights - $maxDays;
                for ($i = $nights - $excessCount; $i < $nights; $i++) {
                    if (isset($byNight[$i]) && is_array($byNight[$i])) {
                        $discount += PriceInfoFormatter::toFloat($byNight[$i]['price'] ?? 0);
                    }
                }
            } else {
                $bpTotal = PriceInfoFormatter::toFloat($basePrice['total'] ?? 0);
                $avgNightPrice = $bpTotal / $nights;
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
     * @return array<string, mixed>
     */
    #[\Override]
    public function calculateReductionPercAdditional(float $subtotal): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_perc_additional'] ?? [];
        if (empty($entries) || !is_array($entries)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'name' => ''];
        }

        if (isset($entries['Perc'])) {
            $entries = [$entries];
        }

        $totalPercent = 0.0;
        $names = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $perc = PriceInfoFormatter::toFloat($entry['Perc'] ?? 0);
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
     * Supports date restrictions, room type filtering, minimum stay, and Type.
     * @return array<string, mixed>
     */
    #[\Override]
    public function calculateReductionPercMarketing(
        string $bookingDate,
        string $checkIn,
        int $nights,
        string $roomId,
        float $subtotal
    ): array {
        $priceinfo = $this->parser->getPriceinfo();
        $entries = $priceinfo['reduction_perc_marketing'] ?? [];
        if (empty($entries) || !is_array($entries)) {
            return ['applicable' => false, 'discount' => 0, 'percent' => 0, 'is_surcharge' => false, 'name' => '', 'details' => []];
        }

        if (isset($entries['Perc'])) {
            $entries = [$entries];
        }

        $bestPercent = 0.0;
        $bestName = '';
        $applicable = false;
        $details = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $perc = PriceInfoFormatter::toFloat($entry['Perc'] ?? 0);
            $name = PriceInfoFormatter::toScalar($entry['Name'] ?? '');
            $bookFrom = PriceInfoFormatter::toScalar($entry['BookingFrom'] ?? '');
            $bookTo = PriceInfoFormatter::toScalar($entry['BookingTo'] ?? '');
            $travelFrom = PriceInfoFormatter::toScalar($entry['TravelTimeFrom'] ?? '');
            $travelTo = PriceInfoFormatter::toScalar($entry['TravelTimeTo'] ?? '');
            $roomTypes = PriceInfoFormatter::toScalar($entry['RoomTypes'] ?? '');
            $minStay = PriceInfoFormatter::toInt($entry['MinimumStay'] ?? 0);
            $type = PriceInfoFormatter::toScalar($entry['Type'] ?? '');

            if ($perc === 0.0) {
                continue;
            }

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

            if ($minStay > 0 && $nights < $minStay) {
                continue;
            }

            $applicable = true;

            $isBestDiscount = ($bestPercent > 0);
            $isCurrentDiscount = ($perc > 0);

            if ($isCurrentDiscount && (!$isBestDiscount || $perc > $bestPercent)) {
                $bestPercent = $perc;
                $bestName = $name;
            } elseif (!$isCurrentDiscount && !$isBestDiscount && $perc < $bestPercent) {
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
     * @param array<string, mixed> $basePrice
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $ebDiscount
     * @param array<string, mixed> $reduction
     * @param array<string, mixed> $reductionPeriod
     * @return array<string, mixed>
     */
    #[\Override]
    public function applyPriorityRules(array $basePrice, array $fees, array $ebDiscount, array $reduction, array $reductionPeriod = []): array
    {
        $priceinfo = $this->parser->getPriceinfo();
        $priority = $priceinfo['Priority'] ?? 'No';
        $priorityEB = $priceinfo['PriorityEB'] ?? 'No';
        $priorityEXT = $priceinfo['PriorityEXT'] ?? 'No';

        $bpTotal = PriceInfoFormatter::toFloat($basePrice['total'] ?? 0);
        $feesTotal = PriceInfoFormatter::toFloat($fees['total'] ?? 0);
        $basePlusFees = $bpTotal + $feesTotal;

        // Apply reduction_period (MaxDays cap) to the base if applicable
        $reductionPeriodDiscount = !empty($reductionPeriod['applicable']) ? PriceInfoFormatter::toFloat($reductionPeriod['discount'] ?? 0) : 0.0;
        $basePlusFees -= $reductionPeriodDiscount;

        $ebApplicable = !empty($ebDiscount['applicable']);
        $ebDiscountAmt = PriceInfoFormatter::toFloat($ebDiscount['discount'] ?? 0);
        $redApplicable = !empty($reduction['applicable']);
        $redDiscountAmt = PriceInfoFormatter::toFloat($reduction['discount'] ?? 0);

        $totalNone = $basePlusFees;
        $totalEB = $ebApplicable ? ($basePlusFees - $ebDiscountAmt) : $basePlusFees;
        $totalReduction = $redApplicable ? ($basePlusFees - $redDiscountAmt) : $basePlusFees;

        $totalCombined = $basePlusFees;
        if ($ebApplicable) $totalCombined -= $ebDiscountAmt;
        if ($redApplicable) $totalCombined -= $redDiscountAmt;

        $appliedDiscount = 'none';
        $discountAmount = $reductionPeriodDiscount;
        $finalTotal = $totalNone;

        if ($priority === 'Yes') {
            if ($priorityEB === 'Yes' && $ebApplicable) {
                $finalTotal = $totalEB;
                $appliedDiscount = 'early_booking';
                $discountAmount += $ebDiscountAmt;
            } elseif ($priorityEXT === 'Yes' && $redApplicable) {
                $finalTotal = $totalReduction;
                $appliedDiscount = 'reduction';
                $discountAmount += $redDiscountAmt;
            } else {
                if ($totalEB <= $totalReduction && $ebApplicable) {
                    $finalTotal = $totalEB;
                    $appliedDiscount = 'early_booking';
                    $discountAmount += $ebDiscountAmt;
                } elseif ($redApplicable) {
                    $finalTotal = $totalReduction;
                    $appliedDiscount = 'reduction';
                    $discountAmount += $redDiscountAmt;
                }
            }
        } else {
            if ($ebApplicable || $redApplicable) {
                $finalTotal = $totalCombined;
                $appliedDiscount = 'combined';
                $discountAmount += ($ebApplicable ? $ebDiscountAmt : 0) +
                                   ($redApplicable ? $redDiscountAmt : 0);
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

    private function log(string $message, mixed $data = null): void
    {
        if ($this->logger) {
            ($this->logger)($message, $data);
        }
    }
}
