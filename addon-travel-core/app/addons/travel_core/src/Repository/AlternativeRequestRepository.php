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
     * Statuses an admin may set manually from the shared grid. Only
     * internal-only providers (sphinx) are managed here — novoton rows
     * follow the provider workflow, whose transitions propagate via
     * updateStatusByProviderRef().
     *
     * @var list<string>
     */
    public const array MANUAL_STATUSES = ['notified', 'booked', 'cancelled'];

    /**
     * Store a request; returns the new request_id.
     *
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        return TypeCoerce::toInt(db_query('INSERT INTO ?:travel_alternative_requests ?e', $row));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $requestId): ?array
    {
        $row = TypeCoerce::toStringMap(db_get_row(
            'SELECT * FROM ?:travel_alternative_requests WHERE request_id = ?i',
            $requestId,
        ));

        return $row === [] ? null : $row;
    }

    /** Set one request's status (updated_at maintained by ON UPDATE). */
    public function updateStatus(int $requestId, string $status): bool
    {
        return (bool) db_query(
            'UPDATE ?:travel_alternative_requests SET status = ?s WHERE request_id = ?i',
            $status,
            $requestId,
        );
    }

    /**
     * Propagate a provider-side transition to the mirror row, keyed by
     * (provider, provider_request_id) — the link written at create time.
     */
    public function updateStatusByProviderRef(string $provider, int $providerRequestId, string $status): bool
    {
        return (bool) db_query(
            'UPDATE ?:travel_alternative_requests SET status = ?s WHERE provider = ?s AND provider_request_id = ?i',
            $status,
            $provider,
            $providerRequestId,
        );
    }

    /** Remove the mirror row of a deleted provider request. */
    public function deleteByProviderRef(string $provider, int $providerRequestId): bool
    {
        return (bool) db_query(
            'DELETE FROM ?:travel_alternative_requests WHERE provider = ?s AND provider_request_id = ?i',
            $provider,
            $providerRequestId,
        );
    }

    /**
     * Expire stale open requests — same 30-day policy as novoton's provider
     * table. Optionally restricted to one provider (the shared cron passes
     * 'sphinx'; novoton propagates its own expiry here itself).
     *
     * @return int Rows expired
     */
    public function expireOlderThan(int $days = 30, string $provider = ''): int
    {
        $providerCondition = $provider !== '' ? db_quote(' AND provider = ?s', $provider) : '';

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:travel_alternative_requests
             SET status = 'expired'
             WHERE status IN ('pending', 'pending_manual')
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i DAY) ?p",
            $days,
            $providerCondition,
        ));
    }

    /**
     * PII hygiene: hard-delete terminal rows past the retention window.
     * Only expired/cancelled rows go — notified/booked stay as history
     * (novoton keeps its own provider copy regardless).
     *
     * @return int Rows deleted
     */
    public function purgeOlderThan(int $days = 180): int
    {
        return TypeCoerce::toInt(db_query(
            "DELETE FROM ?:travel_alternative_requests
             WHERE status IN ('expired', 'cancelled')
             AND created_at < DATE_SUB(NOW(), INTERVAL ?i DAY)",
            $days,
        ));
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
