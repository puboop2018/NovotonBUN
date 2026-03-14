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
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: Format cart product info for travel bookings.
 *
 * Detects travel bookings (via 'travel_booking' or legacy 'novoton_booking' flag)
 * and adds formatted display data.
 */
function fn_travel_core_get_cart_product_data_post(&$product, $cart, $auth): void
{
    if (!empty($product['extra']['travel_booking']) || !empty($product['extra']['novoton_booking'])) {
        BookingDisplayService::addBookingDisplayData($product);
    }
}

/**
 * Hook: After cart items calculated — ensure rooms_data is preserved as array.
 */
function fn_travel_core_calculate_cart_items_post(&$cart, &$cart_products, $auth): void
{
    foreach ($cart_products as $cart_id => &$product) {
        if (empty($product['extra']['travel_booking']) && empty($product['extra']['novoton_booking'])) {
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
    if (defined('AREA') && AREA === 'C') {
        $dispatch = $_REQUEST['dispatch'] ?? '';
        $booking_pages = ['travel_', 'novoton_', 'sphinx_', 'products.', 'checkout', 'cart'];
        $needs_css = false;

        foreach ($booking_pages as $prefix) {
            if (strpos($dispatch, $prefix) === 0) {
                $needs_css = true;
                break;
            }
        }

        if ($needs_css) {
            $styles = Registry::get('runtime.styles') ?: [];
            $css_path = 'addons/travel_core/styles.css';
            if (!in_array($css_path, $styles)) {
                $styles[] = $css_path;
                Registry::set('runtime.styles', $styles);
            }
        }
    }
}
