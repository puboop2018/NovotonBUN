<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Manages the product_skip_reason lifecycle on sphinx_hotels.
 *
 * A hotel carries a skip reason when it is not eligible for CS-Cart product
 * creation: it may be missing a description, sit in an invalid country, or —
 * via the availability gate — have no immediate-confirmation offer. This
 * repository owns marking, clearing, counting, and locating those rows.
 *
 * Extracted from HotelRepository so the skip/availability-gate concern lives
 * behind its own collaborator instead of swelling the hotel repository's
 * read/write surface. Behaviour (SQL and parameters) is preserved verbatim.
 */
class HotelSkipRepository
{
    use RowNarrowingTrait;

    private const string STATUS_ACTIVE = 'active';

    /**
     * Skip reason set by the availability gate for hotels that have no
     * immediate-confirmation offer. Hotels carrying this reason are excluded
     * from product creation by HotelRepository::findUnlinked(); it is cleared
     * automatically once the hotel returns immediate availability on a later
     * sync.
     */
    public const string SKIP_REASON_NO_AVAILABILITY = 'no_availability';

    /**
     * Mark a hotel as skipped for product creation with a reason.
     *
     * Skipped hotels are excluded from findUnlinked() on subsequent runs.
     * Reset with resetSkipped() to retry.
     */
    public function markSkipped(string $hotelId, string $reason): void
    {
        db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s WHERE hotel_id = ?s',
            $reason,
            $hotelId,
        );
    }

    /**
     * Find hotels eligible for the availability gate within the given destinations.
     *
     * Candidates are active hotels with no CS-Cart product yet and either no
     * skip reason or the availability gate's own reason — so the gate can both
     * newly flag unavailable hotels and clear hotels that became available,
     * while never touching linked products or hotels skipped for other reasons.
     *
     * @param int[] $destinationIds
     * @return list<array<string, mixed>> Rows of {hotel_id, destination_id, product_skip_reason}
     */
    public function findAvailabilityGateCandidates(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }

        return self::asRowList(db_get_array(
            'SELECT hotel_id, destination_id, product_skip_reason
             FROM ?:sphinx_hotels
             WHERE destination_id IN (?n)
               AND sync_status = ?s
               AND (product_id IS NULL OR product_id = 0)
               AND (product_skip_reason IS NULL OR product_skip_reason = ?s)',
            $destinationIds,
            self::STATUS_ACTIVE,
            self::SKIP_REASON_NO_AVAILABILITY,
        ));
    }

    /**
     * Set a skip reason on hotels that do not already carry one.
     *
     * Only NULL skip reasons are overwritten, so hotels skipped for other
     * reasons (invalid_country, no_description, ...) are left untouched.
     *
     * @param string[] $hotelIds
     * @return int Number of hotels updated
     */
    public function markSkippedBatch(array $hotelIds, string $reason): int
    {
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s
             WHERE hotel_id IN (?a) AND product_skip_reason IS NULL',
            $reason,
            $hotelIds,
        ));
    }

    /**
     * Clear a specific skip reason from hotels, making them eligible again.
     *
     * Scoped to the given reason so unrelated skips are preserved.
     *
     * @param string[] $hotelIds
     * @return int Number of hotels cleared
     */
    public function clearSkipReasonBatch(array $hotelIds, string $reason): int
    {
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE hotel_id IN (?a) AND product_skip_reason = ?s',
            $hotelIds,
            $reason,
        ));
    }

    /**
     * Reset skip reason for hotels, making them eligible for product creation again.
     *
     * @return int Number of hotels reset
     */
    public function resetSkipped(string $countryCode = '', string $reason = ''): int
    {
        $condition = '';
        if ($countryCode !== '') {
            $condition .= TypeCoerce::toString(db_quote(' AND country_code = ?s', $countryCode));
        }
        if ($reason !== '') {
            $condition .= TypeCoerce::toString(db_quote(' AND product_skip_reason = ?s', $reason));
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = NULL
             WHERE product_skip_reason IS NOT NULL ?p',
            $condition,
        ));
    }

    /**
     * Count hotels that have been skipped for product creation.
     */
    public function countSkipped(string $reason = ''): int
    {
        $condition = '';
        if ($reason !== '') {
            $condition = TypeCoerce::toString(db_quote(' AND product_skip_reason = ?s', $reason));
        }
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_skip_reason IS NOT NULL ?p',
            $condition,
        ));
    }
}
