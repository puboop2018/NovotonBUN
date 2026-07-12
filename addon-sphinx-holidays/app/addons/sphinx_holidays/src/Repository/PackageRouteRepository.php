<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

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
        $id = TypeCoerce::toInt(db_get_field(
            'SELECT route_id FROM ?:sphinx_package_routes
             WHERE transport_type = ?s AND departure_id = ?i AND arrival_id = ?i AND duration = ?i',
            $transportType,
            $departureId,
            $arrivalId,
            $duration,
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Upsert a route row.
     * @param array<string, mixed> $row
     */
    public function upsert(array $row): void
    {
        $existing = $this->findByUniqueKey(
            TypeCoerce::toString($row['transport_type'] ?? ''),
            TypeCoerce::toInt($row['departure_id'] ?? 0),
            TypeCoerce::toInt($row['arrival_id'] ?? 0),
            TypeCoerce::toInt($row['duration'] ?? 0),
        );

        if ($existing !== null) {
            db_query('UPDATE ?:sphinx_package_routes SET ?u WHERE route_id = ?i', $row, $existing);
        } else {
            db_query('INSERT INTO ?:sphinx_package_routes ?e', $row);
        }
    }

    /**
     * Resolve a destination ID to its country code.
     */
    public function getCountryCodeForDestination(int $destinationId): string
    {
        return TypeCoerce::toString(db_get_field(
            'SELECT country_code FROM ?:sphinx_destinations WHERE destination_id = ?i',
            $destinationId,
        ));
    }

    /** Total number of stored routes (for the grid's freshness line). */
    public function countAll(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_package_routes'));
    }

    /** Timestamp of the most recently synced route, or null if none synced yet. */
    public function getLastSyncedAt(): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT MAX(last_synced_at) FROM ?:sphinx_package_routes',
        ));

        return $val === '' ? null : $val;
    }

    /**
     * Paginated, sortable, filterable package-route listing for the admin grid.
     * Returns [$rows, $params] where $params carries total_items / page /
     * items_per_page — the CS-Cart pagination.tpl contract (mirrors
     * fn_sphinx_holidays_get_hotels).
     *
     * @param array<string, mixed> $params
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function getListing(array $params = []): array
    {
        $default_params = [
            'page' => 1,
            'items_per_page' => 50,
            'sort_by' => 'departure_name',
            'sort_order' => 'asc',
            'transport_type' => '',
            'departure' => '',
            'arrival' => '',
            'duration' => '',
        ];

        $params = array_merge($default_params, array_intersect_key($params, $default_params));
        $params['page'] = max(1, TypeCoerce::toInt($params['page']));
        $params['items_per_page'] = max(1, TypeCoerce::toInt($params['items_per_page']));
        $params['transport_type'] = trim(TypeCoerce::toString($params['transport_type']));
        $params['departure'] = trim(TypeCoerce::toString($params['departure']));
        $params['arrival'] = trim(TypeCoerce::toString($params['arrival']));
        $params['duration'] = trim(TypeCoerce::toString($params['duration']));

        $sortings = [
            'route_id' => 'r.route_id',
            'transport_type' => 'r.transport_type',
            'departure_name' => 'r.departure_name',
            'arrival_name' => 'r.arrival_name',
            'duration' => 'r.duration',
            'last_synced_at' => 'r.last_synced_at',
        ];
        $sort_by = isset($sortings[TypeCoerce::toString($params['sort_by'])]) ? TypeCoerce::toString($params['sort_by']) : 'departure_name';
        $sort_order = strtolower(TypeCoerce::toString($params['sort_order'])) === 'desc' ? 'DESC' : 'ASC';
        $sort_column = $sortings[$sort_by];

        $params['sort_by'] = $sort_by;
        $params['sort_order'] = strtolower($sort_order);
        $params['sort_order_toggle'] = ($sort_order === 'ASC') ? 'desc' : 'asc';

        $condition = '';
        if ($params['transport_type'] !== '') {
            $condition .= db_quote(' AND r.transport_type = ?s', $params['transport_type']);
        }
        if ($params['departure'] !== '') {
            $condition .= db_quote(' AND (r.departure_name LIKE ?l OR r.departure_iata LIKE ?l)', '%' . $params['departure'] . '%', '%' . $params['departure'] . '%');
        }
        if ($params['arrival'] !== '') {
            $condition .= db_quote(' AND (r.arrival_name LIKE ?l OR r.arrival_iata LIKE ?l)', '%' . $params['arrival'] . '%', '%' . $params['arrival'] . '%');
        }
        if ($params['duration'] !== '') {
            $condition .= db_quote(' AND r.duration = ?i', TypeCoerce::toInt($params['duration']));
        }

        $params['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_package_routes r WHERE 1 ?p',
            $condition,
        ));

        $offset = ($params['page'] - 1) * $params['items_per_page'];

        $listing_cols = 'r.route_id, r.transport_type, r.departure_name, r.departure_iata, '
            . 'r.arrival_name, r.arrival_iata, r.available_dates, r.duration, r.last_synced_at';

        $rows = TypeCoerce::toRowList(db_get_array(
            "SELECT {$listing_cols} FROM ?:sphinx_package_routes r"
            . ' WHERE 1 ?p'
            . " ORDER BY {$sort_column} {$sort_order}"
            . ' LIMIT ?i, ?i',
            $condition,
            $offset,
            $params['items_per_page'],
        ));

        return [$rows, $params];
    }
}
