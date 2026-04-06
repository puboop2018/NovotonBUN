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

use Tygh\Registry;
use Tygh\Addons\TravelCore\Services\BookingDisplayService;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: Format cart product info for travel bookings.
 *
 * Detects travel bookings (via 'travel_booking' flag) and adds formatted display data.
 */
function fn_travel_core_get_cart_product_data_post(&$product, $cart, $auth): void
{
    if (!empty($product['extra']['travel_booking'])) {
        BookingDisplayService::addBookingDisplayData($product);
    }
}

/**
 * Hook: After cart items calculated — ensure rooms_data is preserved as array.
 */
function fn_travel_core_calculate_cart_items_post(&$cart, &$cart_products, $auth): void
{
    foreach ($cart_products as $cart_id => &$product) {
        if (empty($product['extra']['travel_booking'])) {
            continue;
        }

        if (!empty($product['extra']['rooms_data']) && is_string($product['extra']['rooms_data'])) {
            $decoded = json_decode($product['extra']['rooms_data'], true);
            if (is_array($decoded)) {
                $product['extra']['rooms_data'] = $decoded;
                if (isset($cart['products'][$cart_id])) {
                    $cart['products'][$cart_id]['extra']['rooms_data'] = $decoded;
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

    $dispatch = $_REQUEST['dispatch'] ?? '';

    // ── CSS loading for booking-related pages ──
    $booking_pages = ['travel_', 'novoton_', 'sphinx_', 'products.', 'checkout', 'cart'];
    foreach ($booking_pages as $prefix) {
        if (str_starts_with($dispatch, $prefix)) {
            $styles = Registry::get('runtime.styles') ?: [];
            $css_path = 'addons/travel_core/styles.css';
            if (!in_array($css_path, $styles)) {
                $styles[] = $css_path;
                Registry::set('runtime.styles', $styles);
            }
            break;
        }
    }

    // ── Hotel structured data (JSON-LD + OG tags) for product pages ──
    // This runs BEFORE Smarty template rendering, so Registry::set() is safe.
    if ($dispatch === 'products.view' && !empty($_REQUEST['product_id'])) {
        _travel_core_prepare_hotel_seo_data((int) $_REQUEST['product_id']);
    }
}

/**
 * Load hotel data for SEO (JSON-LD + OG tags) on product detail pages.
 * Stores in Registry so templates can read without $view->assign().
 */
function _travel_core_prepare_hotel_seo_data(int $productId): void
{
    $productCode = (string) db_get_field(
        "SELECT product_code FROM ?:products WHERE product_id = ?i",
        $productId
    );

    if ($productCode === '') {
        return;
    }

    $hotelData = null;

    // Novoton hotel (NVT prefix)
    if (str_starts_with($productCode, 'NVT')) {
        $hotelId = substr($productCode, 3);
        $hotelData = db_get_row(
            "SELECT hotel_name AS name, star_rating AS classification, hotel_type AS property_type,
                    city, region, country, latitude, longitude
             FROM ?:novoton_hotels WHERE hotel_id = ?s",
            $hotelId
        );
    }
    // Sphinx hotel (SPX prefix)
    elseif (str_starts_with($productCode, 'SPX')) {
        $hotelId = substr($productCode, 3);
        $hotelData = db_get_row(
            "SELECT name, classification, property_type,
                    destination_name AS city, region_name AS region, country_name AS country,
                    latitude, longitude, image_url, address, phone, email, website
             FROM ?:sphinx_hotels WHERE hotel_id = ?s",
            $hotelId
        );
    }

    if (empty($hotelData)) {
        return;
    }

    // Load product descriptions for page_title and meta_description
    $productDesc = db_get_row(
        "SELECT page_title, meta_description, full_description FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
        $productId,
        CART_LANGUAGE
    );

    // Build JSON-LD schema
    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'Hotel',
        'name'       => $hotelData['name'] ?? '',
        'description' => fn_travel_core_truncate_seo(strip_tags($productDesc['full_description'] ?? ''), 300),
    ];

    $stars = (int) ($hotelData['classification'] ?? 0);
    if ($stars > 0) {
        $schema['starRating'] = ['@type' => 'Rating', 'ratingValue' => (string) $stars];
    }

    $address = [];
    if (!empty($hotelData['city'])) $address['addressLocality'] = $hotelData['city'];
    if (!empty($hotelData['region'])) $address['addressRegion'] = $hotelData['region'];
    if (!empty($hotelData['country'])) $address['addressCountry'] = $hotelData['country'];
    if (!empty($hotelData['address'])) $address['streetAddress'] = $hotelData['address'];
    if (!empty($address)) {
        $schema['address'] = array_merge(['@type' => 'PostalAddress'], $address);
    }

    if (!empty($hotelData['latitude']) && !empty($hotelData['longitude'])) {
        $schema['geo'] = [
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float) $hotelData['latitude'],
            'longitude' => (float) $hotelData['longitude'],
        ];
    }

    if (!empty($hotelData['image_url'])) $schema['image'] = $hotelData['image_url'];
    if (!empty($hotelData['phone'])) $schema['telephone'] = $hotelData['phone'];
    if (!empty($hotelData['email'])) $schema['email'] = $hotelData['email'];
    if (!empty($hotelData['website'])) $schema['url'] = $hotelData['website'];

    // Assign to Smarty view — safe here because dispatch_before_display
    // runs BEFORE template rendering starts (unlike gather_additional_product_data_post
    // which runs DURING rendering and causes the Data.php:265 crash).
    $view = \Tygh\Tygh::$app['view'];
    $view->assign('travel_hotel_schema_json', json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $view->assign('travel_og_title', $productDesc['page_title'] ?? $hotelData['name']);
    $view->assign('travel_og_description', $productDesc['meta_description'] ?? '');
    $view->assign('travel_og_image', $hotelData['image_url'] ?? '');
    $view->assign('travel_og_type', 'hotel');
}
