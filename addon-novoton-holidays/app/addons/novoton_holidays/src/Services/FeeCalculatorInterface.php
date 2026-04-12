<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Contract for fee/supplement calculations from priceinfo season data.
 *
 * @package NovotonHolidays
 * @since   3.9.0
 */
interface FeeCalculatorInterface
{
    public function calculateFees(array $occupancy, string $checkIn, int $nights, string $roomId, string $boardId): array;

    public function collectSeasonPriceAgeTypes(string $roomId, string $boardId): array;
}
