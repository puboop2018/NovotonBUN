<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Builds the Sphinx book* request payload.
 *
 * ONE place owns the booking wire format for both submission paths — the
 * order-placement hook (fn_sphinx_holidays_place_order_post) and the admin
 * retry (BookingRetryService). Before this factory the two had drifted: the
 * retry path invoked occupancy-builder functions that never existed anywhere
 * in the codebase, so every retry fataled into its catch block and re-marked
 * the booking failed.
 *
 * The shape is the one the live integration submits successfully:
 *   offer_id, guests (keyed room{N}_{type}_{i} map), contact{email,phone},
 *   reference_code (the CS-Cart order id, when known).
 *
 * @package SphinxHolidays
 */
final class BookingPayloadFactory
{
    /**
     * @param array<string, mixed> $guestsData Keyed guests map (decoded guests_data)
     * @param array<string, mixed> $contact ['email' => ..., 'phone' => ...]
     * @return array<string, mixed>
     */
    public static function build(string $offerId, array $guestsData, array $contact, int $orderId = 0): array
    {
        $payload = [
            'offer_id' => $offerId,
            'guests' => $guestsData,
            'contact' => [
                'email' => TypeCoerce::toString($contact['email'] ?? ''),
                'phone' => TypeCoerce::toString($contact['phone'] ?? ''),
            ],
        ];

        if ($orderId > 0) {
            $payload['reference_code'] = (string) $orderId;
        }

        return $payload;
    }

    /**
     * Resolve booking contact from a CS-Cart order's user data.
     *
     * The storefront no longer asks for email/phone on the guest form —
     * checkout already collects them — so the order is the authoritative
     * source. Falls back through the CS-Cart phone variants (profile,
     * billing, shipping).
     *
     * @param array<string, mixed> $userData Order/cart user_data
     * @return array{email: string, phone: string}
     */
    public static function contactFromUserData(array $userData): array
    {
        $phone = TypeCoerce::toString($userData['phone'] ?? '');
        if ($phone === '') {
            $phone = TypeCoerce::toString($userData['b_phone'] ?? '');
        }
        if ($phone === '') {
            $phone = TypeCoerce::toString($userData['s_phone'] ?? '');
        }

        return [
            'email' => TypeCoerce::toString($userData['email'] ?? ''),
            'phone' => $phone,
        ];
    }
}
