<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Contract for the admin-facing cron service that orchestrates
 * hotel sync, price checks, facility sync, and other batch tasks.
 *
 * @package NovotonHolidays
 * @since   3.9.0
 */
interface AdminCronServiceInterface
{
    /** @return array{success: bool, message: string} */
    public function syncHotels(): array;

    /** @return array{success: bool, message: string} */
    public function checkPrices(): array;

    /** @return array{success: bool, message: string} */
    public function syncFacilities(): array;

    /**
     * @return array{success: bool, message: string}
     * @param list<string> $countries
     */
    public function addProducts(array $countries, int $limit): array;

    /** @return array{success: bool, message: string} */
    public function checkOffers(string $country): array;

    /** @return array{success: bool, message: string} */
    public function checkAlternatives(string $type): array;

    /** @return array{success: bool, message: string} */
    public function notifyAlternatives(): array;

    /** @return array{success: bool, message: string} */
    public function cleanup(): array;

    /** @return array{success: bool, message: string} */
    public function expireRequests(int $days): array;
}
