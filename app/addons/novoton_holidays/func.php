<?php
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
 * - functions/helpers.php    - Core utility functions (API, debug, price update)
 * - functions/install.php    - Installation, uninstall, upgrade functions
 * - functions/formatting.php - Room type, board name, terms formatting
 * - functions/hotels.php     - Hotel data, sync, facilities
 * - functions/bookings.php   - Booking management, reservations, alternatives
 * - functions/email.php      - Email notifications, CSV reports
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
];

foreach ($function_files as $file) {
    $path = $addon_dir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// ============================================================================
// BACKWARD COMPATIBILITY
// ============================================================================
// The following section ensures backward compatibility with any code that
// might be calling functions that were previously in this file but are now
// in the organized function files. All functions are now available through
// the included files above.
// ============================================================================

/**
 * Note: All functions have been moved to organized files in the functions/ directory.
 * 
 * Function locations:
 * 
 * HELPERS (functions/helpers.php):
 * - fn_novoton_is_debug()
 * - fn_novoton_get_api()
 * - fn_novoton_holidays_update_product_prices()
 * 
 * INSTALLATION (functions/install.php):
 * - fn_novoton_holidays_install()
 * - fn_novoton_holidays_uninstall()
 * - fn_novoton_holidays_fix_tab_name()
 * - fn_novoton_holidays_post_install()
 * - fn_novoton_holidays_install_email_templates()
 * - fn_novoton_holidays_upgrade_db()
 * 
 * FORMATTING (functions/formatting.php):
 * - fn_novoton_format_board_name()
 * - fn_novoton_format_room_type()
 * - fn_novoton_parse_payment_terms()
 * - fn_novoton_parse_cancellation_terms()
 * - fn_novoton_format_payment_terms()
 * - fn_novoton_format_cancellation_terms()
 * - fn_novoton_get_free_cancellation_date()
 * - fn_novoton_build_hotel_title()
 * - fn_novoton_xml_to_array()
 * 
 * HOTELS (functions/hotels.php):
 * - fn_novoton_get_hotel_data()
 * - fn_novoton_get_hotel_prices()
 * - fn_novoton_get_hotels_count()
 * - fn_novoton_get_hotels_no_packages_count()
 * - fn_novoton_get_hotels_no_packages_by_country()
 * - fn_novoton_get_hotel_id_by_product()
 * - fn_novoton_get_or_create_category()
 * - fn_novoton_sync_facilities_list()
 * - fn_novoton_sync_hotel_facilities()
 * - fn_novoton_get_hotel_facilities()
 * - fn_novoton_get_resorts_for_settings()
 * - fn_novoton_sync_resorts_from_api()
 * - fn_novoton_update_resort_stats()
 * - fn_novoton_assign_star_rating_feature()
 * 
 * BOOKINGS (functions/bookings.php):
 * - fn_novoton_check_reservation_status()
 * - fn_novoton_request_alternatives()
 * - fn_novoton_get_alternatives()
 * - fn_novoton_get_order_bookings()
 * - fn_novoton_calculate_price()
 * - fn_novoton_get_stored_price()
 * - fn_novoton_cron_resinfo()
 * 
 * EMAIL (functions/email.php):
 * - fn_novoton_generate_import_csv_report()
 * - fn_novoton_send_import_report_email()
 * - fn_novoton_cleanup_old_reports()
 * - fn_novoton_generate_hotel_features_csv()
 */
