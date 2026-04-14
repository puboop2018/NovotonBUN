<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Contract for early-booking discount and reduction calculations.
 *
 * @package NovotonHolidays
 * @since   3.9.0
 */
interface DiscountCalculatorInterface
{
    /**
     * @param array<string, mixed> $basePrice
     * @param array<string, mixed> $fees
     * @return array<string, mixed>
     */
    public function calculateEarlyBookingDiscount(string $bookingDate, string $checkIn, int $nights, array $basePrice, array $fees): array;

    /**
     * @param list<array<string, int|string>> $seasonsByNight
     * @param array<string, mixed> $occupancy
     * @param array<string, mixed> $basePrice
     * @param array<string, mixed> $fees
     * @return array<string, mixed>
     */
    public function calculateReduction(string $checkIn, int $nights, array $seasonsByNight, array $occupancy, string $roomId, string $boardId, array $basePrice = [], array $fees = []): array;

    /**
     * @param array<string, mixed> $basePrice
     * @return array<string, mixed>
     */
    public function calculateReductionPeriod(string $checkIn, int $nights, array $basePrice): array;

    /**
     * @return array<string, mixed>
     */
    public function calculateReductionPercAdditional(float $subtotal): array;

    /**
     * @return array<string, mixed>
     */
    public function calculateReductionPercMarketing(
        string $bookingDate,
        string $checkIn,
        int $nights,
        string $roomId,
        float $subtotal,
    ): array;

    /**
     * @param array<string, mixed> $basePrice
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $ebDiscount
     * @param array<string, mixed> $reduction
     * @param array<string, mixed> $reductionPeriod
     * @return array<string, mixed>
     */
    public function applyPriorityRules(array $basePrice, array $fees, array $ebDiscount, array $reduction, array $reductionPeriod = []): array;
}
