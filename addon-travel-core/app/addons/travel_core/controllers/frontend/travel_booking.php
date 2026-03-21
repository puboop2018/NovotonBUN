<?php
declare(strict_types=1);
/**
 * Travel Core - Booking Controller Dispatcher
 *
 * Generic entry point that routes to the correct provider-specific controller.
 * In practice, most bookings go directly to novoton_booking.* or sphinx_booking.*
 * via data-search-dispatch on the React mount element. This dispatcher serves as
 * a fallback for generic links (e.g. travel_booking.search?provider=novoton).
 *
 * Resolution order:
 *   1. Explicit ?provider=novoton/sphinx parameter
 *   2. Product code prefix lookup (NVT* → novoton, SPH* → sphinx)
 *   3. Single-provider fallback (if only one provider is active)
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// CS-Cart auto-sets $mode from dispatch URL (e.g., dispatch=travel_booking.booking_form → $mode = 'booking_form')
// Do NOT overwrite $mode from $_REQUEST — that causes routing issues.
$hotel_id = $_REQUEST['hotel_id'] ?? '';
$product_id = $_REQUEST['product_id'] ?? '';

// 1. Explicit provider parameter (most reliable)
$provider = null;
if (!empty($_REQUEST['provider'])) {
    $provider = TravelProviderRegistry::get($_REQUEST['provider']);
}

// 2. Resolve by product code prefix (NVT* → novoton, SPH* → sphinx)
if ($provider === null && !empty($product_id)) {
    $productCode = db_get_field("SELECT product_code FROM ?:products WHERE product_id = ?i", (int) $product_id);
    if ($productCode !== '') {
        if (str_starts_with($productCode, 'NVT')) {
            $provider = TravelProviderRegistry::get('novoton');
        } elseif (str_starts_with($productCode, 'SPH')) {
            $provider = TravelProviderRegistry::get('sphinx');
        }
    }
}

// 3. Resolve by hotel_id in novoton_hotels table (numeric hotel IDs = Novoton)
if ($provider === null && !empty($hotel_id) && ctype_digit((string) $hotel_id)) {
    $exists = db_get_field("SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id = ?i LIMIT 1", (int) $hotel_id);
    if ($exists) {
        $provider = TravelProviderRegistry::get('novoton');
    }
}

// 4. Single-provider fallback
if ($provider === null) {
    $allProviders = TravelProviderRegistry::all();
    if (count($allProviders) === 1) {
        $provider = reset($allProviders);
    }
}

if ($provider !== null) {
    $providerName = $provider['name'];

    // Each provider addon has its own booking controller following the convention: {name}_booking
    $targetController = $providerName . '_booking';

    // Redirect to provider-specific controller with all parameters preserved
    $params = $_REQUEST;
    unset($params['dispatch']);
    $queryString = !empty($params) ? '?' . http_build_query($params) : '';

    return [CONTROLLER_STATUS_REDIRECT, $targetController . '.' . $mode . $queryString];
}

// No provider found — show error
fn_set_notification('E', __('error'), 'Unable to determine travel provider for this booking.');
return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
