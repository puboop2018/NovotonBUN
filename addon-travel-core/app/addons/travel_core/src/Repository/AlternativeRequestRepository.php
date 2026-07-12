<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cross-provider "no availability" requests — travel_alternative_requests.
 *
 * Guests who asked to be contacted when a hotel had no offers for their
 * dates. Novoton mirrors its provider-level requests here
 * (provider_request_id links back to novoton_alternative_requests); sphinx
 * writes here ONLY — internal follow-up, no provider API call.
 *
 * @since 1.5.0
 */
class AlternativeRequestRepository
{
    /**
     * Store a request; returns the new request_id.
     *
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        return TypeCoerce::toInt(db_query('INSERT INTO ?:travel_alternative_requests ?e', $row));
    }

    /** Total number of stored requests. */
    public function countAll(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:travel_alternative_requests'));
    }

    /**
     * Paginated, filterable listing for the admin grid.
     * Returns [$rows, $params] where $params carries total_items / page /
     * items_per_page — the CS-Cart pagination.tpl contract.
     *
     * @param array<string, mixed> $params
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function getListing(array $params = []): array
    {
        $default_params = [
            'page' => 1,
            'items_per_page' => 50,
            'provider' => '',
            'status' => '',
            'q' => '',
        ];

        $params = array_merge($default_params, array_intersect_key($params, $default_params));
        $params['page'] = max(1, TypeCoerce::toInt($params['page']));
        $params['items_per_page'] = max(1, TypeCoerce::toInt($params['items_per_page']));
        $params['provider'] = trim(TypeCoerce::toString($params['provider']));
        $params['status'] = trim(TypeCoerce::toString($params['status']));
        $params['q'] = trim(TypeCoerce::toString($params['q']));

        $condition = '';
        if ($params['provider'] !== '') {
            $condition .= db_quote(' AND r.provider = ?s', $params['provider']);
        }
        if ($params['status'] !== '') {
            $condition .= db_quote(' AND r.status = ?s', $params['status']);
        }
        if ($params['q'] !== '') {
            $condition .= db_quote(
                ' AND (r.hotel_name LIKE ?l OR r.contact_email LIKE ?l)',
                '%' . $params['q'] . '%',
                '%' . $params['q'] . '%',
            );
        }

        $params['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:travel_alternative_requests r WHERE 1 ?p',
            $condition,
        ));

        $offset = ($params['page'] - 1) * $params['items_per_page'];

        $rows = TypeCoerce::toRowList(db_get_array(
            'SELECT r.* FROM ?:travel_alternative_requests r'
            . ' WHERE 1 ?p'
            . ' ORDER BY r.created_at DESC, r.request_id DESC'
            . ' LIMIT ?i, ?i',
            $condition,
            $offset,
            $params['items_per_page'],
        ));

        return [$rows, $params];
    }
}
