<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\HotelOffer;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Server-side snapshot of what a search offer said, keyed by a digest —
 * the sphinx OfferSnapshotStore idea on session storage (eurosite has no
 * provider cache table, and the flow is per-visitor anyway).
 *
 * The booking form and the terms modal are reached by URL, so nothing in
 * those requests can be believed: prices, variant ids and occupancy all
 * come from a snapshot the server itself wrote at search time. Only the
 * fields below are kept, so a shape change upstream cannot smuggle
 * anything through. One search replaces the whole set (bounded memory).
 */
final class OfferContextStore
{
    private const SESSION_KEY = 'eurosite_offers';

    private const MAX_OFFERS = 200;

    /**
     * @param list<HotelOffer> $offers
     * @param array{adults: int, children_ages: list<int>} $occupancy
     * @return array<string, string> spl_object_id-ish map: offer index => key
     */
    public static function remember(array $offers, array $occupancy): array
    {
        $snapshots = [];
        $keys = [];
        foreach (array_slice($offers, 0, self::MAX_OFFERS) as $i => $offer) {
            $key = self::keyFor($offer);
            $keys[$i] = $key;
            $snapshots[$key] = [
                'product_code' => $offer->productCode,
                'product_name' => $offer->productName,
                'country_code' => $offer->countryCode,
                'city_code'    => $offer->cityCode,
                'city_name'    => $offer->cityName,
                'check_in'     => $offer->checkIn,
                'check_out'    => $offer->checkOut,
                'variant_id'   => $offer->variantId,
                'series_id'    => $offer->seriesId,
                'price'        => $offer->price,
                'currency'     => $offer->currency,
                'offer_type'   => $offer->offerType,
                'availability' => $offer->availability,
                'rooms'        => $offer->rooms,
                'meals'        => $offer->meals,
                'adults'       => TypeCoerce::toInt($occupancy['adults'] ?? 2),
                'children_ages' => array_values(array_map('intval', (array) ($occupancy['children_ages'] ?? []))),
            ];
        }
        \Tygh\Tygh::$app['session'][self::SESSION_KEY] = $snapshots;

        return $keys;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        $all = \Tygh\Tygh::$app['session'][self::SESSION_KEY] ?? [];
        $snapshot = is_array($all) || $all instanceof \ArrayAccess ? ($all[$key] ?? null) : null;

        return is_array($snapshot) && $snapshot !== [] ? $snapshot : null;
    }

    public static function keyFor(HotelOffer $offer): string
    {
        return md5(implode('|', [
            $offer->productCode,
            $offer->variantId,
            $offer->seriesId,
            $offer->checkIn,
            $offer->checkOut,
            (string) $offer->price,
        ]));
    }
}
