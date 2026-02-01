<?php
/**
 * Novoton Holidays - AJAX Price Recalculator
 * Version: 2.7.1-A74m
 * 
 * This file is automatically copied to CS-Cart root during addon installation.
 * Source: app/addons/novoton_holidays/ajax_price.php
 * Target: novoton_ajax_price.php (in CS-Cart root)
 */

// CRITICAL: Suppress ALL errors BEFORE anything else
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Start output buffer (don't clean first - might not exist)
ob_start();

// Define CS-Cart constants
define('AREA', 'C');
define('ACCOUNT_TYPE', 'customer');
define('BOOTSTRAP', true);

// Disable CS-Cart's error handler by setting development mode off
$_SERVER['DEVELOPMENT'] = false;

$cscart_root = __DIR__;

if (!file_exists($cscart_root . '/init.php')) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['success' => false, 'message' => 'CS-Cart init not found']));
}

// Load CS-Cart - errors are suppressed
require_once($cscart_root . '/init.php');

// Restore error suppression after CS-Cart init
error_reporting(0);
ini_set('display_errors', '0');

// Restore default PHP error handler (removes CS-Cart's custom handler)
restore_error_handler();

// Load addon functions
$addon_func = $cscart_root . '/app/addons/novoton_holidays/func.php';
if (file_exists($addon_func)) {
    require_once($addon_func);
}

// Clear ALL output buffers safely
while (ob_get_level()) {
    ob_end_clean();
}

// Now output JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Debug log
$debug_file = $cscart_root . '/var/novoton_price_debug.log';
function debug_log($msg, $data = null) {
    global $debug_file;
    $line = date('Y-m-d H:i:s') . ' - ' . $msg;
    if ($data !== null) {
        $line .= ': ' . print_r($data, true);
    }
    file_put_contents($debug_file, $line . "\n", FILE_APPEND);
}

debug_log('=== NOVOTON_AJAX_PRICE.PHP CALLED ===');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

debug_log('Raw input', $input);

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

$hotel_id = $data['hotel_id'] ?? '';
$room_id = $data['room_id'] ?? '';
$board_id = $data['board_id'] ?? '';
$check_in = $data['check_in'] ?? '';
$nights = intval($data['nights'] ?? 7);
$adults = intval($data['adults'] ?? 2);
$children_ages = $data['children_ages'] ?? [];
$original_price = floatval($data['original_price'] ?? 0);

// Ensure children_ages is array of integers
if (!is_array($children_ages)) {
    $children_ages = [];
} else {
    $children_ages = array_map('intval', array_values($children_ages));
}

debug_log('Parsed data', [
    'hotel_id' => $hotel_id,
    'room_id' => $room_id,
    'board_id' => $board_id,
    'children_ages' => $children_ages
]);

// Validate
if (empty($hotel_id) || empty($check_in)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Calculate check-out
$check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));

// Get API
if (!function_exists('fn_novoton_get_api')) {
    debug_log('ERROR: fn_novoton_get_api not found');
    echo json_encode(['success' => false, 'message' => 'API function not available']);
    exit;
}

$api = fn_novoton_get_api();
if (!$api) {
    debug_log('ERROR: API not available');
    echo json_encode(['success' => false, 'message' => 'API not available']);
    exit;
}

try {
    $params = [
        'hotel_id' => $hotel_id,
        'room_id' => '',
        'board_id' => '',
        'check_in' => $check_in,
        'check_out' => $check_out,
        'adults' => $adults,
        'children' => $children_ages,
        'nocache' => true
    ];
    
    debug_log('API params', $params);
    
    $response = $api->getRoomPrice($params);
    
    debug_log('API response received');
    
    if (!$response) {
        debug_log('ERROR: No API response');
        echo json_encode(['success' => false, 'message' => 'No price data from API']);
        exit;
    }
    
    // Parse flat XML
    $prices = $response->xpath('//Price');
    $idRooms = $response->xpath('//IdRoom');
    $boards = $response->xpath('//Board');
    
    $numResults = min(count($prices), count($idRooms), count($boards));
    debug_log('Found results', $numResults);
    
    $new_price = 0;
    $price_found = false;
    $matched_room = '';
    $matched_board = '';
    $room_id_decoded = $room_id;
    
    for ($i = 0; $i < $numResults; $i++) {
        $resultPrice = floatval((string)$prices[$i]);
        $resultRoom = urldecode((string)$idRooms[$i]);
        $resultBoard = (string)$boards[$i];
        
        // Match room and board
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
            debug_log('MATCH', ['room' => $resultRoom, 'price' => $resultPrice]);
            break;
        }
    }
    
    // Fallback to first result
    if (!$price_found && $numResults > 0) {
        $new_price = floatval((string)$prices[0]);
        $matched_room = urldecode((string)$idRooms[0]);
        $matched_board = (string)$boards[0];
        $price_found = ($new_price > 0);
        debug_log('Fallback to first', ['room' => $matched_room, 'price' => $new_price]);
    }
    
    if (!$price_found) {
        debug_log('ERROR: No price found');
        echo json_encode(['success' => false, 'message' => 'Price not found']);
        exit;
    }
    
    // Check room change
    $room_changed = false;
    if (!empty($matched_room) && !empty($room_id_decoded)) {
        $room_changed = (strcasecmp(trim($matched_room), trim($room_id_decoded)) !== 0);
    }
    
    $price_difference = $new_price - $original_price;
    
    debug_log('SUCCESS', [
        'new_price' => $new_price,
        'room_changed' => $room_changed,
        'matched_room' => $matched_room
    ]);
    
    echo json_encode([
        'success' => true,
        'new_price' => $new_price,
        'original_price' => $original_price,
        'formatted_price' => number_format($new_price, 2, '.', ''),
        'price_difference' => $price_difference,
        'new_adults' => $adults,
        'new_children' => count($children_ages),
        'children_ages' => $children_ages,
        'room_changed' => $room_changed,
        'original_room' => $room_id_decoded,
        'new_room' => $matched_room ?: $room_id_decoded,
        'new_board' => $matched_board ?: $board_id
    ]);
    
} catch (Exception $e) {
    debug_log('EXCEPTION', $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

exit;
