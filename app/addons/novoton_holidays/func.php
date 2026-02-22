<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Main Functions File
 * 
 * This file includes all organized function files.
 * Functions are split into domain-specific files for better maintainability.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 * 
 * Function files included:
 * - functions/helpers.php        - Core utility functions (API, debug, price update)
 * - functions/install.php        - Installation, uninstall, upgrade functions
 * - functions/formatting.php     - Room type, board name, terms formatting
 * - functions/hotels.php         - Hotel data, sync, facilities
 * - functions/bookings.php       - Booking management, reservations, alternatives
 * - functions/email.php          - Email notifications, CSV reports
 * - functions/exchange_rates.php - BNR exchange rates, currency updates
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Get addon directory
$addon_dir = Registry::get('config.dir.addons') . 'novoton_holidays/';

// Include organized function files
$function_files = [
    'functions/helpers.php',
    'functions/install.php',
    'functions/formatting.php',
    'functions/hotels.php',
    'functions/bookings.php',
    'functions/email.php',
    'functions/exchange_rates.php',
];

foreach ($function_files as $file) {
    $path = $addon_dir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * Variants function for the api_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 * Called only from admin settings page where Registry is always populated.
 */
function fn_settings_variants_addons_novoton_holidays_api_currency(): array
{
    $currencies = Registry::get('currencies');
    $result = [];

    if (empty($currencies) || !is_array($currencies)) {
        return $result;
    }

    foreach ($currencies as $code => $currency) {
        $result[$code] = $code . (!empty($currency['symbol']) ? ' (' . $currency['symbol'] . ')' : '');
    }

    return $result;
}

// ============================================================================
// FUNCTION LOCATIONS
// ============================================================================
// All functions use the fn_novoton_holidays_* prefix per CS-Cart convention.
//
// HELPERS (functions/helpers.php):
//   fn_novoton_holidays_parse_countries()
//   fn_novoton_holidays_is_debug()
//   fn_novoton_holidays_get_api()
//   fn_novoton_holidays_update_product_prices()
//
// INSTALLATION (functions/install.php):
//   fn_novoton_holidays_uninstall()
//   fn_novoton_holidays_fix_tab_name()
//   fn_novoton_holidays_post_install()
//   fn_novoton_holidays_install_email_templates()
//   fn_novoton_holidays_upgrade_db()
//
// FORMATTING (functions/formatting.php):
//   fn_novoton_holidays_format_date()
//   fn_novoton_holidays_format_board_name()
//   fn_novoton_holidays_format_room_type()
//   fn_novoton_holidays_normalize_room_code()
//   fn_novoton_holidays_normalize_resort_name()
//   fn_novoton_holidays_parse_xml_string()
//   fn_novoton_holidays_parse_payment_terms()
//   fn_novoton_holidays_parse_cancellation_terms()
//   fn_novoton_holidays_format_payment_terms()
//   fn_novoton_holidays_format_payment_terms_with_amounts()
//   fn_novoton_holidays_format_cancellation_terms()
//   fn_novoton_holidays_get_free_cancellation_date()
//   fn_novoton_holidays_build_hotel_title()
//   fn_novoton_holidays_xml_to_array()
//
// HOTELS (functions/hotels.php):
//   fn_novoton_holidays_normalize_package()
//   fn_novoton_holidays_get_hotel_data()
//   fn_novoton_holidays_get_hotel_prices()
//   fn_novoton_holidays_get_package_priceinfo()
//   fn_novoton_holidays_get_package_priceinfo_by_name()
//   fn_novoton_holidays_get_hotels_count()
//   fn_novoton_holidays_get_hotels_no_packages_count()
//   fn_novoton_holidays_get_hotels_no_packages_by_country()
//   fn_novoton_holidays_get_hotel_id_by_product()
//   fn_novoton_holidays_get_or_create_category()
//   fn_novoton_holidays_sync_resorts_list()
//   fn_novoton_holidays_sync_facilities_list()
//   fn_novoton_holidays_sync_hotel_facilities()
//   fn_novoton_holidays_get_hotel_facilities()
//   fn_novoton_holidays_get_resorts_for_settings()
//   fn_novoton_holidays_assign_star_rating_feature()
//   fn_novoton_holidays_add_product_image()
//
// BOOKINGS (functions/bookings.php):
//   fn_novoton_holidays_decrypt_request_pii()
//   fn_novoton_holidays_decrypt_requests_pii()
//   fn_novoton_holidays_check_reservation_status()
//   fn_novoton_holidays_request_alternatives()
//   fn_novoton_holidays_get_alternatives()
//   fn_novoton_holidays_get_order_bookings()
//   fn_novoton_holidays_cron_resinfo()
//
// EMAIL (functions/email.php):
//   fn_novoton_holidays_csv_escape()
//   fn_novoton_holidays_generate_import_csv_report()
//   fn_novoton_holidays_send_import_report_email()
//   fn_novoton_holidays_cleanup_old_reports()
//   fn_novoton_holidays_generate_hotel_features_csv()
//   fn_novoton_holidays_generate_hotel_features_xml()
//
// EXCHANGE RATES (functions/exchange_rates.php):
//   fn_novoton_holidays_fetch_bnr_rates()
//   fn_novoton_holidays_parse_bnr_xml()
//   fn_novoton_holidays_calculate_currency_coefficients()
//   fn_novoton_holidays_update_cscart_currencies()
//   fn_novoton_holidays_update_exchange_rates()
//   fn_novoton_holidays_get_exchange_rate_info()
//   fn_novoton_holidays_cron_update_exchange_rates()
// ============================================================================
