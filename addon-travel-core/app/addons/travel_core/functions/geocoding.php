<?php

declare(strict_types=1);

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;

/**
 * Reverse-geocoding configuration.
 *
 * The three values live in the normal place — Settings → Travel Core →
 * Geocoding — and SettingsMigrator guarantees those rows exist even on stores
 * installed before the section was declared (CS-Cart imports addon.xml only
 * at install/upgrade). That is the single source of truth and the only place
 * an admin edits them.
 *
 * ?:storage_data is read only as a LEGACY fallback, for the window when the
 * values were captured on the Tools page because the settings rows did not
 * exist yet. It is consulted solely when the setting is absent from the
 * Registry altogether — never to override a real setting, so an admin who
 * clears a field in Settings gets an empty field, not a resurrected old value.
 *
 * Read order per value: settings → storage (legacy) → default.
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

    // The setting WINS whenever it exists — including when it is empty, which
    // is a real choice an admin can make. Storage answers only for a store
    // whose settings rows have not been healed into existence yet.
    $pick = static function (string $key) use ($stored, $registry): string {
        if (array_key_exists($key, $registry)) {
            return trim(TypeCoerce::toString($registry[$key]));
        }

        return trim(TypeCoerce::toString($stored[$key] ?? ''));
    };

    $endpoint = $pick('geocoding_endpoint');

    return [
        'enabled' => $pick('geocoding_enabled') === 'Y',
        'contact_email' => $pick('geocoding_contact_email'),
        'endpoint' => $endpoint !== '' ? $endpoint : NominatimClient::DEFAULT_ENDPOINT,
    ];
}
