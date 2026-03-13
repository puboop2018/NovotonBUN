<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024 VacanteLitoral.ro                                            *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/hooks.php                       *
 *                                                                          *
 *   Dispatcher: includes domain-specific hook files.                       *
 *   Each file owns a single responsibility domain:                         *
 *                                                                          *
 *   hooks/product_hooks.php — product data enrichment, tabs, deletion      *
 *   hooks/order_hooks.php   — place_order, get_orders, get_order_info      *
 *   hooks/cart_hooks.php    — cart calculation, checkout, display, meta     *
 *   hooks/auth_hooks.php    — user_login_post, create_user_post            *
 *                                                                          *
 *   Hook functions:                                                        *
 *                                                                          *
 *   PRODUCT (product_hooks.php):                                           *
 *     fn_novoton_holidays_get_products_post()                              *
 *     fn_novoton_holidays_gather_additional_product_data_post()            *
 *     fn_novoton_holidays_get_product_data_post()                          *
 *     fn_novoton_holidays_delete_product_post()                            *
 *                                                                          *
 *   ORDER (order_hooks.php):                                               *
 *     fn_novoton_holidays_pre_place_order()                                *
 *     fn_novoton_holidays_place_order_post()                               *
 *     fn_novoton_holidays_get_orders_post()                                *
 *     fn_novoton_holidays_get_order_info()                                 *
 *                                                                          *
 *   CART (cart_hooks.php):                                                 *
 *     fn_novoton_holidays_get_cart_product_data_post()                     *
 *     fn_novoton_holidays_calculate_cart_items()                           *
 *     fn_novoton_holidays_calculate_cart_items_post()                      *
 *     fn_novoton_holidays_checkout_pre_dispatch()                          *
 *     fn_novoton_holidays_dispatch_before_display()                        *
 *     smarty_modifier_json_decode()                                        *
 *     fn_novoton_holidays_add_booking_display_data()                                *
 *                                                                          *
 *   AUTH (auth_hooks.php):                                                 *
 *     fn_novoton_holidays_user_login_post()                                *
 *     fn_novoton_holidays_create_user_post()                               *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Include domain-specific hook files
$hooks_dir = __DIR__ . '/hooks/';

$hook_files = [
    'product_hooks.php',    // Product data enrichment, tabs, deletion
    'order_hooks.php',      // pre_place_order, place_order, get_orders_post, get_order_info
    'cart_hooks.php',       // Cart calculation, checkout display, meta, CSS
    'auth_hooks.php',       // user_login_post, create_user_post
];

foreach ($hook_files as $file) {
    $path = $hooks_dir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}
