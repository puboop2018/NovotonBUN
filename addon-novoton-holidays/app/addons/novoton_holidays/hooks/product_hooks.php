<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Product Hook Functions
 *
 * Responsible for:
 *   - get_products_post: Batch prefetch hotel data for product listings
 *   - gather_additional_product_data_post: No-op (Smarty 5 compatibility)
 *   - get_product_data_post: No-op (Smarty 5 compatibility)
 *   - delete_product_post: Clean up bookings when product is deleted
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: after product list is fetched.
 *
 * Batch pre-fetches hotel data for all hotel products on the page so that
 * subsequent per-product gather_additional_product_data_post calls hit the
 * in-memory cache instead of issuing 2 DB queries each (N+1 fix).
 *
 * @param list<array<string, mixed>> $products
 * @param array<string, mixed> $params
 * @param string $lang_code
 */
function fn_novoton_holidays_get_products_post(&$products, $params = [], $lang_code = ''): void
{
    if (empty($products)) {
        return;
    }

    try {
        $addon_settings = ConfigProvider::all();
        if (empty($addon_settings) || empty($addon_settings['product_code_prefixes'])) {
            return;
        }

        $hotel_ids = [];
        foreach ($products as $product) {
            if (!empty($product['product_code']) && _nvt_is_hotel_product($product, $addon_settings)) {
                $hotel_id = _nvt_extract_hotel_id($product['product_code']);
                if (!empty($hotel_id)) {
                    $hotel_ids[] = $hotel_id;
                }
            }
        }

        if (!empty($hotel_ids)) {
            fn_novoton_holidays_prefetch_hotel_data($hotel_ids);
        }
    } catch (\Throwable $e) {
        // Prefetch is optional — don't crash
    }
}

/**
 * Hook: gather additional product data - pass prices to templates
 *
 * CRITICAL: This hook runs during template rendering — inside CS-Cart's
 * {capture name="mainbox"} block in index.tpl. ANY uncaught exception
 * or PHP error here corrupts Smarty's output buffer and crashes the page
 * with "Not matching {capture}{/capture}".
 *
 * Defence layers:
 * 1. Custom error handler converts trigger_error() to ErrorException
 *    (CS-Cart's DB layer uses trigger_error for SQL errors — not catchable
 *    by try/catch alone)
 * 2. try/catch(\Throwable) catches all exceptions
 * 3. Safe Smarty defaults assigned in catch block
 *
 * @param array<string, mixed> $product
 * @param array<string, mixed> $auth
 * @param array<string, mixed> $params
 */
function fn_novoton_holidays_gather_additional_product_data_post(&$product, $auth, $params): void
{
    // ────────────────────────────────────────────────────────────────────
    // COMPLETE NO-OP.
    //
    // In Smarty 5 (CS-Cart 4.18+), this hook runs during template
    // rendering. ANY of the following crashes the page:
    //   - $view->assign() → corrupts Smarty scope chain (Data.php:265)
    //   - $product['key'] = ... → corrupts Smarty Variable wrapper
    //   - registerPlugin() → too late, compiled templates can't find it
    //
    // All hotel data is now loaded by templates themselves:
    //   - Booking form: detected from $product.product_code prefix (NVT)
    //   - Hotel prices tab: uses fn_novoton_holidays_get_tab_data()
    //     registered as a Smarty function in func.php
    // ────────────────────────────────────────────────────────────────────
}

/**
 * Hook: after getting product data
 *
 * No-op — hotel detection and data enrichment are handled entirely in
 * gather_additional_product_data_post. This hook skeleton is kept for
 * CS-Cart's hook registration system.
 *
 * @param array<string, mixed> $product_data
 * @param array<string, mixed> $auth
 * @param bool $preview
 * @param string $lang_code
 */
function fn_novoton_holidays_get_product_data_post(&$product_data, $auth, $preview, $lang_code): void
{
    if (empty($product_data)) {
        return;
    }

    // No-op: hotel detection and data enrichment are handled entirely in
    // gather_additional_product_data_post. We no longer assign addon-specific
    // keys to $product_data here to avoid polluting Smarty's $product scope
    // chain, which causes Data::getVariable() stack overflow (see #41).
}

/**
 * Hook: after deleting product
 *
 * @param int $product_id
 * @param bool $product_deleted
 */
function fn_novoton_holidays_delete_product_post($product_id, $product_deleted): void
{
    if (!$product_deleted) {
        return;
    }

    // Guard: during addon uninstall, Container or DB tables may not exist
    try {
        $bookingRepo = Container::getInstance()->bookingRepository();
        $bookingRepo->deleteByProductId($product_id);

        $hotelRepo = Container::getInstance()->hotelRepository();
        $hotelRepo->unlinkProduct($product_id);
    } catch (\Throwable $e) {
        // Silently fail — tables are being dropped during uninstall anyway
    }
}

/**
 * Hook: get_product_tabs_post
 *
 * Hide the Novoton "Hotel Prices" tab on products that are NOT Novoton
 * hotels. The tab is registered globally via ?:product_tabs (CS-Cart's
 * addon tab system), which means CS-Cart attaches it to every product
 * by default. This hook filters it out for non-Novoton products so it
 * only appears where it's relevant.
 *
 * Identification: product_code prefix (e.g. "NVT12345") via the
 * existing _nvt_extract_hotel_id() helper.
 *
 * @param int $product_id
 * @param array<string, mixed> $tabs
 */
function fn_novoton_holidays_get_product_tabs_post($product_id, &$tabs): void
{
    if (empty($tabs)) {
        return;
    }

    // Guard: skip during addon uninstall or if products table is being dropped
    try {
        $code = (string) db_get_field(
            "SELECT product_code FROM ?:products WHERE product_id = ?i",
            $product_id
        );
    } catch (\Throwable $e) {
        return;
    }

    if ($code === '' || _nvt_extract_hotel_id($code) !== null) {
        return; // Novoton product — keep the tab
    }

    // Not a Novoton product — hide the Novoton-owned tab
    foreach ($tabs as $key => $tab) {
        if (($tab['addon'] ?? '') === 'novoton_holidays') {
            unset($tabs[$key]);
        }
    }
}

// ============================================================================
// PRODUCT HELPERS (private-by-convention)
// ============================================================================

/**
 * Check if a product is a hotel product based on product_code prefix.
 *
 * @param array<string, mixed> $product        Product data (must contain 'product_code')
 * @param array<string, mixed> $addon_settings Addon settings (must contain 'product_code_prefixes')
 * @return bool
 */
function _nvt_is_hotel_product(array $product, array $addon_settings): bool
{
    if (empty($product['product_code']) || empty($addon_settings['product_code_prefixes'])) {
        return false;
    }

    $prefixes = explode(',', $addon_settings['product_code_prefixes']);

    foreach ($prefixes as $prefix) {
        $prefix = trim($prefix);
        if (!empty($prefix) && str_starts_with($product['product_code'], $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Extract hotel ID from product code by stripping known prefixes.
 *
 * @param string $product_code e.g. "NVT12345"
 * @return string|null Hotel ID or null
 */
function _nvt_extract_hotel_id(string $product_code): ?string
{
    foreach (\Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getProductCodePrefixes() as $prefix) {
        if (!empty($prefix) && str_starts_with($product_code, $prefix)) {
            $remainder = substr($product_code, strlen($prefix));
            // Strip optional separator (e.g. "NVT-12345" legacy format)
            $remainder = ltrim($remainder, '-');
            return $remainder !== '' ? $remainder : null;
        }
    }

    // Fallback: extract first digit sequence
    preg_match('/\d+/', $product_code, $matches);
    return $matches[0] ?? null;
}
