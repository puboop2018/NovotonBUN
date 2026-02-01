<?php
/**
 * Novoton Holidays - Admin Permissions Schema
 *
 * Registers all backend controller modes so CS-Cart allows access.
 */

// novoton_holidays controller modes
$schema['novoton_holidays'] = [
    'modes' => [
        'manage'                  => ['permissions' => 'manage_catalog'],
        'add_hotels_as_products'  => ['permissions' => 'manage_catalog'],
        'view_hotels_to_add'      => ['permissions' => 'manage_catalog'],
        'list_facilities'         => ['permissions' => 'manage_catalog'],
        'sync_facilities'         => ['permissions' => 'manage_catalog'],
        'check_packages'          => ['permissions' => 'manage_catalog'],
        'update_prices'           => ['permissions' => 'manage_catalog'],
        'check_prices'            => ['permissions' => 'manage_catalog'],
        'room_price'              => ['permissions' => 'manage_catalog'],
        'download_active_prices_csv' => ['permissions' => 'manage_catalog'],
        'cron_offers_update'      => ['permissions' => 'manage_catalog'],
        'fix_tab'                 => ['permissions' => 'manage_catalog'],
        'hotels'                  => ['permissions' => 'manage_catalog'],
        'view_hotel'              => ['permissions' => 'manage_catalog'],
        'test_api'                => ['permissions' => 'manage_catalog'],
        'test_formats'            => ['permissions' => 'manage_catalog'],
        'test_product'            => ['permissions' => 'manage_catalog'],
        'test_hotel_list'         => ['permissions' => 'manage_catalog'],
        'test_room_price'         => ['permissions' => 'manage_catalog'],
        'test_search'             => ['permissions' => 'manage_catalog'],
        'test_hotel_request'      => ['permissions' => 'manage_catalog'],
        'test_alternative_rs'     => ['permissions' => 'manage_catalog'],
        'test_facilities'         => ['permissions' => 'manage_catalog'],
        'export_hotel_features_csv'  => ['permissions' => 'manage_catalog'],
        'download_hotel_features_csv' => ['permissions' => 'manage_catalog'],
        'get_hotel_features_csv'  => ['permissions' => 'manage_catalog'],
        'cron_export_hotel_features' => ['permissions' => 'manage_catalog'],
        'save_excluded_resorts'   => ['permissions' => 'manage_catalog'],
    ],
];

// novoton_bookings controller modes
$schema['novoton_bookings'] = [
    'modes' => [
        'manage'              => ['permissions' => 'manage_catalog'],
        'view'                => ['permissions' => 'manage_catalog'],
        'update'              => ['permissions' => 'manage_catalog'],
        'details'             => ['permissions' => 'manage_catalog'],
        'resinfo'             => ['permissions' => 'manage_catalog'],
        'check_all_status'    => ['permissions' => 'manage_catalog'],
        'cleanup_orphans'     => ['permissions' => 'manage_catalog'],
        'update_novoton_id'   => ['permissions' => 'manage_catalog'],
        'request_alternatives' => ['permissions' => 'manage_catalog'],
        'alternatives'        => ['permissions' => 'manage_catalog'],
        'order_tab'           => ['permissions' => 'manage_catalog'],
    ],
];

return $schema;
