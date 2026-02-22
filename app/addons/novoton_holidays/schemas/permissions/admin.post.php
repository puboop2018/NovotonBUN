<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Admin Permissions Schema
 *
 * Registers all backend controller modes with granular permission groups:
 *
 *   manage_catalog            — hotel & product management (hotels, prices, sync, facilities)
 *   novoton_manage_bookings   — view/manage bookings and alternatives
 *   novoton_manage_sync       — run syncs, diagnostics, cron tools
 *   novoton_manage_settings   — addon settings, exchange rates, API config
 */

// ── novoton_holidays controller (main dashboard, hotel catalog, sync) ──
$schema['novoton_holidays'] = [
    'modes' => [
        // Hotel catalog (read/write)
        'manage'                     => ['permissions' => 'manage_catalog'],
        'add_hotels_as_products'     => ['permissions' => 'manage_catalog'],
        'view_hotels_to_add'         => ['permissions' => 'manage_catalog'],
        'hotels'                     => ['permissions' => 'manage_catalog'],
        'view_hotel'                 => ['permissions' => 'manage_catalog'],
        'list_facilities'            => ['permissions' => 'manage_catalog'],
        'save_excluded_resorts'      => ['permissions' => 'manage_catalog'],

        // Sync operations
        'sync_facilities'            => ['permissions' => 'novoton_manage_sync'],
        'check_packages'             => ['permissions' => 'novoton_manage_sync'],
        'update_prices'              => ['permissions' => 'novoton_manage_sync'],
        'check_prices'               => ['permissions' => 'novoton_manage_sync'],
        'check_prices_hotel'         => ['permissions' => 'novoton_manage_sync'],
        'room_price'                 => ['permissions' => 'novoton_manage_sync'],
        'cron_offers_update'         => ['permissions' => 'novoton_manage_sync'],
        'fix_tab'                    => ['permissions' => 'novoton_manage_sync'],

        // Export / reports
        'download_active_prices_csv' => ['permissions' => 'manage_catalog'],
        'export_hotel_features_csv'  => ['permissions' => 'manage_catalog'],
        'download_hotel_features_csv'=> ['permissions' => 'manage_catalog'],
        'get_hotel_features_csv'     => ['permissions' => 'manage_catalog'],
        'cron_export_hotel_features' => ['permissions' => 'novoton_manage_sync'],

        // API testing / diagnostics
        'test_api'                   => ['permissions' => 'novoton_manage_sync'],
        'test_formats'               => ['permissions' => 'novoton_manage_sync'],
        'test_product'               => ['permissions' => 'novoton_manage_sync'],
        'test_hotel_list'            => ['permissions' => 'novoton_manage_sync'],
        'test_room_price'            => ['permissions' => 'novoton_manage_sync'],
        'test_search'                => ['permissions' => 'novoton_manage_sync'],
        'test_hotel_request'         => ['permissions' => 'novoton_manage_sync'],
        'test_alternative_rs'        => ['permissions' => 'novoton_manage_sync'],
        'test_facilities'            => ['permissions' => 'novoton_manage_sync'],
    ],
];

// ── novoton_bookings controller ──
$schema['novoton_bookings'] = [
    'modes' => [
        'manage'               => ['permissions' => 'novoton_manage_bookings'],
        'view'                 => ['permissions' => 'novoton_manage_bookings'],
        'update'               => ['permissions' => 'novoton_manage_bookings'],
        'details'              => ['permissions' => 'novoton_manage_bookings'],
        'resinfo'              => ['permissions' => 'novoton_manage_bookings'],
        'check_all_status'     => ['permissions' => 'novoton_manage_bookings'],
        'cleanup_orphans'      => ['permissions' => 'novoton_manage_bookings'],
        'update_novoton_id'    => ['permissions' => 'novoton_manage_bookings'],
        'request_alternatives' => ['permissions' => 'novoton_manage_bookings'],
        'alternatives'         => ['permissions' => 'novoton_manage_bookings'],
        'order_tab'            => ['permissions' => 'novoton_manage_bookings'],
    ],
];

// ── novoton_alternatives controller ──
$schema['novoton_alternatives'] = [
    'modes' => [
        'manage'              => ['permissions' => 'novoton_manage_bookings'],
        'view'                => ['permissions' => 'novoton_manage_bookings'],
        'update'              => ['permissions' => 'novoton_manage_bookings'],
        'check_alternatives'  => ['permissions' => 'novoton_manage_bookings'],
        'notify_customer'     => ['permissions' => 'novoton_manage_bookings'],
        'delete'              => ['permissions' => 'novoton_manage_bookings'],
        'check_all_pending'   => ['permissions' => 'novoton_manage_bookings'],
    ],
];

// ── novoton_exchange_rates controller ──
$schema['novoton_exchange_rates'] = [
    'modes' => [
        'manage'  => ['permissions' => 'novoton_manage_settings'],
        'update'  => ['permissions' => 'novoton_manage_settings'],
        'cron'    => ['permissions' => 'novoton_manage_settings'],
    ],
];

// ── novoton_diagnostic controller ──
$schema['novoton_diagnostic'] = [
    'modes' => [
        'health'  => ['permissions' => 'novoton_manage_sync'],
        'check'   => ['permissions' => 'novoton_manage_sync'],
        'test'    => ['permissions' => 'novoton_manage_sync'],
    ],
];

// ── novoton_admin controller (settings & config) ──
$schema['novoton_admin'] = [
    'modes' => [
        'manage'  => ['permissions' => 'novoton_manage_settings'],
        'update'  => ['permissions' => 'novoton_manage_settings'],
    ],
];

// ── novoton_hotels controller ──
$schema['novoton_hotels'] = [
    'modes' => [
        'manage'  => ['permissions' => 'manage_catalog'],
        'view'    => ['permissions' => 'manage_catalog'],
        'update'  => ['permissions' => 'manage_catalog'],
        'sync'    => ['permissions' => 'novoton_manage_sync'],
    ],
];

// ── novoton_prices controller ──
$schema['novoton_prices'] = [
    'modes' => [
        'manage'  => ['permissions' => 'manage_catalog'],
        'view'    => ['permissions' => 'manage_catalog'],
    ],
];

// ── novoton_price_compare controller ──
$schema['novoton_price_compare'] = [
    'modes' => [
        'manage'  => ['permissions' => 'manage_catalog'],
    ],
];

// ── novoton_tools controller ──
$schema['novoton_tools'] = [
    'modes' => [
        'manage'  => ['permissions' => 'novoton_manage_sync'],
    ],
];

return $schema;
