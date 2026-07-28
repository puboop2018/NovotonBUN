<?php

declare(strict_types=1);

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;

/**
 * Reverse-geocoding configuration, stored where an existing install can
 * actually reach it.
 *
 * These three values are declared in addon.xml, but CS-Cart only reads that
 * file at install/upgrade time — it does NOT re-read it on a code deploy or a
 * cache clear. So on every store that installed travel_core before the
 * geocoding section existed, the settings rows are simply absent and the
 * admin has no checkbox to tick. (Confirmed in the field: the Travel Core
 * settings page ended at "Display Settings".)
 *
 * The fix mirrors what the booking-form colors already do: ?:storage_data is
 * the source of truth — it survives cache clears, is shared by both areas,
 * and needs no addon upgrade — while the addon.xml settings are still read as
 * a fallback so installs where the rows DO exist keep working, and so a fresh
 * install behaves identically.
 *
 * Read order for every getter: storage → Registry (addon settings) → default.
 */

const TRAVEL_CORE_GEOCODING_STORAGE_KEY = 'travel_core_geocoding';

/**
 * All three geocoding values, storage first.
 *
 * @return array{enabled: bool, contact_email: string, endpoint: string}
 */
function fn_travel_core_get_geocoding_settings(): array
{
    $stored = [];
    if (function_exists('fn_get_storage_data')) {
        $raw = fn_get_storage_data(TRAVEL_CORE_GEOCODING_STORAGE_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
    }

    $registry = \Tygh\Registry::get('addons.travel_core');
    $registry = is_array($registry) ? $registry : [];

    $pick = static function (string $key) use ($stored, $registry): string {
        if (array_key_exists($key, $stored)) {
            return trim(TypeCoerce::toString($stored[$key]));
        }

        return trim(TypeCoerce::toString($registry[$key] ?? ''));
    };

    $endpoint = $pick('geocoding_endpoint');

    return [
        'enabled' => $pick('geocoding_enabled') === 'Y',
        'contact_email' => $pick('geocoding_contact_email'),
        'endpoint' => $endpoint !== '' ? $endpoint : NominatimClient::DEFAULT_ENDPOINT,
    ];
}

/**
 * Persist the three values to ?:storage_data (and mirror into the Registry so
 * the rest of THIS request sees them).
 *
 * @param array{enabled?: bool, contact_email?: string, endpoint?: string} $values
 */
function fn_travel_core_save_geocoding_settings(array $values): void
{
    $row = [
        'geocoding_enabled' => !empty($values['enabled']) ? 'Y' : 'N',
        'geocoding_contact_email' => trim(TypeCoerce::toString($values['contact_email'] ?? '')),
        'geocoding_endpoint' => trim(TypeCoerce::toString($values['endpoint'] ?? '')),
    ];

    if (function_exists('fn_set_storage_data')) {
        fn_set_storage_data(TRAVEL_CORE_GEOCODING_STORAGE_KEY, (string) json_encode($row));
    }

    $existing = \Tygh\Registry::get('addons.travel_core');
    \Tygh\Registry::set('addons.travel_core', array_merge(is_array($existing) ? $existing : [], $row));
}
