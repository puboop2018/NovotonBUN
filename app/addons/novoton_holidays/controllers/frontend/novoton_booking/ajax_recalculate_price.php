<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — AJAX Price Recalculation Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "ajax_recalculate_price".
 */
if (!defined('BOOTSTRAP')) { die('Access denied'); }

    // Scoped error handler: log warnings to CS-Cart log, prevent any output.
    // This replaces the old blanket error_reporting(0) — real errors are still
    // logged, but PHP won't echo anything that corrupts our JSON response.
    $_nvt_caught_warnings = [];
    $prev_error_handler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$_nvt_caught_warnings) {
        // Log the warning for debugging, but don't output anything
        $_nvt_caught_warnings[] = [
            'type'    => $errno,
            'message' => $errstr,
            'file'    => $errfile,
            'line'    => $errline,
        ];
        // Return true = handled, PHP won't output or escalate
        return true;
    }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE);

    // Debug logging — writes to CS-Cart log when addon setting debug=Y
    // or when ?novoton_debug=1 is in the URL
    $debug_enabled = false;
    $debug_messages = [];
    try {
        $debug_enabled = (ConfigProvider::get('debug', 'N') === 'Y');
    } catch (\Exception $e) {
        // Registry may not be available in edge cases; debug stays disabled
    }

    $debug_log = function($msg, $data = null) use (&$debug_enabled, &$debug_messages) {
        if (!$debug_enabled) return;
        $entry = date('H:i:s') . ' ' . $msg;
        if ($data !== null) {
            $entry .= ': ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $debug_messages[] = $entry;
        // Also write to CS-Cart log immediately
        fn_log_event('general', 'runtime', ['message' => '[NovotonPriceRecalc] ' . $entry]);
    };

    $debug_log('=== NEW PRICE RECALCULATION REQUEST ===');

    // Helper function to send JSON response and exit
    $sendJson = function($response) use (&$debug_enabled, &$debug_messages, &$_nvt_caught_warnings, &$prev_error_handler) {
        // Include debug log in response when debug is enabled
        if ($debug_enabled && !empty($debug_messages)) {
            $response['_debug'] = $debug_messages;
        }
        // Log any caught warnings to CS-Cart log (visible in admin, not in response)
        if (!empty($_nvt_caught_warnings)) {
            fn_log_event('general', 'runtime', [
                'message' => '[NovotonPriceRecalc] Caught PHP warnings',
                'warnings' => $_nvt_caught_warnings,
            ]);
            // Include in debug response so developer can see them
            if ($debug_enabled) {
                $response['_warnings'] = $_nvt_caught_warnings;
            }
        }
        // Restore previous error handler
        restore_error_handler();
        // Set headers and output clean JSON
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        die(json_encode($response, JSON_UNESCAPED_UNICODE));
    };

    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $debug_log('Raw input', $input);
    $debug_log('Decoded data', $data);
    
    if (empty($data)) {
        $debug_log('ERROR: Invalid request data');
        $sendJson(['success' => false, 'message' => 'Invalid request data']);
    }
    
    $hotel_id = $data['hotel_id'] ?? '';
    $room_id = $data['room_id'] ?? '';
    $board_id = $data['board_id'] ?? '';
    $check_in = $data['check_in'] ?? '';
    $nights = (int)($data['nights'] ?? 7);
    $adults = (int)($data['adults'] ?? 2);
    $children_ages = $data['children_ages'] ?? [];
    $package_name = $data['package_name'] ?? '';
    $original_price = (float)($data['original_price'] ?? 0);
    
    // A73s: Ensure children_ages is an array of integers
    if (!is_array($children_ages)) {
        $children_ages = [];
    } else {
        $children_ages = array_map('intval', array_values($children_ages));
    }
    
    $debug_log('Parsed children_ages', $children_ages);
    
    // Validate required fields
    if (empty($hotel_id) || empty($check_in)) {
        $debug_log('ERROR: Missing required fields');
        $sendJson(['success' => false, 'message' => 'Missing required fields']);
    }
    
    // Calculate check-out date
    $check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));
    
    // Get API instance
    $api = fn_novoton_holidays_get_api();
    if (!$api) {
        $debug_log('ERROR: API not available');
        $sendJson(['success' => false, 'message' => 'API not available']);
    }
    
    try {
        $new_price = 0;
        $price_found = false;
        $matched_room = '';
        $matched_board = '';
        $room_id_decoded = $room_id;  // Keep as-is from form (already has + not %2b)

        // =====================================================================
        // PRIMARY: Send actual room_id/board_id (same approach as add_to_cart)
        // This is the proven path that works for the booking flow
        // =====================================================================
        $params = [
            'hotel_id' => $hotel_id,
            'room_id' => $room_id,
            'board_id' => $board_id,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
            'children' => $children_ages,
            'nocache' => true
        ];

        $debug_log('API params (with actual room/board IDs)', $params);

        $response = $api->getRoomPrice($params);

        $debug_log('API Last Request', $api->getLastRequest());
        $rawResponse = $api->getLastResponse();
        $debug_log('API Last Response (first 2000 chars)', substr($rawResponse, 0, 2000));

        // Direct Price element (API returns single result for specific room/board)
        if ($response && isset($response->Price)) {
            $new_price = (float)((string)$response->Price);
            if ($new_price > 0) {
                $price_found = true;
                $matched_room = rawurldecode((string)($response->IdRoom ?? $room_id));
                $matched_board = (string)($response->IdBoard ?? $board_id);
                $debug_log('Found direct Price from specific room/board query', $new_price);
            }
        }

        // =====================================================================
        // FALLBACK: If specific room/board returned no price, try getting all
        // combinations and match (handles APIs that reject IDs with spaces)
        // =====================================================================
        if (!$price_found) {
            $debug_log('Specific room/board query returned no price, trying all combinations');

            $params['room_id'] = '';
            $params['board_id'] = '';
            $response = $api->getRoomPrice($params);

            $debug_log('Fallback API Last Response (first 2000 chars)', substr($api->getLastResponse() ?? '', 0, 2000));

            if (!$response) {
                $debug_log('ERROR: No response from API');
                $sendJson([
                    'success' => false,
                    'message' => 'Price not available'
                ]);
            }

            // Method 1: Try standard structure first (with hotel wrapper)
            if (isset($response->hotel)) {
                $debug_log('Standard structure detected (hotel wrapper)');
                $hotel = $response->hotel;
                $rooms = isset($hotel->rooms->IdRoom) ? [$hotel->rooms] : ($hotel->rooms ?? []);

                foreach ($rooms as $room) {
                    $roomId = rawurldecode((string)($room->IdRoom ?? ''));
                    if (!empty($room_id) && $roomId !== $room_id_decoded && stripos($roomId, $room_id_decoded) === false) {
                        continue;
                    }

                    $boardsList = isset($room->board->IdBoard) ? [$room->board] : ($room->board ?? []);
                    foreach ($boardsList as $board) {
                        $boardIdVal = (string)($board->IdBoard ?? '');
                        if (!empty($board_id) && $boardIdVal !== $board_id && stripos($boardIdVal, $board_id) === false) {
                            continue;
                        }

                        $price = (float)((string)($board->Price ?? $board->TotalPrice ?? 0));
                        if ($price > 0) {
                            $new_price = $price;
                            $price_found = true;
                            $matched_room = $roomId;
                            $matched_board = $boardIdVal;
                            $debug_log('Found price (standard structure)', $price);
                            break 2;
                        }
                    }
                }
            }

            // Method 2: Parse flat structure (direct fields under room_price)
            if (!$price_found) {
                $debug_log('Trying flat structure parsing');

                $prices = $response->xpath('//Price');
                $idRooms = $response->xpath('//IdRoom');
                $idBoards = $response->xpath('//IdBoard');

                $debug_log('Found elements', [
                    'prices' => count($prices),
                    'rooms' => count($idRooms),
                    'idBoards' => count($idBoards)
                ]);

                $numResults = min(count($prices), count($idRooms), count($idBoards));

                for ($i = 0; $i < $numResults; $i++) {
                    $resultPrice = (float)((string)$prices[$i]);
                    $resultRoom = rawurldecode((string)$idRooms[$i]);
                    $resultBoard = (string)$idBoards[$i];

                    $debug_log("Result $i", [
                        'price' => $resultPrice,
                        'room' => $resultRoom,
                        'board' => $resultBoard
                    ]);

                    $roomMatches = empty($room_id_decoded) ||
                                   $resultRoom === $room_id_decoded ||
                                   stripos($resultRoom, $room_id_decoded) !== false ||
                                   stripos($room_id_decoded, $resultRoom) !== false;

                    $boardMatches = empty($board_id) ||
                                    $resultBoard === $board_id ||
                                    stripos($resultBoard, $board_id) !== false ||
                                    stripos($board_id, $resultBoard) !== false;

                    if ($roomMatches && $boardMatches && $resultPrice > 0) {
                        $new_price = $resultPrice;
                        $price_found = true;
                        $matched_room = $resultRoom;
                        $matched_board = $resultBoard;
                        $debug_log('MATCH FOUND!', [
                            'index' => $i,
                            'room' => $resultRoom,
                            'board' => $resultBoard,
                            'price' => $resultPrice
                        ]);
                        break;
                    }
                }

                // Fallback: use first available price from response
                if (!$price_found && $numResults > 0) {
                    $new_price = (float)((string)$prices[0]);
                    $matched_room = rawurldecode((string)$idRooms[0]);
                    $matched_board = (string)$idBoards[0];
                    if ($new_price > 0) {
                        $price_found = true;
                        $debug_log('Using first available price as fallback', [
                            'price' => $new_price,
                            'room' => $matched_room,
                            'board' => $matched_board
                        ]);
                    }
                }
            }

            // Method 3: Direct Price element at root (from all-combinations response)
            if (!$price_found && isset($response->Price)) {
                $new_price = (float)((string)$response->Price);
                if ($new_price > 0) {
                    $price_found = true;
                    $matched_room = rawurldecode((string)($response->IdRoom ?? ''));
                    $matched_board = (string)($response->IdBoard ?? '');
                    $debug_log('Found direct Price element from fallback', $new_price);
                }
            }
        }

        if (!$price_found) {
            $debug_log('ERROR: Price not found for combination');
            $sendJson([
                'success' => false,
                'message' => 'Price not found for this room/board combination'
            ]);
        }

        // Apply commission so displayed price matches customer-facing price
        $new_price = $api->applyCommission($new_price);

        // Convert from API currency to CS-Cart display currency
        $new_price = RoomPriceService::convertFromApiCurrency($new_price);

        // Check if room changed
        $room_changed = false;
        $original_room = $room_id_decoded;
        if (!empty($matched_room) && !empty($original_room)) {
            $room_changed = (strcasecmp(trim($matched_room), trim($original_room)) !== 0);
        }

        $debug_log('Room change check', [
            'original_room' => $original_room,
            'matched_room' => $matched_room ?: 'N/A',
            'room_changed' => $room_changed ? 'YES' : 'NO'
        ]);

        // Calculate price difference (both prices now in display currency)
        $price_difference = $new_price - $original_price;

        // Format price for display using the active display currency
        $display_currency_code = RoomPriceService::getDisplayCurrency();
        $currency = Registry::get('currencies.' . $display_currency_code);
        $formatted_price = fn_format_price($new_price, $currency);

        $debug_log('SUCCESS', [
            'new_price' => $new_price,
            'original_price' => $original_price,
            'difference' => $price_difference,
            'children_ages' => $children_ages,
            'room_changed' => $room_changed,
            'new_room' => $matched_room ?: ''
        ]);

        // Return success response with room change info
        $sendJson([
            'success' => true,
            'new_price' => $new_price,
            'original_price' => $original_price,
            'formatted_price' => $formatted_price,
            'price_difference' => $price_difference,
            'new_adults' => $adults,
            'new_children' => count($children_ages),
            'children_ages' => $children_ages,
            'room_changed' => $room_changed,
            'original_room' => $original_room,
            'new_room' => $matched_room ?: $original_room,
            'new_board' => $matched_board ?: $board_id
        ]);

    } catch (\Exception $e) {
        $debug_log('EXCEPTION', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        $sendJson([
            'success' => false,
            'message' => 'Price calculation error'
        ]);
    }
