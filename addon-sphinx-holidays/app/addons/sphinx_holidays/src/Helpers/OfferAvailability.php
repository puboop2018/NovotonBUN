<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Availability semantics for Sphinx search offers.
 *
 * The Sphinx search results endpoint (/api/v1/hotels/results) returns offers
 * carrying a `confirmation` field. A value of `immediate` means the offer has
 * real-time availability and can be booked instantly; any other value (or a
 * missing field) means the offer is on-request / not immediately bookable.
 *
 * This helper is intentionally pure (no DB, no I/O) so the availability rule
 * lives in one tested place, shared by the hotel sync gate and the storefront
 * search filter.
 */
final class OfferAvailability
{
    /** Confirmation value that denotes real, immediately-bookable availability. */
    public const string CONFIRMATION_IMMEDIATE = 'immediate';

    /**
     * Whether a single offer has immediate confirmation.
     *
     * @param array<array-key, mixed> $offer
     */
    public static function isImmediate(array $offer): bool
    {
        return strtolower(trim(TypeCoerce::toString($offer['confirmation'] ?? '')))
            === self::CONFIRMATION_IMMEDIATE;
    }

    /**
     * Keep only offers with immediate confirmation, re-indexed.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public static function filterImmediate(array $offers): array
    {
        return array_values(array_filter($offers, self::isImmediate(...)));
    }

    /**
     * Collect the set of hotel IDs that have at least one immediate-confirmation
     * offer. Returned as a map (hotel_id => true) for O(1) membership tests.
     *
     * Accepts the raw poll payload (list<mixed>): non-array rows and rows
     * without a usable hotel id are skipped. Mirrors the hotel-id resolution
     * used across the search controllers (hotel_id, falling back to id).
     *
     * @param list<mixed> $offers
     * @return array<string, true>
     */
    public static function collectImmediateHotelIds(array $offers): array
    {
        $hotelIds = [];
        foreach ($offers as $offer) {
            if (!is_array($offer) || !self::isImmediate($offer)) {
                continue;
            }
            $hotelId = TypeCoerce::toString($offer['hotel_id'] ?? $offer['id'] ?? '');
            if ($hotelId !== '') {
                $hotelIds[$hotelId] = true;
            }
        }

        return $hotelIds;
    }
}
