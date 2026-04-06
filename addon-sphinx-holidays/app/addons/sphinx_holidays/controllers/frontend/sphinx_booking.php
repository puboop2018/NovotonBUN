<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller
 * Path: app/addons/sphinx_holidays/controllers/frontend/sphinx_booking.php
 *
 * Mode dispatcher for Sphinx hotel booking flow.
 * Follows the same pattern as novoton_booking.php.
 *
 * Modes:
 *   sphinx_booking/search.php              - Hotel availability search (polling)
 *   sphinx_booking/booking_form.php        - Verify offer, show guest form
 *   sphinx_booking/add_to_cart.php         - Create booking, add to cart
 *   sphinx_booking/ajax_recalculate_price.php - AJAX price re-verification
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

//=============================================================================
// SANITIZE $_REQUEST — flatten array params to comma-separated strings
// to prevent "Array to string conversion" warnings in CS-Cart core dispatch.
//=============================================================================
foreach (['children_ages', 'ages'] as $_sphinx_param) {
    if (isset($_REQUEST[$_sphinx_param]) && is_array($_REQUEST[$_sphinx_param])) {
        $_REQUEST[$_sphinx_param] = implode(',', array_map('intval', $_REQUEST[$_sphinx_param]));
    }
}
unset($_sphinx_param);

//=============================================================================
// UTILITY HELPERS
//=============================================================================

// Guest parsing and DOB validation are now in travel_core:
// \Tygh\Addons\TravelCore\Services\GuestDataService::parseAndValidateGuests()
// \Tygh\Addons\TravelCore\Services\GuestDataService::parseDob()

//=============================================================================
// MODE DISPATCHER
//=============================================================================

$_sphinx_mode_dir = __DIR__ . '/sphinx_booking';

if ($mode == 'search') {
    $__sphinx_result = include($_sphinx_mode_dir . '/search.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'booking_form') {
    $__sphinx_result = include($_sphinx_mode_dir . '/booking_form.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'add_to_cart') {
    $__sphinx_result = include($_sphinx_mode_dir . '/add_to_cart.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'ajax_recalculate_price') {
    include($_sphinx_mode_dir . '/ajax_recalculate_price.php');
}
