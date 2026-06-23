<?php

declare(strict_types=1);
/**
 * Travel Core - Cart Hook Functions
 *
 * Provider-agnostic cart/checkout hooks for travel bookings.
 * Delegates provider-specific work to the registered provider via TravelProviderRegistry.
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;
use Tygh\Addons\TravelCore\Services\BookingDisplayService;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Hook: Format cart product info for travel bookings.
 *
 * Detects travel bookings (via 'travel_booking' flag) and adds formatted display data.
 *
 * @param array<string, mixed> $product
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $auth
 */
function fn_travel_core_get_cart_product_data_post(&$product, $cart, $auth): void
{
    $extra = TypeCoerce::toStringMap($product['extra'] ?? null);
    if (!empty($extra['travel_booking'])) {
        BookingDisplayService::addBookingDisplayData($product);
    }
}

/**
 * Hook: After cart items calculated — ensure rooms_data is preserved as array.
 *
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $cart_products
 * @param array<string, mixed> $auth
 */
function fn_travel_core_calculate_cart_items_post(&$cart, &$cart_products, $auth): void
{
    foreach ($cart_products as $cart_id => &$product) {
        if (!is_array($product)) {
            continue;
        }
        /** @var array<string, mixed> $pExtra */
        $pExtra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
        if (empty($pExtra['travel_booking'])) {
            continue;
        }

        $roomsDataRaw = $pExtra['rooms_data'] ?? null;
        if (!empty($roomsDataRaw) && is_string($roomsDataRaw)) {
            $decoded = json_decode($roomsDataRaw, true);
            if (is_array($decoded)) {
                $pExtra['rooms_data'] = $decoded;
                $product['extra'] = $pExtra;
                $cartProductsList = $cart['products'] ?? null;
                if (is_array($cartProductsList) && isset($cartProductsList[$cart_id])) {
                    $cartRow = TypeCoerce::toStringMap($cartProductsList[$cart_id]);
                    $cartExtra = TypeCoerce::toStringMap($cartRow['extra'] ?? null);
                    $cartExtra['rooms_data'] = $decoded;
                    $cartRow['extra'] = $cartExtra;
                    $cartProductsList[$cart_id] = $cartRow;
                    $cart['products'] = $cartProductsList;
                }
            }
        }
    }
}

/**
 * Hook: dispatch_before_display — Load travel_core CSS for booking pages.
 */
function fn_travel_core_dispatch_before_display(): void
{
    if (!defined('AREA') || AREA !== 'C') {
        return;
    }

    $dispatch = RequestCoerce::string($_REQUEST, 'dispatch');

    // ── CSS loading for booking-related pages ──
    $booking_pages = ['travel_', 'novoton_', 'sphinx_', 'products.', 'checkout', 'cart'];
    foreach ($booking_pages as $prefix) {
        if (str_starts_with($dispatch, $prefix)) {
            $styles = TypeCoerce::toList(Registry::get('runtime.styles'));
            $css_path = 'addons/travel_core/styles.css';
            if (!in_array($css_path, $styles)) {
                $styles[] = $css_path;
                Registry::set('runtime.styles', $styles);
            }
            break;
        }
    }

    // ── Hotel product page: inject booking form mount + React scripts ──
    // This is the PRIMARY mechanism for loading the booking form.
    // It works via PHP hook (dispatch_before_display) which is reliable
    // even when Smarty template cache hasn't been cleared after deployment.
    // The Smarty hook (product_tabs.pre.tpl) serves as a secondary fallback.
    if ($dispatch === 'products.view' && !empty($_REQUEST['product_id'])) {
        $productId = ValidationHelpers::toInt($_REQUEST['product_id']);
        $productCode = ValidationHelpers::toString(db_get_field(
            'SELECT product_code FROM ?:products WHERE product_id = ?i',
            $productId,
        ));

        $hotelSeoData = TravelProviderRegistry::resolveProductOwner($productId, $productCode);
        if ($productCode !== '' && $hotelSeoData !== null) {
            $view = \Tygh\Tygh::$app['view'];
            if ($view instanceof \Smarty) {
                $view->assign('travel_booking_product_id', $productId);
                $view->assign('travel_booking_product_code', $productCode);

                // Register the React scripts via CS-Cart's inline script mechanism
                $cacheVer = TypeCoerce::toString(defined('TRAVEL_CACHE_VER') ? TRAVEL_CACHE_VER : '1');
                $baseUrl = TypeCoerce::toString(Registry::get('config.current_location'));
                $view->assign('travel_booking_scripts', [
                    $baseUrl . '/js/addons/travel_core/react-vendor.js?v=' . $cacheVer,
                    $baseUrl . '/js/addons/travel_core/react19-bundle.js?v=' . $cacheVer,
                ]);
            }
        }

        _travel_core_prepare_hotel_seo_data($productId);
    }

    // ── DEBUG MODE: append ?travel_debug=1 to any page ──
    // Runs AFTER all variable assignments so captured state is accurate.
    if (!empty($_REQUEST['travel_debug'])) {
        _travel_core_render_debug($dispatch);
    }
}

/**
 * Load hotel data for SEO (JSON-LD + OG tags) on product detail pages.
 * Stores in Registry so templates can read without $view->assign().
 */
function _travel_core_prepare_hotel_seo_data(int $productId): void
{
    $productCode = ValidationHelpers::toString(db_get_field(
        'SELECT product_code FROM ?:products WHERE product_id = ?i',
        $productId,
    ));

    if ($productCode === '') {
        return;
    }

    $hotel = TravelProviderRegistry::resolveProductOwner($productId, $productCode);
    if ($hotel === null) {
        return;
    }

    // Load product descriptions for page_title and meta_description
    $productDescRaw = db_get_row(
        'SELECT page_title, meta_description, full_description FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s',
        $productId,
        CART_LANGUAGE,
    );

    $productDesc = is_array($productDescRaw) ? $productDescRaw : [];

    // Build JSON-LD schema
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Hotel',
        'name' => $hotel->name,
        'description' => fn_travel_core_truncate_seo(strip_tags(ValidationHelpers::toString($productDesc['full_description'] ?? '')), 300),
    ];

    if ($hotel->classification !== null && $hotel->classification > 0) {
        $schema['starRating'] = ['@type' => 'Rating', 'ratingValue' => (string) $hotel->classification];
    }

    $address = [];
    if ($hotel->city !== null) {
        $address['addressLocality'] = $hotel->city;
    }
    if ($hotel->region !== null) {
        $address['addressRegion'] = $hotel->region;
    }
    if ($hotel->country !== null) {
        $address['addressCountry'] = $hotel->country;
    }
    if ($hotel->address !== null) {
        $address['streetAddress'] = $hotel->address;
    }
    if (!empty($address)) {
        $schema['address'] = array_merge(['@type' => 'PostalAddress'], $address);
    }

    if ($hotel->latitude !== null && $hotel->longitude !== null) {
        $schema['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => $hotel->latitude,
            'longitude' => $hotel->longitude,
        ];
    }

    if ($hotel->imageUrl !== null) {
        $schema['image'] = $hotel->imageUrl;
    }
    if ($hotel->phone !== null) {
        $schema['telephone'] = $hotel->phone;
    }
    if ($hotel->email !== null) {
        $schema['email'] = $hotel->email;
    }
    if ($hotel->website !== null) {
        $schema['url'] = $hotel->website;
    }

    // Assign to Smarty view — safe here because dispatch_before_display
    // runs BEFORE template rendering starts (unlike gather_additional_product_data_post
    // which runs DURING rendering and causes the Data.php:265 crash).
    $view = \Tygh\Tygh::$app['view'];
    if ($view instanceof \Smarty) {
        $view->assign('travel_hotel_schema_json', json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $view->assign('travel_og_title', $productDesc['page_title'] ?? $hotel->name);
        $view->assign('travel_og_description', $productDesc['meta_description'] ?? '');
        $view->assign('travel_og_image', $hotel->imageUrl ?? '');
        $view->assign('travel_og_type', 'hotel');
    }
}

/**
 * DEBUG: Render inline diagnostic info when ?travel_debug=1 is appended to URL.
 * Shows PHP hook status, Smarty template hook status, JS file accessibility, and DB state.
 * Remove or disable in production.
 */
function _travel_core_render_debug(string $dispatch): void
{
    $debug = [];
    $debug['timestamp'] = date('Y-m-d H:i:s');
    $debug['dispatch'] = $dispatch;
    $debug['php_version'] = PHP_VERSION;
    $debug['area'] = defined('AREA') ? AREA : 'undefined';

    // ── 1. PHP Hook status ──
    $debug['php_hook'] = [
        'fn_travel_core_dispatch_before_display' => 'RUNNING (you are seeing this)',
    ];

    // ── 2. Addon status ──
    $addons = ['travel_core', 'novoton_holidays', 'sphinx_holidays'];
    foreach ($addons as $addon) {
        $status = db_get_field('SELECT status FROM ?:addons WHERE addon = ?s', $addon);
        $debug['addon_status'][$addon] = $status ?: 'NOT INSTALLED';
    }

    // ── 3. Product info (if on product page) ──
    if ($dispatch === 'products.view' && !empty($_REQUEST['product_id'])) {
        $pid = ValidationHelpers::toInt($_REQUEST['product_id']);
        $pcode = ValidationHelpers::toString(db_get_field('SELECT product_code FROM ?:products WHERE product_id = ?i', $pid));
        $debugHotel = TravelProviderRegistry::resolveProductOwner($pid, $pcode);
        $debug['product'] = [
            'product_id'   => $pid,
            'product_code' => $pcode,
            'is_hotel'     => $debugHotel !== null,
            'provider'     => $debugHotel !== null ? $debugHotel->providerName : 'none',
            'hotel_id'     => $debugHotel !== null ? $debugHotel->hotelId : '',
        ];

        if ($debugHotel !== null) {
            $debug['product']['hotel_name'] = $debugHotel->name;
        }

        // Check Smarty variables assigned
        $view = \Tygh\Tygh::$app['view'];
        $tplVars = (is_object($view) && method_exists($view, 'getTemplateVars'))
            ? TypeCoerce::toStringMap($view->getTemplateVars())
            : [];
        $debug['smarty_vars'] = [
            'travel_booking_product_id' => $tplVars['travel_booking_product_id'] ?? 'NOT SET',
            'travel_booking_product_code' => $tplVars['travel_booking_product_code'] ?? 'NOT SET',
            'travel_booking_scripts' => isset($tplVars['travel_booking_scripts']) ? 'SET (' . count(TypeCoerce::toList($tplVars['travel_booking_scripts'])) . ' scripts)' : 'NOT SET',
            'travel_hotel_schema_json' => isset($tplVars['travel_hotel_schema_json']) ? 'SET (' . strlen(TypeCoerce::toString($tplVars['travel_hotel_schema_json'])) . ' chars)' : 'NOT SET',
        ];
    }

    // ── 4. JS file checks ──
    $jsFiles = [
        'js/addons/travel_core/react-vendor.js',
        'js/addons/travel_core/react19-bundle.js',
        'js/addons/travel_core/utils.js',
        'js/addons/travel_core/multiroom-booking.js',
        'js/addons/travel_core/dob-validation.js',
        'js/addons/travel_core/booking-form-validation.js',
    ];
    $docRoot = rtrim(TypeCoerce::toString(\Tygh\Registry::get('config.dir.root') ?? $_SERVER['DOCUMENT_ROOT']), '/');
    foreach ($jsFiles as $jsFile) {
        $fullPath = $docRoot . '/' . $jsFile;
        $debug['js_files'][$jsFile] = file_exists($fullPath)
            ? 'EXISTS (' . number_format((float) filesize($fullPath)) . ' bytes)'
            : 'MISSING';
    }

    // ── 5. Smarty template hook files ──
    $tplHooks = [
        'design/themes/responsive/templates/addons/travel_core/hooks/products/product_detail_bottom.post.tpl',
        'design/themes/responsive/templates/addons/travel_core/hooks/index/scripts.post.tpl',
        'design/themes/responsive/templates/addons/travel_core/blocks/booking_engine.tpl',
        'design/themes/responsive/templates/addons/novoton_holidays/hooks/products/product_tabs.pre.tpl',
    ];
    foreach ($tplHooks as $tplFile) {
        $fullPath = $docRoot . '/' . $tplFile;
        $debug['template_files'][$tplFile] = file_exists($fullPath)
            ? 'EXISTS (' . number_format((float) filesize($fullPath)) . ' bytes)'
            : 'MISSING';
    }

    // ── 6. Smarty cache info ──
    $cacheDir = $docRoot . '/var/cache/templates';
    if (is_dir($cacheDir)) {
        $cacheFiles = glob($cacheDir . '/*travel_core*');
        $debug['smarty_cache'] = [
            'cache_dir_exists' => true,
            'travel_core_cached_files' => count($cacheFiles ?: []),
        ];
    } else {
        $debug['smarty_cache'] = ['cache_dir_exists' => false, 'note' => 'Cache dir not found at ' . $cacheDir];
    }

    // ── 7. CSS file ──
    $cssFile = $docRoot . '/design/themes/responsive/css/addons/travel_core/styles.css';
    $debug['css_file'] = file_exists($cssFile)
        ? 'EXISTS (' . number_format((float) filesize($cssFile)) . ' bytes)'
        : 'MISSING';

    // ── Render debug output as HTML comment + visible panel ──
    $view = \Tygh\Tygh::$app['view'];
    $debugJson = json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($view instanceof \Smarty) {
        $view->assign('travel_debug_output', $debugJson);
        $view->assign('travel_debug_enabled', true);
    }

    // Also log to CS-Cart log
    fn_log_event('general', 'runtime', [
        'message' => '[travel_core DEBUG] ' . $debugJson,
    ]);
}
