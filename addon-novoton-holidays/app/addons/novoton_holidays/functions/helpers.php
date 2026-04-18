<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Core Helper Functions
 * 
 * Common utility functions used across the addon.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Parse selected countries from addon settings
 * 
 * If no countries are selected, returns ALL available countries.
 * If countries are selected, returns only those countries.
 * 
 * @param mixed $selected_countries Countries from settings (array or comma-separated string)
 * @return list<string> List of country names in uppercase
 */
function fn_novoton_holidays_parse_countries(mixed $selected_countries = null): array
{
    // When called without argument, delegate to ConfigProvider (single source of truth)
    if ($selected_countries === null) {
        return array_values(ConfigProvider::getSelectedCountries());
    }

    $countries = [];

    // Parse the selected countries from raw settings value
    if (is_array($selected_countries)) {
        foreach ($selected_countries as $key => $value) {
            if ($value === 'Y' || $value === '1' || $value === 1) {
                $countries[] = strtoupper(trim($key));
            } elseif (is_string($value) && strlen($value) > 2) {
                $countries[] = strtoupper(trim($value));
            }
        }
    } elseif (!empty($selected_countries) && is_string($selected_countries)) {
        $countries = array_map(function($c) {
            return strtoupper(trim($c));
        }, explode(',', $selected_countries));
    }

    $countries = array_values(array_filter($countries));

    // If no countries resolved, fallback to all available countries
    if (empty($countries)) {
        return Constants::COUNTRIES;
    }

    return $countries;
}

/**
 * Check if debug mode is enabled
 * 
 * @return bool
 */
function fn_novoton_holidays_is_debug(): bool
{
    // Debug via URL parameter requires authenticated admin session
    if (!empty($_REQUEST['debug_novoton'])) {
        $auth = \Tygh\Tygh::$app['session']['auth'] ?? [];
        if (!empty($auth['user_id']) && ($auth['area'] ?? '') === 'A') {
            return true;
        }
        // Silently ignore for non-admin users (no information leak)
    }

    return ConfigProvider::isDebugMode();
}

/**
 * Get Novoton API instance (singleton)
 * 
 * @return NovotonApi|null
 */
/**
 * Add a pre-built cart-product row to the current session's cart.
 *
 * Thin wrapper around CS-Cart's procedural trio
 * (fn_add_product_to_cart / fn_save_cart_content / fn_calculate_cart_content)
 * that absorbs the reference-based `$cart` + `$auth` handling required by
 * those APIs. Living in `functions/` (the allowlisted boundary) keeps the
 * `\Tygh::$app` access out of service classes like BookingService.
 *
 * @param array<string, mixed> $product
 */
function fn_novoton_holidays_add_to_session_cart(array $product): void
{
    // Narrow the session array once so the reference binds below see a
    // typed array shape (CS-Cart's reference-based cart flow needs live
    // refs into \Tygh::\$app['session'] for `fn_add_product_to_cart` and
    // friends to persist their mutations).
    /** @var array<string, mixed> $session */
    $session = is_array(\Tygh\Tygh::$app['session'] ?? null) ? \Tygh\Tygh::$app['session'] : [];
    $session['cart'] = is_array($session['cart'] ?? null) ? $session['cart'] : [];
    $session['auth'] = is_array($session['auth'] ?? null) ? $session['auth'] : [];
    \Tygh\Tygh::$app['session'] = $session;

    $cart = &\Tygh\Tygh::$app['session']['cart'];
    $auth = &\Tygh\Tygh::$app['session']['auth'];

    fn_add_product_to_cart($product, $cart, $auth);
    $userId = is_numeric($auth['user_id'] ?? null) ? (int) $auth['user_id'] : 0;
    fn_save_cart_content($cart, $userId);
    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
}

/**
 * Broadcast the search / listing page title + blank meta description/keywords
 * into CS-Cart's dynamic navigation state.
 *
 * Consolidates the four Registry::set() writes that used to live in
 * SearchResultFormatter::assignMeta(). Living here lets that service class
 * stay free of global-state access (Wave 2 PR 3 of the structured-shape
 * plan) — services receive page-title via method args and delegate the
 * broadcast to this helper at the functions/ boundary.
 */
function fn_novoton_holidays_set_dynamic_page_meta(string $pageTitle): void
{
    Registry::set('navigation.dynamic.page_title', $pageTitle);
    Registry::set('navigation.dynamic.meta_description', '');
    Registry::set('navigation.dynamic.meta_keywords', '');
    Registry::set('runtime.page_title', $pageTitle);
}

function fn_novoton_holidays_get_api(): ?NovotonApi
{
    static $api = null;
    
    if ($api === null) {
        $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
        
        if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi')) {
            if (file_exists($src_dir . 'NovotonApi.php')) {
                require_once($src_dir . 'NovotonApi.php');
            } else {
                return null;
            }
        }
        
        try {
            $api = new NovotonApi();
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', 'Failed to initialize API: ' . $e->getMessage());
            return null;
        }
    }
    
    return $api;
}

/**
 * Update product prices from API
 * V3: Stores packages in novoton_hotel_packages table
 *
 * @param int $product_id Product ID
 * @return bool|string True on success, 'no_data', or false on failure
 */
function fn_novoton_holidays_update_product_prices($product_id): bool|string
{
    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        return false;
    }

    try {
        // Get product info
        $product = db_get_row(
            "SELECT p.product_id, p.product_code, pd.product
             FROM ?:products AS p
             LEFT JOIN ?:product_descriptions AS pd ON p.product_id = pd.product_id AND pd.lang_code = ?s
             WHERE p.product_id = ?i",
            CART_LANGUAGE,
            $product_id
        );

        if (empty($product)) {
            return false;
        }

        // Get hotel_id from novoton_hotels table
        $hotel_id = fn_novoton_holidays_get_hotel_id_by_product($product_id);

        if (empty($hotel_id)) {
            // Try extracting from product code (NVT-XXXX or NVT format)
            if (!empty($product['product_code'])) {
                preg_match('/\d+/', $product['product_code'], $matches);
                $hotel_id = $matches[0] ?? null;
            }
        }

        if (empty($hotel_id)) {
            return 'no_data';
        }

        // Get hotel info from API (includes packages)
        $hotel_info = $api->hotels()->getHotelInfo($hotel_id);

        if (empty($hotel_info) || !isset($hotel_info->packages)) {
            return 'no_data';
        }

        // Convert to array
        $hotelData = json_decode((string) json_encode($hotel_info), true);

        // Normalize packages array
        $packages = [];
        if (isset($hotelData['packages']['IdCont'])) {
            $packages = [$hotelData['packages']];
        } elseif (!empty($hotelData['packages'])) {
            $packages = $hotelData['packages'];
        }

        if (empty($packages)) {
            return 'no_data';
        }

        $packagesUpdated = 0;

        // Store each package in novoton_hotel_packages, flag for cron recomputation,
        // and compute min_price inline for immediate product price update.
        $lowestPrice = null;

        foreach ($packages as $pkg) {
            $packageId = $pkg['IdCont'] ?? '';
            $packageName = $pkg['PackageName'] ?? '';

            if (empty($packageId) || empty($packageName)) {
                continue;
            }

            // Get priceinfo for this package
            $priceInfo = $api->pricing()->getPriceInfo($hotel_id, $packageName);
            $priceData = !empty($priceInfo) ? json_decode((string) json_encode($priceInfo), true) : [];

            $priceinfoValue = !empty($priceData) ? json_encode($priceData) : '';
            $now = date('Y-m-d H:i:s');

            // Atomic upsert — flag for recomputation by compute_prices cron
            db_query(
                "INSERT INTO ?:novoton_hotel_packages
                 (hotel_id, package_id, package_name, priceinfo_data, needs_price_compute, synced_at)
                 VALUES (?s, ?s, ?s, ?s, 'Y', ?s) AS new_row
                 ON DUPLICATE KEY UPDATE
                 package_name = new_row.package_name,
                 priceinfo_data = new_row.priceinfo_data,
                 needs_price_compute = 'Y',
                 synced_at = new_row.synced_at",
                (string) $hotel_id,
                (string) $packageId,
                (string) $packageName,
                $priceinfoValue,
                $now
            );

            // Compute min_price using canonical method for immediate product price update
            if (!empty($priceData)) {
                $mp = \Tygh\Addons\NovotonHolidays\Cron\Commands\PriceComputeCommand::extractMinPrice($priceData);
                if ($mp !== null && ($lowestPrice === null || $mp < $lowestPrice)) {
                    $lowestPrice = $mp;
                }
            }

            $packagesUpdated++;
        }

        // Update hotel record (packages_count only — has_room_price is set exclusively by room_price check)
        $update_data = [
            'packages_count' => $packagesUpdated,
        ];
        db_query("UPDATE ?:novoton_hotels SET ?u WHERE hotel_id = ?s", $update_data, $hotel_id);

        // Update CS-Cart product price immediately (don't wait for compute_prices cron)
        if ($lowestPrice !== null && $lowestPrice > 0) {
            $commission = ConfigProvider::getCommission();
            $withComm = $lowestPrice * (1 + ($commission / 100));
            $withComm = ConfigProvider::isRoundPrices() ? round($withComm) : round($withComm, 2);
            db_query("UPDATE ?:products SET price = ?d WHERE product_id = ?i", $withComm, $product_id);
        }

        return $packagesUpdated > 0 ? true : 'no_data';

    } catch (\Exception $e) {
        fn_log_event('general', 'runtime', 'Price update failed: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// XML PRICE MATCHING
// ============================================================================

/**
 * Match a room/board price from a flat XML price response.
 *
 * Searches for //Price, //IdRoom, and //IdBoard (or //Board) elements
 * and returns the first match for the given room/board IDs using
 * case-insensitive comparison with URL-decoded room IDs.
 *
 * @param \SimpleXMLElement $xml      XML response from room_price API
 * @param string|null       $room_id  Room ID to match (already URL-decoded), or null/empty to match any
 * @param string|null       $board_id Board ID to match, or null/empty to match any
 * @return array<string, mixed>|null ['price' => float, 'room' => string, 'board' => string] or null if no match
 */
function fn_novoton_match_price_from_xml(\SimpleXMLElement $xml, ?string $room_id, ?string $board_id): ?array
{
    $prices  = $xml->xpath('//Price');
    $idRooms = $xml->xpath('//IdRoom');
    // API may use //IdBoard or //Board depending on endpoint
    $idBoards = $xml->xpath('//IdBoard');
    if (empty($idBoards)) {
        $idBoards = $xml->xpath('//Board');
    }

    if (empty($prices) || empty($idRooms) || empty($idBoards)) {
        return null;
    }

    $numResults = min(count($prices), count($idRooms), count($idBoards));

    for ($i = 0; $i < $numResults; $i++) {
        $resultPrice = (float)((string)$prices[$i]);
        $resultRoom  = rawurldecode((string)$idRooms[$i]);
        $resultBoard = (string)$idBoards[$i];

        $roomMatches  = empty($room_id) ||
                        strcasecmp($resultRoom, $room_id) === 0;

        $boardMatches = empty($board_id) ||
                        strcasecmp($resultBoard, $board_id) === 0;

        if ($roomMatches && $boardMatches && $resultPrice > 0) {
            return [
                'price' => $resultPrice,
                'room'  => $resultRoom,
                'board' => $resultBoard,
            ];
        }
    }

    return null;
}

// ============================================================================
// STREAMING HTML HELPERS
// ============================================================================

/**
 * Output the opening HTML for a streaming progress page.
 *
 * Used by controllers that echo real-time progress (check_prices, check_packages,
 * add_hotels_as_products, etc.) so they share one consistent layout and CSS.
 *
 * @param string $title  Page title
 * @param string $extra_css  Optional additional CSS rules
 */
function fn_novoton_holidays_stream_page_open(string $title, string $extra_css = ''): void
{
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #003580; margin-top: 0; }
        .log { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .skip { color: #999; }
        .resort-header { color: #003580; font-weight: bold; margin-top: 8px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        .country-header { color: #fff; background: #003580; font-weight: bold; margin-top: 12px; padding: 6px 10px; border-radius: 4px; font-size: 13px; }
        .btn { display: inline-block; padding: 10px 20px; background: #003580; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; margin-right: 10px; }
        .btn-run { background: #28a745; font-size: 14px; border: none; cursor: pointer; color: white; padding: 10px 25px; border-radius: 4px; }
        .progress { margin: 10px 0; padding: 8px; background: #e3f2fd; border-radius: 4px; font-weight: bold; }
        .form-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: bold; color: #333; }
        .form-group input, .form-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .info-box { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
        .country-checkboxes { display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .country-checkboxes label { font-size: 12px; font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 4px; }
        .country-checkboxes input[type="checkbox"] { margin: 0; }
        .summary-table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        .summary-table th, .summary-table td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; font-size: 12px; }
        .summary-table th { background: #f8f9fa; color: #003580; }
        .hint { color: #666; font-size: 13px; }
        ' . $extra_css . '
    </style></head><body><div class="container"><h1>' . htmlspecialchars($title) . '</h1>';
}

/**
 * Output the closing HTML for a streaming progress page.
 *
 * @param string $back_url  URL for the "Back" button (defaults to manage page)
 * @param list<array{url: string, label: string}>  $extra_buttons  Optional additional buttons
 */
function fn_novoton_holidays_stream_page_close(string $back_url = '', array $extra_buttons = []): void
{
    if (empty($back_url)) {
        $back_url = fn_url('novoton_holidays.manage');
    }

    echo '<a href="' . htmlspecialchars($back_url) . '" class="btn">&larr; Back</a>';

    foreach ($extra_buttons as $btn) {
        echo '<a href="' . htmlspecialchars($btn['url']) . '" class="btn">' . htmlspecialchars($btn['label']) . '</a>';
    }

    echo '</div></body></html>';
}

/**
 * Build a hidden-field set from a CS-Cart fn_url() result.
 *
 * Streaming forms need to preserve the dispatch & security hash that
 * fn_url() returns as query parameters. This helper generates the
 * hidden inputs so they don't have to be repeated in every controller.
 *
 * @param string $dispatch_url  Full URL from fn_url()
 * @return array{action: string, hidden_fields: string}
 */
function fn_novoton_holidays_stream_form_fields(string $dispatch_url): array
{
    $action = htmlspecialchars((string) strtok($dispatch_url, '?'));
    $hidden = '';
    $url_parts = parse_url($dispatch_url);

    if (!empty($url_parts['query'])) {
        parse_str($url_parts['query'], $qs);
        foreach ($qs as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $hidden .= '<input type="hidden" name="' . htmlspecialchars((string) $k) . '" value="' . htmlspecialchars($v) . '">';
        }
    }

    return ['action' => $action, 'hidden_fields' => $hidden];
}