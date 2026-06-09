<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — AJAX Price Recalculation Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "ajax_recalculate_price".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Services\CurrencyService;

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
    $sendJson = function($response) use (&$debug_enabled, &$debug_messages, &$_nvt_caught_warnings) {
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
        exit(json_encode($response, JSON_UNESCAPED_UNICODE));
    };

    // Get JSON input
    $input = (string) file_get_contents('php://input');
    $data = json_decode($input, true);

    $debug_log('Raw input', $input);
    $debug_log('Decoded data', $data);
    
    if (empty($data) || !is_array($data)) {
        $debug_log('ERROR: Invalid request data');
        $sendJson(['success' => false, 'message' => 'Invalid request data']);
    }

    /** @var array<string, mixed> $data */
    $hotel_id = PriceInfoFormatter::toScalar($data['hotel_id'] ?? '');
    $room_id = PriceInfoFormatter::toScalar($data['room_id'] ?? '');
    $board_id = PriceInfoFormatter::toScalar($data['board_id'] ?? '');
    $check_in = PriceInfoFormatter::toScalar($data['check_in'] ?? '');
    $nights = PriceInfoFormatter::toInt($data['nights'] ?? 7);
    $adults = PriceInfoFormatter::toInt($data['adults'] ?? 2);
    $children_ages = is_array($data['children_ages'] ?? null) ? $data['children_ages'] : [];
    $package_name = PriceInfoFormatter::toScalar($data['package_name'] ?? '');
    $original_price = PriceInfoFormatter::toFloat($data['original_price'] ?? 0);

    // Input range validation
    if ($nights < 1 || $nights > 365) {
        $debug_log('ERROR: Invalid nights value', $nights);
        $sendJson(['success' => false, 'message' => 'Invalid night count']);
    }

    if ($adults < 1 || $adults > 12) {
        $debug_log('ERROR: Invalid adults value', $adults);
        $sendJson(['success' => false, 'message' => 'Invalid adult count']);
    }

    if ($original_price < 0 || $original_price > 999999) {
        $debug_log('ERROR: Invalid original_price value', $original_price);
        $sendJson(['success' => false, 'message' => 'Invalid price']);
    }

    // Validate check_in is a valid date format (YYYY-MM-DD)
    if ($check_in && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in)) {
        $debug_log('ERROR: Invalid check_in format', $check_in);
        $sendJson(['success' => false, 'message' => 'Invalid check-in date format']);
    }

    // A73s: Ensure children_ages is an array of integers within valid range
    if (!is_array($children_ages)) {
        $children_ages = [];
    } else {
        $children_ages = array_map('intval', array_values($children_ages));
        // Filter to valid child ages (0-17), max 10 children
        $children_ages = array_filter($children_ages, function($age) {
            return $age >= 0 && $age <= 17;
        });
        $children_ages = array_slice(array_values($children_ages), 0, 10);
    }

    $debug_log('Parsed children_ages', $children_ages);

    // Validate required fields
    if (empty($hotel_id) || empty($check_in)) {
        $debug_log('ERROR: Missing required fields');
        $sendJson(['success' => false, 'message' => 'Missing required fields']);
    }
    
    // Calculate check-out date
    $check_out = date('Y-m-d', (int) strtotime($check_in . ' + ' . $nights . ' days'));
    
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

        $pricing = $api->pricing();
        $response = $pricing->getRoomPrice($params);

        $debug_log('API Last Request', $api->getLastRequest());
        $rawResponse = $api->getLastResponse();
        $debug_log('API Last Response (first 2000 chars)', substr($rawResponse, 0, 2000));

        // Direct Price element — filter by board_id to avoid reading a different board's price
        if ($response && isset($response->Price)) {
            $minMatch = fn_novoton_min_price_from_xml($response, $room_id_decoded, $board_id);
            if ($minMatch !== null && $minMatch['price'] > 0) {
                $new_price    = $minMatch['price'];
                $price_found  = true;
                $matched_room  = $minMatch['room'];
                $matched_board = $minMatch['board'];
                $debug_log('Found min price (board-filtered) from specific room/board query', $new_price);
            } else {
                $new_price = (float)((string)$response->Price);
                if ($new_price > 0) {
                    $price_found  = true;
                    $matched_room  = rawurldecode((string)($response->IdRoom ?? $room_id));
                    $matched_board = (string)($response->IdBoard ?? $board_id);
                    $debug_log('Found direct Price (fallback) from specific room/board query', $new_price);
                }
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
            $response = $pricing->getRoomPrice($params);

            $debug_log('Fallback API Last Response (first 2000 chars)', substr($api->getLastResponse(), 0, 2000));

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
                    if (!empty($room_id) && strcasecmp($roomId, $room_id_decoded) !== 0) {
                        continue;
                    }

                    $boardsList = isset($room->board->IdBoard) ? [$room->board] : ($room->board ?? []);
                    foreach ($boardsList as $board) {
                        $boardIdVal = (string)($board->IdBoard ?? '');
                        if (!empty($board_id) && strcasecmp($boardIdVal, $board_id) !== 0) {
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

                $flatMatch = fn_novoton_match_price_from_xml($response, $room_id_decoded, $board_id);
                if ($flatMatch !== null) {
                    $new_price = $flatMatch['price'];
                    $price_found = true;
                    $matched_room = $flatMatch['room'];
                    $matched_board = $flatMatch['board'];
                    $debug_log('MATCH FOUND (flat)!', $flatMatch);
                }

                // Fallback: use first available price from response
                if (!$price_found) {
                    $prices = $response->xpath('//Price');
                    $idRooms = $response->xpath('//IdRoom');
                    $idBoards = $response->xpath('//IdBoard') ?: $response->xpath('//Board');
                    if (!empty($prices) && !empty($idRooms) && !empty($idBoards)) {
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
        $new_price = $pricing->applyCommission($new_price);

        // Price stays in API currency (EUR); formatter applies display coefficient for rendering

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

        // Format price for display using the addon formatter (handles rounding + currency symbol)
        $display_currency_code = CurrencyService::getDisplayCurrency();
        $currenciesRaw = Registry::get('currencies');
        $currencies = is_array($currenciesRaw) ? $currenciesRaw : [];
        $currencyEntry = is_array($currencies[$display_currency_code] ?? null) ? $currencies[$display_currency_code] : [];
        $display_coefficient = PriceInfoFormatter::toFloat($currencyEntry['coefficient'] ?? 1.0);
        $display_symbol = PriceInfoFormatter::toScalar($currencyEntry['symbol'] ?? $display_currency_code);
        $formatted_price = fn_novoton_holidays_format_price($new_price, $display_coefficient, $display_symbol);

        $debug_log('SUCCESS', [
            'new_price' => $new_price,
            'original_price' => $original_price,
            'difference' => $price_difference,
            'children_ages' => $children_ages,
            'room_changed' => $room_changed,
            'new_room' => $matched_room ?: ''
        ]);

        // Analyse price change for "No Surprises" UX (tolerance-aware)
        $price_change = null;
        if ($original_price > 0) {
            $detector = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->priceChangeDetector();
            $price_change = $detector->analyse(
                $original_price,
                $new_price,
                \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getApiCurrency(),
                'recalculate'
            );
        }

        // Return success response with room change info and price change analysis
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
            'new_board' => $matched_board ?: $board_id,
            'price_change' => $price_change
        ]);

    } catch (\Exception $e) {
        $debug_log('EXCEPTION', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        $sendJson([
            'success' => false,
            'message' => 'Price calculation error'
        ]);
    }
