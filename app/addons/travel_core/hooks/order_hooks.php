<?php
declare(strict_types=1);
/**
 * Travel Core - Order Hook Functions
 *
 * Provider-agnostic order hooks for travel bookings.
 * Each provider handles its own booking submission via its own place_order_post hook.
 * This file handles shared post-order enrichment (e.g., attaching booking display data).
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: After getting order info — format travel booking data for display.
 *
 * Enriches order products with formatted dates and guest display names.
 * Provider-specific enrichment (terms, hotel locations) remains in provider hooks.
 */
function fn_travel_core_get_order_info(&$order, $additional_data): void
{
    if (empty($order['products'])) {
        return;
    }

    $date_format = \Tygh\Registry::get('settings.Appearance.date_format') ?: '%d %b %Y';

    foreach ($order['products'] as &$product) {
        // Support both new and legacy booking flags
        if (empty($product['extra']['travel_booking']) && empty($product['extra']['novoton_booking'])) {
            continue;
        }

        $check_in  = $product['extra']['check_in']  ?? '';
        $check_out = $product['extra']['check_out'] ?? '';

        // Formatted dates
        $ci_ts = !empty($check_in)  ? strtotime($check_in)  : false;
        $co_ts = !empty($check_out) ? strtotime($check_out) : false;
        if ($ci_ts !== false) {
            $product['extra']['check_in_formatted']  = fn_date_format($ci_ts, $date_format);
        }
        if ($co_ts !== false) {
            $product['extra']['check_out_formatted'] = fn_date_format($co_ts, $date_format);
        }

        // Format guests_data for display
        $guests_data = $product['extra']['guests_data'] ?? null;
        if (!empty($guests_data)) {
            $guests_data = GuestDataNormalizer::normalize($guests_data);
            if (!empty($guests_data)) {
                $formatted = [];
                $holder_name = $product['extra']['holder_name'] ?? '';
                $is_first = true;

                foreach ($guests_data as $key => $guest) {
                    if (!is_array($guest)) {
                        continue;
                    }

                    $display_name = $guest['display_name'] ?? $guest['name'] ?? '';
                    $api_name     = $guest['api_name'] ?? '';

                    if (empty($display_name) && !empty($api_name)) {
                        $parts = explode(' ', trim($api_name), 2);
                        $display_name = count($parts) === 2
                            ? $parts[1] . ', ' . $parts[0]
                            : $api_name;
                    }

                    $guest_type = $guest['type'] ?? 'adult';
                    $is_holder = false;

                    if ($is_first && $guest_type === 'adult') {
                        $is_holder = true;
                        $is_first = false;
                    } elseif (!empty($holder_name) && stripos($display_name, $holder_name) !== false) {
                        $is_holder = true;
                    }

                    $formatted[$key] = [
                        'display_name' => $display_name,
                        'name'         => $guest['name'] ?? $display_name,
                        'type'         => $guest_type,
                        'age'          => (int)($guest['age'] ?? 0),
                        'is_holder'    => $is_holder,
                        'birthday'     => $guest['birthday'] ?? '',
                        'room'         => $guest['room'] ?? 1,
                    ];
                }

                $product['extra']['guests_data'] = $formatted;
            }
        }
    }
}
