<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Flattens a Sphinx hotel-search offer into the shape the storefront
 * search template (and its JS poller) consumes.
 *
 * The Sphinx /hotels/search results endpoint returns a nested offer shape:
 *   {
 *     offer_id, hotel_id, hotel_name, destination_name,
 *     pricing: { selling_price, currency, ... },
 *     rooms: [ { room_name|room_type|name, ... }, ... ],
 *     meal_type_name, ...
 *   }
 *
 * The template and the JS renderer read a FLAT shape (price, currency,
 * destination, board_name, room_name). Without this mapping every offer
 * renders with a 0,00 price and blank room/board, and the commission step
 * in the poll controller is skipped (it keys off `price`, which is absent).
 *
 * Field sources mirror the existing readers already in the codebase:
 *   - pricing.selling_price / pricing.currency  (CircuitSyncService, ExperienceSyncService)
 *   - meal_type_name                            (DiscoverBoardsCommand)
 *   - destination_name                          (HotelSyncService, CacheEndpointService)
 *
 * Each source also falls back to the legacy flat key, so already-flat
 * payloads (e.g. cached results) pass through unchanged and idempotently.
 */
class SearchOfferNormalizer
{
    /**
     * @param array<string, mixed> $offer Raw search offer from the Sphinx API
     * @return array<string, mixed> Flattened offer with template-ready keys
     */
    public static function flatten(array $offer): array
    {
        $pricing = TypeCoerce::toStringMap($offer['pricing'] ?? null);

        $price = array_key_exists('price', $offer)
            ? TypeCoerce::toFloat($offer['price'])
            : TypeCoerce::toFloat($pricing['selling_price'] ?? 0);

        $currency = TypeCoerce::toString(
            $offer['currency'] ?? $pricing['currency'] ?? '',
        );

        // room_name lives inside the first room of the offer's `rooms` array;
        // booking_form/circuit templates read room_name|room_type|name there.
        $roomName = TypeCoerce::toString($offer['room_name'] ?? $offer['room_type'] ?? '');
        if ($roomName === '') {
            $rooms = TypeCoerce::toRowList($offer['rooms'] ?? null);
            if ($rooms !== []) {
                $firstRoom = $rooms[0];
                $roomName = TypeCoerce::toString(
                    $firstRoom['room_name'] ?? $firstRoom['room_type'] ?? $firstRoom['name'] ?? '',
                );
            }
        }

        $flat = $offer;
        $flat['offer_id'] = TypeCoerce::toString($offer['offer_id'] ?? '');
        $flat['hotel_id'] = TypeCoerce::toString($offer['hotel_id'] ?? $offer['id'] ?? '');
        $flat['hotel_name'] = TypeCoerce::toString($offer['hotel_name'] ?? $offer['name'] ?? '');
        $flat['destination'] = TypeCoerce::toString(
            $offer['destination'] ?? $offer['destination_name'] ?? '',
        );
        $flat['room_name'] = $roomName;
        $flat['board_name'] = TypeCoerce::toString(
            $offer['board_name'] ?? $offer['meal_type_name'] ?? '',
        );
        $flat['price'] = $price;
        $flat['currency'] = $currency;

        return $flat;
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public static function flattenAll(array $offers): array
    {
        return array_map([self::class, 'flatten'], $offers);
    }
}
