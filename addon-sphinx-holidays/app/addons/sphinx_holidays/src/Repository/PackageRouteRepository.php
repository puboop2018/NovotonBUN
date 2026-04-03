<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Repository for sphinx_package_routes table.
 *
 * @since 1.3.0
 */
class PackageRouteRepository
{
    /**
     * Find route by unique key (transport_type, departure_id, arrival_id, duration).
     *
     * @return int|null Route ID or null
     */
    public function findByUniqueKey(string $transportType, int $departureId, int $arrivalId, int $duration): ?int
    {
        $id = db_get_field(
            "SELECT route_id FROM ?:sphinx_package_routes
             WHERE transport_type = ?s AND departure_id = ?i AND arrival_id = ?i AND duration = ?i",
            $transportType, $departureId, $arrivalId, $duration
        );
        return ($id !== false && $id !== '') ? (int) $id : null;
    }

    /**
     * Upsert a route row.
     */
    public function upsert(array $row): void
    {
        $existing = $this->findByUniqueKey(
            $row['transport_type'],
            (int) $row['departure_id'],
            (int) $row['arrival_id'],
            (int) $row['duration']
        );

        if ($existing !== null) {
            db_query("UPDATE ?:sphinx_package_routes SET ?u WHERE route_id = ?i", $row, $existing);
        } else {
            db_query("INSERT INTO ?:sphinx_package_routes ?e", $row);
        }
    }

    /**
     * Resolve a destination ID to its country code.
     */
    public function getCountryCodeForDestination(int $destinationId): string
    {
        return (string) db_get_field(
            "SELECT country_code FROM ?:sphinx_destinations WHERE destination_id = ?i",
            $destinationId
        );
    }
}
