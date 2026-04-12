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
    public function calculateEarlyBookingDiscount(string $bookingDate, string $checkIn, int $nights, array $basePrice, array $fees): array;

    public function calculateReduction(string $checkIn, int $nights, array $seasonsByNight, array $occupancy, string $roomId, string $boardId, array $basePrice = [], array $fees = []): array;

    public function calculateReductionPeriod(string $checkIn, int $nights, array $basePrice): array;

    public function calculateReductionPercAdditional(float $subtotal): array;

    public function calculateReductionPercMarketing(
        string $bookingDate,
        string $checkIn,
        int $nights,
        string $roomId,
        float $subtotal
    ): array;

    public function applyPriorityRules(array $basePrice, array $fees, array $ebDiscount, array $reduction, array $reductionPeriod = []): array;
}
