<?php
/**
 * Novoton Bookings Admin Controller
 * Handles booking management, status checking, and alternatives
 * 
 * Path: app/addons/novoton_holidays/controllers/backend/novoton_bookings.php
 */

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Default mode - redirect to manage
if (empty($mode)) {
    $mode = 'manage';
}

// Check permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check reservation status
    if ($mode === 'resinfo') {
        $booking_id = isset($_REQUEST['booking_id']) ? intval($_REQUEST['booking_id']) : 0;
        
        $results = fn_novoton_check_reservation_status($booking_id);
        
        fn_set_notification('N', __('notice'), __('novoton_holidays.status_checked'));
        
        if (!empty($_REQUEST['return_url'])) {
            return array(CONTROLLER_STATUS_REDIRECT, $_REQUEST['return_url']);
        }
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Request alternatives
    if ($mode === 'request_alternatives') {
        $booking_id = isset($_REQUEST['booking_id']) ? intval($_REQUEST['booking_id']) : 0;
        
        if ($booking_id > 0) {
            $alternatives = fn_novoton_request_alternatives($booking_id);
            
            if ($alternatives) {
                fn_set_notification('N', __('notice'), __('novoton_holidays.alternatives_found', ['[count]' => count($alternatives)]));
            } else {
                fn_set_notification('W', __('warning'), __('novoton_holidays.no_alternatives'));
            }
        }
        
        if (!empty($_REQUEST['return_url'])) {
            return array(CONTROLLER_STATUS_REDIRECT, $_REQUEST['return_url']);
        }
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Check all ASK bookings (bulk)
    if ($mode === 'check_all_status') {
        $results = fn_novoton_cron_resinfo();
        
        fn_set_notification('N', __('notice'), 
            __('novoton_holidays.bulk_status_checked', [
                '[checked]' => $results['checked'] ?? 0,
                '[changed]' => $results['changed'] ?? 0
            ])
        );
        
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Cleanup orphan bookings (no order_id and older than 24 hours)
    if ($mode === 'cleanup_orphans') {
        // First, try to merge orphan booking data into order-linked bookings
        // This handles cases where an orphan has data (like price) that the order-linked booking is missing
        $orphans = db_get_array(
            "SELECT * FROM ?:novoton_bookings 
             WHERE order_id = 0 
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        $merged = 0;
        foreach ($orphans as $orphan) {
            // Find matching booking with order_id based on same details
            $matching = db_get_row(
                "SELECT booking_id, total_price, hotel_name, rooms_data 
                 FROM ?:novoton_bookings 
                 WHERE order_id > 0 
                 AND hotel_id = ?s 
                 AND check_in = ?s 
                 AND check_out = ?s
                 AND ABS(TIMESTAMPDIFF(HOUR, created_at, ?s)) < 2",
                $orphan['hotel_id'],
                $orphan['check_in'],
                $orphan['check_out'],
                $orphan['created_at']
            );
            
            if ($matching) {
                // Update the order-linked booking with data from orphan if missing
                $updates = [];
                if (empty($matching['total_price']) && !empty($orphan['total_price'])) {
                    $updates['total_price'] = $orphan['total_price'];
                }
                if ((empty($matching['hotel_name']) || strpos($matching['hotel_name'], 'Hotel #') === 0) && !empty($orphan['hotel_name'])) {
                    $updates['hotel_name'] = $orphan['hotel_name'];
                }
                if (empty($matching['rooms_data']) && !empty($orphan['rooms_data'])) {
                    $updates['rooms_data'] = $orphan['rooms_data'];
                }
                
                if (!empty($updates)) {
                    db_query("UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i", $updates, $matching['booking_id']);
                    $merged++;
                }
            }
        }
        
        // Now delete the orphans
        $deleted = db_query(
            "DELETE FROM ?:novoton_bookings 
             WHERE order_id = 0 
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        $message = "Cleaned up {$deleted} orphan booking(s) older than 24 hours.";
        if ($merged > 0) {
            $message .= " Merged data from {$merged} orphan(s) into existing bookings.";
        }
        
        fn_set_notification('N', __('notice'), $message);
        
        return array(CONTROLLER_STATUS_OK, 'novoton_bookings.manage');
    }
    
    // Update Novoton ID manually (Issue 7.8)
    if ($mode === 'update_novoton_id') {
        $booking_id = isset($_REQUEST['booking_id']) ? intval($_REQUEST['booking_id']) : 0;
        $novoton_invoice_id = isset($_REQUEST['novoton_invoice_id']) ? trim($_REQUEST['novoton_invoice_id']) : '';
        
        if ($booking_id > 0) {
            db_query(
                "UPDATE ?:novoton_bookings SET novoton_invoice_id = ?s WHERE booking_id = ?i",
                $novoton_invoice_id,
                $booking_id
            );
            
            fn_set_notification('N', __('notice'), 'Novoton ID updated');
            
            // If ID provided, check status
            if (!empty($novoton_invoice_id)) {
                fn_novoton_check_reservation_status($booking_id);
            }
        }
        
        return array(CONTROLLER_STATUS_REDIRECT, 'novoton_bookings.view?booking_id=' . $booking_id);
    }
}

// GET modes
if ($mode === 'manage') {
    // List all bookings
    $params = $_REQUEST;
    $params['items_per_page'] = Registry::get('settings.Appearance.admin_elements_per_page');
    
    // Filter parameters
    $condition = '';
    $join = '';
    
    // By default, hide orphan bookings (order_id = 0 and older than 24 hours)
    // unless explicitly showing all
    if (empty($params['show_orphans'])) {
        $condition .= " AND (nb.order_id > 0 OR nb.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR))";
    }
    
    if (!empty($params['order_id'])) {
        $condition .= db_quote(" AND nb.order_id = ?i", $params['order_id']);
    }
    
    if (!empty($params['novoton_status'])) {
        $condition .= db_quote(" AND nb.novoton_status = ?s", $params['novoton_status']);
    }
    
    if (!empty($params['status'])) {
        $condition .= db_quote(" AND nb.status = ?s", $params['status']);
    }
    
    if (!empty($params['hotel_id'])) {
        $condition .= db_quote(" AND nb.hotel_id = ?s", $params['hotel_id']);
    }
    
    // Date filters - convert from d/m/Y to Y-m-d if needed
    if (!empty($params['check_in_from'])) {
        $date_from = $params['check_in_from'];
        // Check if date is in d/m/Y format and convert
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $date_from)) {
            $parsed = DateTime::createFromFormat('d/m/Y', $date_from);
            if ($parsed) {
                $date_from = $parsed->format('Y-m-d');
            }
        }
        $condition .= db_quote(" AND nb.check_in >= ?s", $date_from);
    }
    if (!empty($params['check_in_to'])) {
        $date_to = $params['check_in_to'];
        // Check if date is in d/m/Y format and convert
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $date_to)) {
            $parsed = DateTime::createFromFormat('d/m/Y', $date_to);
            if ($parsed) {
                $date_to = $parsed->format('Y-m-d');
            }
        }
        $condition .= db_quote(" AND nb.check_in <= ?s", $date_to);
    }
    
    // Sorting (Issue 5)
    $sort_by = !empty($params['sort_by']) ? $params['sort_by'] : 'order_id';
    $sort_order = (!empty($params['sort_order']) && strtolower($params['sort_order']) === 'asc') ? 'ASC' : 'DESC';
    
    // Map sort field to column
    $sort_fields = [
        'order_id' => 'nb.order_id',
        'check_in' => 'nb.check_in',
        'created_at' => 'nb.created_at',
    ];
    $sort_column = $sort_fields[$sort_by] ?? 'nb.order_id';
    
    // Toggle for template
    $sort_order_toggle = ($sort_order === 'ASC') ? 'desc' : 'asc';
    Tygh::$app['view']->assign('sort_order_toggle', $sort_order_toggle);
    
    $total = db_get_field(
        "SELECT COUNT(*) FROM ?:novoton_bookings AS nb WHERE 1 {$condition}"
    );
    
    $limit = '';
    if (!empty($params['items_per_page'])) {
        $params['total_items'] = $total;
        $limit = db_paginate($params['page'], $params['items_per_page'], $total);
    }
    
    $bookings = db_get_array(
        "SELECT nb.booking_id, nb.order_id, nb.product_id, nb.hotel_id, nb.hotel_name,
                nb.room_id, nb.room_type, nb.board_id, nb.check_in, nb.check_out, nb.nights,
                nb.adults, nb.children, nb.children_ages, nb.num_rooms, nb.rooms_data,
                nb.base_price, nb.api_price, nb.total_price, nb.currency,
                nb.status, nb.novoton_status,
                nb.novoton_confirm_id, nb.novoton_invoice_id, nb.created_at,
                nb.guests_data, nb.guest_name, nb.holder_name,
                o.status AS order_status, o.total AS order_total,
                COALESCE(nh.hotel_name, nb.hotel_name) AS hotel_name,
                nh.city AS hotel_city, nh.region AS hotel_region, nh.country AS hotel_country,
                pd.product AS product_name
         FROM ?:novoton_bookings AS nb
         LEFT JOIN ?:orders AS o ON nb.order_id = o.order_id
         LEFT JOIN ?:novoton_hotels AS nh ON nb.hotel_id = nh.hotel_id
         LEFT JOIN ?:product_descriptions AS pd ON nb.product_id = pd.product_id AND pd.lang_code = ?s
         WHERE 1 {$condition}
         ORDER BY {$sort_column} {$sort_order}
         {$limit}",
        DESCR_SL
    );
    
    // Parse guests_data for each booking to show guests per room
    foreach ($bookings as &$booking) {
        // Fix hotel name if it's just "Hotel #ID"
        if (empty($booking['hotel_name']) || strpos($booking['hotel_name'], 'Hotel #') === 0) {
            if (!empty($booking['product_name'])) {
                $booking['hotel_name'] = $booking['product_name'];
            }
        }
        
        // Fallback: If no total_price but has order_id, try to get from order
        if (!empty($booking['order_id'])) {
            // Get the order_details.extra which has complete booking data
            // First try by product_id
            $order_detail = db_get_row(
                "SELECT extra, price FROM ?:order_details 
                 WHERE order_id = ?i AND product_id = ?i 
                 LIMIT 1",
                $booking['order_id'],
                $booking['product_id']
            );
            
            // If not found, try to find any novoton booking in this order with matching hotel_id
            if (!$order_detail && !empty($booking['hotel_id'])) {
                $order_details_all = db_get_array(
                    "SELECT extra, price FROM ?:order_details WHERE order_id = ?i",
                    $booking['order_id']
                );
                
                foreach ($order_details_all as $od) {
                    $extra_check = @unserialize($od['extra']);
                    if (!$extra_check) {
                        $extra_check = json_decode($od['extra'], true);
                    }
                    if ($extra_check && is_array($extra_check) && !empty($extra_check['novoton_booking'])) {
                        // Check if hotel_id matches
                        $od_hotel_id = $extra_check['hotel_id'] ?? '';
                        if ($od_hotel_id == $booking['hotel_id']) {
                            $order_detail = $od;
                            break;
                        }
                    }
                }
            }
            
            if ($order_detail) {
                $extra = @unserialize($order_detail['extra']);
                if (!$extra) {
                    $extra = json_decode($order_detail['extra'], true);
                }
                
                if ($extra && is_array($extra)) {
                    // Get price from order_details row or from cart extra
                    if (empty($booking['total_price'])) {
                        if ($order_detail['price'] > 0) {
                            $booking['total_price'] = $order_detail['price'];
                        } elseif (!empty($extra['total_price'])) {
                            $booking['total_price'] = floatval($extra['total_price']);
                        }
                    }

                    // Get base_price / api_price from cart extra
                    if (empty($booking['base_price']) && !empty($extra['base_price'])) {
                        $booking['base_price'] = floatval($extra['base_price']);
                    }

                    // Get hotel_name from extra
                    if ((empty($booking['hotel_name']) || strpos($booking['hotel_name'], 'Hotel #') === 0) && !empty($extra['hotel_name'])) {
                        $booking['hotel_name'] = $extra['hotel_name'];
                    }

                    // Get room_type_display from extra
                    if (!empty($extra['room_type_display'])) {
                        $booking['room_type_display'] = $extra['room_type_display'];
                    }

                    // Get room_name from extra (for room_id display)
                    if (!empty($extra['room_name'])) {
                        $booking['room_name_extra'] = $extra['room_name'];
                    }

                    // Get rooms_data from extra (for multi-room)
                    if (!empty($extra['rooms_data']) && is_array($extra['rooms_data'])) {
                        $room_types = [];
                        $board_names = [];
                        foreach ($extra['rooms_data'] as $room) {
                            $room_display = $room['room_type_display'] ?? $room['room_name'] ?? $room['room_id'] ?? 'Room';
                            if ($room_display === 'Room' && !empty($room['room_id'])) {
                                $room_display = fn_novoton_format_room_type($room['room_id']);
                            }
                            $room_display = str_replace(['%2b', '%2B'], '+', $room_display);
                            $room_types[] = $room_display;
                            if (!empty($room['board_name'])) {
                                $board_names[] = $room['board_name'];
                            }
                        }
                        if (!empty($room_types)) {
                            $booking['room_types_list'] = implode(', ', $room_types);
                        }
                        if (!empty($board_names)) {
                            $booking['board_display'] = $board_names[0];
                        }
                    } elseif (!empty($extra['room_type_display'])) {
                        $booking['room_types_list'] = str_replace(['%2b', '%2B'], '+', $extra['room_type_display']);
                    }

                    // Get board_name from extra
                    if (empty($booking['board_display']) && !empty($extra['board_name'])) {
                        $booking['board_display'] = $extra['board_name'];
                    }
                }
            }
        }

        // Fallback for base_price: use api_price if base_price is empty
        if (empty($booking['base_price']) && !empty($booking['api_price'])) {
            $booking['base_price'] = $booking['api_price'];
        }

        // Fallback for total_price: use base_price if total_price is empty
        if (empty($booking['total_price']) && !empty($booking['base_price'])) {
            $booking['total_price'] = $booking['base_price'];
        }

        if (!empty($booking['guests_data'])) {
            $guests = json_decode($booking['guests_data'], true);
            if ($guests) {
                $booking['guests_parsed'] = $guests;
                // Group guests by room
                $by_room = [];
                foreach ($guests as $key => $guest) {
                    $room_num = $guest['room'] ?? 1;
                    if (!isset($by_room[$room_num])) {
                        $by_room[$room_num] = [];
                    }
                    $by_room[$room_num][] = $guest['name'] ?? 'Guest';
                }
                $booking['guests_by_room'] = $by_room;
            }
        }
        // Parse rooms_data to show room types
        if (!empty($booking['rooms_data'])) {
            $rooms = json_decode($booking['rooms_data'], true);
            if ($rooms) {
                $booking['rooms_parsed'] = $rooms;
                $room_types = [];
                $board_names = [];
                foreach ($rooms as $room) {
                    // Use room_type_display if available, then room_name, then room_id
                    $room_display = $room['room_type_display'] ?? $room['room_name'] ?? $room['room_id'] ?? 'Room';
                    // If generic "Room", try formatting the room_id instead
                    if ($room_display === 'Room' && !empty($room['room_id'])) {
                        $room_display = fn_novoton_format_room_type($room['room_id']);
                    }
                    // Decode URL encoding
                    $room_display = str_replace(['%2b', '%2B'], '+', $room_display);
                    $room_types[] = $room_display;
                    
                    // Collect board names
                    if (!empty($room['board_name'])) {
                        $board_names[] = $room['board_name'];
                    } elseif (!empty($room['board_id'])) {
                        $board_names[] = $room['board_id'];
                    }
                }
                $booking['room_types_list'] = implode(', ', $room_types);
                
                // Format board name consistently
                if (!empty($board_names)) {
                    $booking['board_display'] = fn_novoton_format_board_name($board_names[0]);
                }
            }
        }

        // Final fallback for room_types_list: if still "Room" or empty, use room_id column
        if ((empty($booking['room_types_list']) || $booking['room_types_list'] === 'Room') && !empty($booking['room_id'])) {
            $booking['room_types_list'] = fn_novoton_format_room_type($booking['room_id']);
        }

        // Format board_id if no board_display yet
        if (empty($booking['board_display']) && !empty($booking['board_id'])) {
            $booking['board_display'] = fn_novoton_format_board_name($booking['board_id']);
        }
    }
    unset($booking);
    
    Tygh::$app['view']->assign('bookings', $bookings);
    Tygh::$app['view']->assign('search', $params);
    
} elseif ($mode === 'view') {
    // View single booking details
    $booking_id = isset($_REQUEST['booking_id']) ? intval($_REQUEST['booking_id']) : 0;
    
    if ($booking_id > 0) {
        $booking = db_get_row("SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
        
        if ($booking) {
            // Parse JSON fields
            if (!empty($booking['guests_data'])) {
                $booking['guests'] = json_decode($booking['guests_data'], true);
            }
            if (!empty($booking['alternatives_data'])) {
                $booking['alternatives'] = json_decode($booking['alternatives_data'], true);
            }
            if (!empty($booking['api_request'])) {
                $booking['api_request_data'] = json_decode($booking['api_request'], true);
            }
            if (!empty($booking['api_response'])) {
                $booking['api_response_data'] = json_decode($booking['api_response'], true);
            }
            
            // Get order info
            $order = fn_get_order_info($booking['order_id']);
            
            Tygh::$app['view']->assign('booking', $booking);
            Tygh::$app['view']->assign('order', $order);
        }
    }
    
} elseif ($mode === 'alternatives') {
    // View alternatives for a booking
    $booking_id = isset($_REQUEST['booking_id']) ? intval($_REQUEST['booking_id']) : 0;
    
    if ($booking_id > 0) {
        $booking = db_get_row("SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i", $booking_id);
        
        if ($booking) {
            $alternatives = fn_novoton_get_alternatives($booking_id);
            
            // Enrich alternatives with hotel names
            if ($alternatives) {
                foreach ($alternatives as &$alt) {
                    $hotel = db_get_row(
                        "SELECT hotel_name, city, country FROM ?:novoton_hotels WHERE hotel_id = ?s",
                        $alt['hotel_id']
                    );
                    if ($hotel) {
                        $alt['hotel_name'] = $hotel['hotel_name'];
                        $alt['hotel_city'] = $hotel['city'];
                        $alt['hotel_country'] = $hotel['country'];
                    }
                }
            }
            
            Tygh::$app['view']->assign('booking', $booking);
            Tygh::$app['view']->assign('alternatives', $alternatives);
        }
    }
    
} elseif ($mode === 'order_tab') {
    // AJAX content for order page tab
    $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
    
    if ($order_id > 0) {
        $bookings = fn_novoton_get_order_bookings($order_id);
        
        foreach ($bookings as &$booking) {
            if (!empty($booking['guests_data'])) {
                $booking['guests'] = json_decode($booking['guests_data'], true);
            }
            if (!empty($booking['alternatives_data'])) {
                $booking['alternatives'] = json_decode($booking['alternatives_data'], true);
            }
        }
        
        Tygh::$app['view']->assign('bookings', $bookings);
        Tygh::$app['view']->assign('order_id', $order_id);
    }
}
