<?php
declare(strict_types=1);
/**
 * Cron Service Interface
 *
 * Contract for cron job operations: ASK booking checks, alternative polling.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface CronServiceInterface
{
    /**
     * Check ASK status bookings.
     * Polls API for status updates on pending ASK bookings.
     *
     * @return array<string, mixed> Results with updated/unchanged/errors counts
     */
    public function checkAskBookings(): array;

    /**
     * Check pending alternative requests.
     * Polls API for alternatives on pending requests.
     *
     * @return array<string, mixed> Results
     */
    public function checkAlternatives(): array;

    /**
     * Get countries configured for sync.
     *
     * @return list<string>
     */
    public function getCountries(): array;
}
