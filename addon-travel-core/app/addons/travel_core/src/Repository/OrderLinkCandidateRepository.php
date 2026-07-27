<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Finds the orders worth replaying the `travel_link_order_bookings` hook on.
 *
 * Each replay is expensive — every provider hook runs fn_get_order_info()
 * (dozens of queries per order) — so both reconcile surfaces (the bookings
 * grid self-heal and the Travel Tools button) pre-filter here instead of
 * walking the newest N orders blindly:
 *
 *  - Orders whose items never reference a provider booking can be skipped
 *    outright: the providers link through `$product['extra']` keys
 *    (`travel_booking_id`, `novoton_booking_id`, ...) that live serialized
 *    in `?:order_details.extra`, so a LIKE probe over the bounded candidate
 *    set is a strict superset of what any provider hook could link.
 *  - For the automatic grid self-heal, only orders placed around the time an
 *    unlinked booking row was created can be its order (the booking row and
 *    its order are written seconds apart in the same checkout flow), so
 *    candidates shrink further to a padded window around the unlinked rows.
 */
final class OrderLinkCandidateRepository
{
    use RowNarrowingTrait;

    /**
     * Padding (seconds) around the unlinked-booking window when matching
     * orders — one day absorbs any PHP↔MySQL clock or timezone skew
     * (?:orders.timestamp is a PHP unix stamp, created_at a MySQL DATETIME).
     */
    private const WINDOW_MARGIN = 86400;

    /**
     * Candidates for the throttled grid self-heal: the newest orders inside
     * the unlinked-booking time window whose items reference a provider
     * booking. Empty when nothing is unlinked — callers skip the replay
     * (and the listing re-fetch) entirely.
     *
     * @return list<int> Order ids, newest first
     */
    public function findAutoCandidates(int $limit): array
    {
        $window = self::asRow(db_get_row(
            'SELECT MIN(UNIX_TIMESTAMP(created_at)) AS t0, MAX(UNIX_TIMESTAMP(created_at)) AS t1'
            . ' FROM ?:travel_bookings WHERE order_id IS NULL OR order_id = 0',
        ));
        $t0 = TypeCoerce::toInt($window['t0'] ?? 0);
        $t1 = TypeCoerce::toInt($window['t1'] ?? 0);
        if ($t0 <= 0 || $t1 <= 0) {
            return [];
        }

        $orderIds = TypeCoerce::toIntList(db_get_fields(
            'SELECT order_id FROM ?:orders WHERE timestamp BETWEEN ?i AND ?i'
            . ' ORDER BY order_id DESC LIMIT ?i',
            $t0 - self::WINDOW_MARGIN,
            $t1 + self::WINDOW_MARGIN,
            $limit,
        ));

        return $this->filterOrdersWithBookingItems($orderIds);
    }

    /**
     * Candidates for the manual Travel Tools sweep: the newest $limit orders
     * whose items reference a provider booking (no time-window filter — the
     * button is the deliberate deep pass).
     *
     * @return list<int> Order ids, newest first
     */
    public function findSweepCandidates(int $limit): array
    {
        $orderIds = TypeCoerce::toIntList(db_get_fields(
            'SELECT order_id FROM ?:orders ORDER BY order_id DESC LIMIT ?i',
            $limit,
        ));

        return $this->filterOrdersWithBookingItems($orderIds);
    }

    /**
     * Keep only orders with at least one item whose serialized extra mentions
     * a booking-id key. Matching the substring every provider's key shares
     * keeps the probe provider-agnostic; false positives are harmless (the
     * provider hooks re-check the unserialized extra properly) and false
     * negatives are impossible for hook-linkable items.
     *
     * @param list<int> $orderIds
     * @return list<int> Subset of $orderIds, original (newest-first) order
     */
    private function filterOrdersWithBookingItems(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $withBookings = TypeCoerce::toIntList(db_get_fields(
            'SELECT DISTINCT order_id FROM ?:order_details WHERE order_id IN (?n) AND extra LIKE ?l',
            $orderIds,
            '%booking_id%',
        ));
        $keep = array_flip($withBookings);

        $result = [];
        foreach ($orderIds as $orderId) {
            if (isset($keep[$orderId])) {
                $result[] = $orderId;
            }
        }

        return $result;
    }
}
