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

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

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
    $productCode = db_get_field('SELECT product_code FROM ?:products WHERE product_id = ?i', (int) $product_id);
    if ($productCode !== '') {
        if (str_starts_with($productCode, 'NVT')) {
            $provider = TravelProviderRegistry::get('novoton');
        } elseif (str_starts_with($productCode, 'SPH')) {
            $provider = TravelProviderRegistry::get('sphinx');
        }
    }
}

// 3. Resolve by hotel_id in novoton_hotels table (numeric hotel IDs = Novoton).
// Guarded so a deactivated novoton_holidays (missing table) can't crash here.
if ($provider === null && TravelProviderRegistry::has('novoton') && !empty($hotel_id) && ctype_digit((string) $hotel_id)) {
    $exists = db_get_field('SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id = ?i LIMIT 1', (int) $hotel_id);
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

// ── booking_config: AJAX endpoint for React booking engine ──
// Returns JSON with provider, hotel_id, colors, translations.
// Called by React init() — replaces data-* attributes on mount point.
if ($mode === 'booking_config') {
    // Build the config response — determine if this product is a hotel
    $config = ['isHotel' => false];

    if (empty($product_id)) {
        $product_id = $_REQUEST['product_id'] ?? '';
    }

    if (!empty($product_id)) {
        $productCode = (string) db_get_field(
            'SELECT product_code FROM ?:products WHERE product_id = ?i',
            (int) $product_id,
        );

        $providerName = '';
        $hotelId = '';
        $searchDispatch = '';

        if ($productCode && str_starts_with($productCode, 'NVT')) {
            $providerName = 'novoton';
            $hotelId = substr($productCode, 3);
            $searchDispatch = 'novoton_booking.search';
        } elseif (TravelProviderRegistry::has('sphinx')) {
            // Sphinx: identify via sphinx_hotels table (works for any product code prefix).
            // Guarded so a deactivated sphinx_holidays (missing table) can't crash here.
            $sphinxHotelId = (string) db_get_field(
                'SELECT hotel_id FROM ?:sphinx_hotels WHERE product_id = ?i',
                (int) $product_id,
            );
            if ($sphinxHotelId !== '') {
                $providerName = 'sphinx';
                $hotelId = $sphinxHotelId;
                $searchDispatch = 'sphinx_booking.search';
            }
        }

        if ($providerName) {
            // Colors from travel_core addon settings
            $tc = \Tygh\Registry::get('addons.travel_core') ?: [];
            $colors = [];
            $colorMap = [
                'primary' => 'color_primary',
                'accent' => 'color_accent',
                'text' => 'color_text',
                'textLight' => 'color_text_light',
                'bg' => 'color_bg',
                'border' => 'color_border',
                'btnBg' => 'color_search_btn_bg',
                'btnHover' => 'color_search_btn_hover',
                'btnText' => 'color_search_btn_text',
                'calCheapest' => 'color_cal_cheapest',
                'calPrice' => 'color_cal_price',
                'danger' => 'color_danger',
            ];
            foreach ($colorMap as $jsKey => $settingKey) {
                $colors[$jsKey] = $tc[$settingKey] ?? '';
            }

            // Translations for current language
            $translationKeys = [
                'availability', 'check_in_date', 'check_out_date',
                'check_in', 'check_out', 'select_dates_message',
                'search', 'change_search', 'apply_changes',
                'adult', 'adults', 'child', 'children',
                'rooms', 'room', 'done', 'add_room',
                'adults_label', 'children_label',
                'nights_stay', 'night_stay', 'night', 'nights',
                'childrens_ages', 'child_age', 'select_age',
                'years_old', 'year_old',
                'selected', 'selected_singular', 'select_check_out',
                'january', 'february', 'march', 'april',
                'may', 'june', 'july', 'august',
                'september', 'october', 'november', 'december',
                'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
                'remove', 'please_enter_dates', 'select_check_in',
                'select_missing_ages', 'select_age_for_one_child',
                'select_age_for_children', 'calendar_price_footer',
            ];
            $translations = [];
            foreach ($translationKeys as $key) {
                $camelKey = lcfirst(str_replace('_', '', ucwords($key, '_')));
                $translations[$camelKey] = __('travel_core.' . $key);
            }

            $config = [
                'isHotel' => true,
                'provider' => $providerName,
                'hotelId' => $hotelId,
                'productId' => (int) $product_id,
                'searchDispatch' => $searchDispatch,
                'mode' => 'product',
                'colors' => $colors,
                'translations' => $translations,
            ];
        }
    }

    // Prepare JSON response BEFORE touching output buffers.
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);

    // Discard any buffered HTML (CS-Cart notices, debug bars) that would
    // corrupt the JSON. Must happen AFTER json_encode, not before.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo $json;
    exit;
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
