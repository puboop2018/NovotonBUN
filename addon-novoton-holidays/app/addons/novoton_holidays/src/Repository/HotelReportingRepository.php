<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotel Reporting Repository
 *
 * Aggregate and geography queries used by the admin dashboard,
 * sync status pages, and CSV-export screens.
 *
 * Extracted from HotelRepository (PR #3 of the architectural audit).
 * SQL is preserved verbatim from the original facade.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;

class HotelReportingRepository implements HotelReportingRepositoryInterface
{
    /**
     * @return list<string>
     */
    #[\Override]
    public function getCountries(): array
    {
        return db_get_fields("SELECT DISTINCT country FROM ?:novoton_hotels WHERE country != '' ORDER BY country");
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getResorts(string $country = ''): array
    {
        $hidden = Constants::HIDDEN_RESORTS;

        if (!empty($country)) {
            return db_get_fields(
                "SELECT DISTINCT city FROM ?:novoton_hotels WHERE country = ?s AND city != '' AND city NOT IN (?a) ORDER BY city",
                $country, $hidden
            );
        }

        return db_get_fields(
            "SELECT DISTINCT city FROM ?:novoton_hotels WHERE city != '' AND city NOT IN (?a) ORDER BY city",
            $hidden
        );
    }

    /**
     * @return list<array{country: string, city: string}>
     */
    #[\Override]
    public function getCountryCityPairs(): array
    {
        return db_get_array(
            "SELECT DISTINCT country, city FROM ?:novoton_hotels WHERE city != '' ORDER BY country, city"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getCountriesWithPriceCounts(): array
    {
        return db_get_array(
            "SELECT country, COUNT(*) as cnt FROM ?:novoton_hotels WHERE has_room_price = 'Y' GROUP BY country ORDER BY country"
        );
    }

    /**
     * @return array<string, int>
     */
    #[\Override]
    public function countWithoutPackagesByCountry(): array
    {
        return db_get_hash_single_array(
            "SELECT h.country, COUNT(*) as cnt FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE p.id IS NULL
             GROUP BY h.country ORDER BY cnt DESC",
            ['country', 'cnt']
        );
    }

    #[\Override]
    public function countWithPackagesByCountry(string $country): int
    {
        return (int) db_get_field(
            "SELECT COUNT(DISTINCT h.hotel_id) FROM ?:novoton_hotels h
             INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE h.country = ?s",
            $country
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getResortStatsByCountry(string $country): array
    {
        return db_get_array(
            "SELECT city, COUNT(*) as hotel_count,
                    SUM(CASE WHEN has_room_price = 'Y' THEN 1 ELSE 0 END) as with_prices
             FROM ?:novoton_hotels
             WHERE country = ?s AND city IS NOT NULL AND city != ''
             GROUP BY city ORDER BY hotel_count DESC",
            $country
        );
    }

    #[\Override]
    public function countWithCalendarPrices(): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotels WHERE calendar_prices_raw IS NOT NULL AND calendar_prices_raw != ''"
        );
    }
}
