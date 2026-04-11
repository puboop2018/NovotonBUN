<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotel Cache Repository Contract
 *
 * Focused contract for the "computed/cached metadata" columns on
 * novoton_hotels (`calendar_prices_raw`, `hotel_data`, `packages_count`).
 * These are recomputed by cron jobs and consumed by the frontend
 * booking engine at render time.
 *
 * Extracted from HotelRepository to unwind the 49-method god object.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

interface HotelCacheRepositoryInterface
{
    /** Get precomputed calendar prices JSON for a hotel (or null if empty). */
    public function getCalendarPricesRaw(string $hotel_id): ?string;

    /** Set (or clear) precomputed calendar prices JSON for a hotel. */
    public function setCalendarPricesRaw(string $hotel_id, ?string $json): void;

    /** Get the hotel_data JSON blob stored alongside the hotel row. */
    public function getHotelData(string $hotel_id): ?string;

    /** Update the denormalised packages_count column for a hotel. */
    public function updatePackagesCount(string $hotel_id, int $count): bool;
}
