<?php
declare(strict_types=1);
/**
 * Travel Core - Booking Controller Dispatcher
 *
 * Routes booking requests to the appropriate provider-specific controller
 * based on the hotel ID prefix (e.g., "novoton_12345" → novoton_holidays provider).
 *
 * Supported modes:
 *   - booking_form: Display the booking form (provider-specific)
 *   - add_to_cart: Process booking form submission (provider-specific)
 *   - search: Search for availability (provider-specific)
 *
 * If no provider is identified, falls back to a generic error.
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

$mode = $_REQUEST['mode'] ?? '';
$hotel_id = $_REQUEST['hotel_id'] ?? '';

// Resolve the provider for this hotel
$provider = null;
if (!empty($hotel_id)) {
    $provider = TravelProviderRegistry::getProviderForHotel($hotel_id);
}

// If no provider found from hotel_id, check for explicit provider parameter
if ($provider === null && !empty($_REQUEST['provider'])) {
    $providerName = $_REQUEST['provider'];
    $provider = TravelProviderRegistry::get($providerName);
}

if ($provider === null) {
    // Fallback: check all registered providers and try to dispatch to the first one
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
