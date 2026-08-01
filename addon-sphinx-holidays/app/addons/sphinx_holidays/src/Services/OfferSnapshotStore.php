<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Server-side snapshot of what a search offer said, keyed by offer_id.
 *
 * WHY THIS EXISTS
 *
 * The Sphinx API marks each offer with `must_verify`. Its own documentation is
 * explicit: "You must use this endpoint whenever the chosen offer has the
 * must_verify property set to true", and "this call can be an expensive
 * operation and should be used only when necessary". We were calling verify
 * unconditionally, including for offers that declare must_verify=false — and
 * when the provider's verify endpoint broke (500, "Undefined array key 0"
 * inside their EtripV2Client), those offers became unbookable even though
 * nothing required verifying them. Measured on the live staging host: all four
 * Porto Bello Hotel Resort&Spa offers carry must_verify=false and every verify
 * call for them 500s.
 *
 * Skipping verify needs a price the SERVER trusts. The booking form is reached
 * by URL, so nothing in the request can be believed — a hand-edited price
 * would otherwise become the order total. The search controllers already cache
 * their normalized, commission-applied result rows; this stores a small
 * per-offer copy of the fields the booking form needs, under the same TTL. A
 * lookup either finds a row the server itself wrote, or finds nothing and the
 * caller falls back to verify.
 *
 * Deliberately NOT a general cache of the whole offer: only the fields below
 * are kept, so a shape change upstream cannot smuggle anything through.
 *
 * @since 1.4.1
 */
final class OfferSnapshotStore
{
    /**
     * Key version — bump when the snapshot shape changes so entries written by
     * an older deploy are ignored rather than misread.
     */
    private const string KEY_PREFIX = 'offer:v2:';

    public static function key(string $offerId): string
    {
        return self::KEY_PREFIX . $offerId;
    }

    /**
     * Store one snapshot per offer. Called right after the search results are
     * cached, with the same TTL: a snapshot must never outlive the result set
     * it came from, or a stale price could be served after the offers expire.
     *
     * @param list<array<string, mixed>> $offers normalized search rows
     * @return int snapshots written
     */
    public static function remember(array $offers, int $ttl): int
    {
        if ($ttl <= 0) {
            return 0;
        }

        $entries = [];
        foreach ($offers as $offer) {
            $offerId = TypeCoerce::toString($offer['offer_id'] ?? '');
            if ($offerId === '') {
                continue;
            }
            $snapshot = self::snapshot($offer);
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            $entries[self::key($offerId)] = $encoded;
        }

        if ($entries === []) {
            return 0;
        }

        return Container::getCacheService()->setMany($entries, $ttl);
    }

    /**
     * Write snapshots only when they are not already there.
     *
     * For the CACHE-HIT path. A cached result set is served for up to its full
     * TTL (900s by default), and if it was written before snapshots existed —
     * or by any path that does not write them — every Rezerva acum in that
     * window falls back to the verify call this whole mechanism exists to
     * avoid. Re-writing unconditionally would mean ~1,500 rows on every hit of
     * a destination-wide search, so one cheap read of the first offer decides
     * for the set: they are written together, so they are missing together.
     *
     * @param list<array<string, mixed>> $offers
     * @return int snapshots written (0 when already present)
     */
    public static function rememberIfMissing(array $offers, int $ttl): int
    {
        if ($offers === [] || $ttl <= 0) {
            return 0;
        }

        $firstId = TypeCoerce::toString($offers[0]['offer_id'] ?? '');
        if ($firstId !== '' && self::get($firstId) !== null) {
            return 0;
        }

        return self::remember($offers, $ttl);
    }

    /**
     * Read one snapshot back. Returns null when absent or expired — the caller
     * must then fall back to verify rather than guessing.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $offerId): ?array
    {
        if ($offerId === '') {
            return null;
        }

        $cached = Container::getCacheService()->get(self::key($offerId));

        return is_array($cached) && $cached !== [] ? $cached : null;
    }

    /**
     * Is this offer safe to book WITHOUT calling verify?
     *
     * Every condition must hold, and the default on anything missing is "no":
     * - the API said must_verify=false (the documented contract), and it must
     *   be present and explicitly false, not merely absent;
     * - confirmation is `immediate`, so the supplier confirms instantly;
     * - there is a real positive price to charge.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public static function isBookableWithoutVerify(?array $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }
        // Strict identity against boolean false, NOT a coerced truthiness
        // check. JSON `false` decodes to PHP bool false, so that is the only
        // shape the API can legitimately produce — while a coercing check
        // would also accept null ("field absent from this payload"), 0 and ""
        // as permission to skip. Unknown is not the same as not-required, and
        // this gate guards an order total.
        if (($snapshot['must_verify'] ?? null) !== false) {
            return false;
        }
        if (strtolower(TypeCoerce::toString($snapshot['confirmation'] ?? '')) !== 'immediate') {
            return false;
        }

        // base_price is the PRE-commission figure. Without it the skip cannot
        // hand booking_form a payload it will price correctly (see
        // toVerifyShape), so an old-shape snapshot must verify instead.
        return TypeCoerce::toFloat($snapshot['price'] ?? 0) > 0.0
            && TypeCoerce::toFloat($snapshot['base_price'] ?? 0) > 0.0;
    }

    /**
     * Re-shape a snapshot into what booking_form expects from verify.
     *
     * CRITICAL: `price` here must be the PRE-commission figure. The search
     * controllers commission the rows before caching (`original_price` keeps
     * the raw one), while booking_form takes whatever OfferAvailability
     * ::extractPrice() returns and applies commission to it. Handing over the
     * already-commissioned number would charge commission twice and quietly
     * inflate the total — the customer would see one price on the card and a
     * higher one on the booking form.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function toVerifyShape(array $snapshot): array
    {
        return [
            'offer_id' => $snapshot['offer_id'] ?? '',
            'hotel_id' => $snapshot['hotel_id'] ?? '',
            'hotel_name' => $snapshot['hotel_name'] ?? '',
            'room_name' => $snapshot['room_name'] ?? '',
            'board_name' => $snapshot['board_name'] ?? '',
            'price' => TypeCoerce::toFloat($snapshot['base_price'] ?? 0),
            'currency' => $snapshot['currency'] ?? '',
            'check_in' => $snapshot['check_in'] ?? '',
            'check_out' => $snapshot['check_out'] ?? '',
            'confirmation' => $snapshot['confirmation'] ?? '',
            'must_verify' => false,
            'cancellation_fees' => $snapshot['cancellation_fees'] ?? [],
            'payment_terms' => $snapshot['payment_terms'] ?? [],
        ];
    }

    /**
     * The kept fields. `price` is the commission-applied storefront price the
     * search already computed; `original_price` is the pre-commission figure.
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private static function snapshot(array $offer): array
    {
        return [
            'offer_id' => TypeCoerce::toString($offer['offer_id'] ?? ''),
            'hotel_id' => TypeCoerce::toString($offer['hotel_id'] ?? ''),
            'hotel_name' => TypeCoerce::toString($offer['hotel_name'] ?? ''),
            'room_name' => TypeCoerce::toString($offer['room_name'] ?? ''),
            'board_name' => TypeCoerce::toString($offer['board_name'] ?? ''),
            // `price` is what the CARD shows (commission already applied by
            // the search controller). `base_price` is the provider's own
            // figure, kept separately because booking_form re-applies
            // commission; conflating them double-charges. Absent when the
            // search row never carried original_price, and the gate above
            // then refuses to skip rather than guessing.
            'price' => TypeCoerce::toFloat($offer['price'] ?? 0),
            'base_price' => TypeCoerce::toFloat($offer['original_price'] ?? 0),
            'currency' => TypeCoerce::toString($offer['currency'] ?? ''),
            'check_in' => TypeCoerce::toString($offer['check_in'] ?? ''),
            'check_out' => TypeCoerce::toString($offer['check_out'] ?? ''),
            'confirmation' => TypeCoerce::toString($offer['confirmation'] ?? ''),
            // Kept as the raw value so "absent" stays distinguishable from
            // "false" — isBookableWithoutVerify() treats absent as unsafe.
            'must_verify' => $offer['must_verify'] ?? null,
            // Search carries is_free even though the detailed rules only load
            // on verify; it is what the terms modal falls back to.
            'cancellation_fees' => TypeCoerce::toStringMap($offer['cancellation_fees'] ?? []),
            'payment_terms' => TypeCoerce::toStringMap($offer['payment_terms'] ?? []),
        ];
    }
}
