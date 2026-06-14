<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Calculates the per-category "extras" supplements (daily, single, rooms,
 * board) from the parser's priceinfo, for a given occupancy and stay window.
 *
 * Extracted from FeeCalculator, where these four routines made up ~half the
 * class. Each reads priceinfo['extras_*'], matches entries by date / room /
 * board / age, and accumulates the supplement. Behaviour is preserved verbatim;
 * FeeCalculator delegates here from calculateFees().
 */
class ExtrasFeeCalculator
{
    public function __construct(
        private readonly PriceInfoParser $parser,
    ) {
    }

    /**
     * Calculate extras_daily.
     *
     * @param array<string, mixed> $occupancy
     * @param array<string> $seasonAgeTypes Resolved IdAge values from season_price for the booked room
     */
    public function calculateDaily(array $occupancy, string $checkIn, int $nights, array $seasonAgeTypes = []): float
    {
        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $extrasDaily = $priceinfo['extras_daily'] ?? [];
        if (empty($extrasDaily) || !is_array($extrasDaily)) {
            return 0.0;
        }

        if (isset($extrasDaily['IdAge'])) {
            $extrasDaily = [$extrasDaily];
        }

        $total = 0.0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasDaily as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $fromDate = PriceInfoFormatter::toScalar($extra['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($extra['ToDate'] ?? '');
            $idAge = PriceInfoFormatter::toScalar($extra['IdAge'] ?? '');
            $price = PriceInfoFormatter::toFloat($extra['Price'] ?? 0);
            $type = PriceInfoFormatter::toScalar($extra['Type'] ?? 'Day');

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
     * Calculate extras_single (single-occupancy supplement).
     *
     * @param array<string, mixed> $occupancy
     */
    public function calculateSingle(array $occupancy, string $checkIn, int $nights, string $roomId = ''): float
    {
        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $extrasSingle = $priceinfo['extras_single'] ?? [];
        if (empty($extrasSingle) || !is_array($extrasSingle)) {
            return 0.0;
        }

        $occAdults = is_array($occupancy['adults'] ?? null) ? $occupancy['adults'] : [];
        if (count($occAdults) !== 1) {
            return 0.0;
        }

        if (!empty($roomId) && str_contains(strtolower($roomId), 'sgl')) {
            return 0.0;
        }

        if (isset($extrasSingle['Price'])) {
            $extrasSingle = [$extrasSingle];
        }

        $total = 0.0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasSingle as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $fromDate = PriceInfoFormatter::toScalar($extra['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($extra['ToDate'] ?? '');
            $price = PriceInfoFormatter::toFloat($extra['Price'] ?? 0);
            $type = PriceInfoFormatter::toScalar($extra['Type'] ?? 'Stay');
            $idRoom = PriceInfoFormatter::toScalar($extra['IdRoom'] ?? '');

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
     * Calculate extras_rooms.
     *
     * @param array<string, mixed> $occupancy
     */
    public function calculateRooms(array $occupancy, string $checkIn, int $nights, string $roomId = ''): float
    {
        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $extrasRooms = $priceinfo['extras_rooms'] ?? [];
        if (empty($extrasRooms) || !is_array($extrasRooms)) {
            return 0.0;
        }

        if (isset($extrasRooms['IdRoom']) || isset($extrasRooms['Price'])) {
            $extrasRooms = [$extrasRooms];
        }

        $total = 0.0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasRooms as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $fromDate = PriceInfoFormatter::toScalar($extra['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($extra['ToDate'] ?? '');
            $price = PriceInfoFormatter::toFloat($extra['Price'] ?? 0);
            $type = PriceInfoFormatter::toScalar($extra['Type'] ?? 'Day');
            $idRoom = PriceInfoFormatter::toScalar($extra['IdRoom'] ?? '');

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
     * Calculate extras_board (board supplement).
     *
     * @param array<string, mixed> $occupancy
     */
    public function calculateBoard(array $occupancy, string $checkIn, int $nights, string $boardId): float
    {
        // No board supplement when booking the base board (empty boardId).
        if (empty($boardId)) {
            return 0;
        }

        $priceinfo = $this->parser->getPriceinfo() ?? [];
        $extrasBoard = $priceinfo['extras_board'] ?? [];
        if (empty($extrasBoard) || !is_array($extrasBoard)) {
            return 0.0;
        }

        if (isset($extrasBoard['IdBoard']) || isset($extrasBoard['Price'])) {
            $extrasBoard = [$extrasBoard];
        }

        $total = 0.0;
        $checkInDate = new \DateTime($checkIn);
        $checkOutDate = clone $checkInDate;
        $checkOutDate->modify("+{$nights} days");

        foreach ($extrasBoard as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $fromDate = PriceInfoFormatter::toScalar($extra['FromDate'] ?? '');
            $toDate = PriceInfoFormatter::toScalar($extra['ToDate'] ?? '');
            $price = PriceInfoFormatter::toFloat($extra['Price'] ?? 0);
            $type = PriceInfoFormatter::toScalar($extra['Type'] ?? 'Day');
            $idBoard = PriceInfoFormatter::toScalar($extra['IdBoard'] ?? '');
            $idAge = PriceInfoFormatter::toScalar($extra['IdAge'] ?? '');

            if (!empty($idBoard)) {
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
                if ($personCount === 0) {
                    continue;
                }
            } else {
                $occAdults = is_array($occupancy['adults'] ?? null) ? $occupancy['adults'] : [];
                $occChildren = is_array($occupancy['children'] ?? null) ? $occupancy['children'] : [];
                $personCount = count($occAdults) + count($occChildren);
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
}
