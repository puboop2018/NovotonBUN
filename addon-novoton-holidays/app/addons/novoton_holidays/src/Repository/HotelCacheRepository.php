<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Hotel Cache Repository
 *
 * Read/write access to cron-recomputed metadata columns on
 * novoton_hotels: calendar_prices_raw, hotel_data, packages_count.
 *
 * Extracted from HotelRepository (PR #3 of the architectural audit).
 * SQL is preserved verbatim from the original facade.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

class HotelCacheRepository implements HotelCacheRepositoryInterface
{
    #[\Override]
    public function getCalendarPricesRaw(string $hotel_id): ?string
    {
        // `calendar_prices_raw` is guaranteed to exist by the idempotent install
        // migration at functions/install.php:428. No error suppression needed.
        $val = db_get_field(
            'SELECT calendar_prices_raw FROM ?:novoton_hotels WHERE hotel_id = ?s',
            $hotel_id,
        );
        return ($val !== false && $val !== '' && $val !== null) ? (string) $val : null;
    }

    #[\Override]
    public function setCalendarPricesRaw(string $hotel_id, ?string $json): void
    {
        if ($json !== null) {
            db_query(
                'UPDATE ?:novoton_hotels SET calendar_prices_raw = ?s WHERE hotel_id = ?s',
                $json,
                $hotel_id,
            );
        } else {
            db_query(
                'UPDATE ?:novoton_hotels SET calendar_prices_raw = NULL WHERE hotel_id = ?s',
                $hotel_id,
            );
        }
    }

    #[\Override]
    public function getHotelData(string $hotel_id): ?string
    {
        $val = db_get_field(
            'SELECT hotel_data FROM ?:novoton_hotels WHERE hotel_id = ?s',
            $hotel_id,
        );
        return ($val !== false && $val !== '' && $val !== null) ? (string) $val : null;
    }

    #[\Override]
    public function updatePackagesCount(string $hotel_id, int $count): bool
    {
        return (bool) db_query(
            'UPDATE ?:novoton_hotels SET packages_count = ?i WHERE hotel_id = ?s',
            $count,
            $hotel_id,
        );
    }
}
