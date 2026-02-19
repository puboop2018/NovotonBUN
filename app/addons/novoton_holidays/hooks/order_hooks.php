<?php
/**
 * Novoton Holidays - Order Hook Functions
 *
 * Responsible for:
 *   - place_order: Submit bookings to Novoton API, persist to DB
 *   - get_orders_post: Attach booking data to order listings
 *   - get_order_info: Enrich order products with terms, locations, formatted data
 *
 * Follows Single Responsibility Principle: each helper function handles
 * exactly one concern of the booking submission pipeline.
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\GuestDataNormalizer;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// ============================================================================
// HOOK: place_order
// ============================================================================

/**
 * Hook: place_order - Send booking to Novoton API after order is placed.
 *
 * Orchestrator only — delegates to focused helpers:
 *   1. _nvt_hydrate_booking_from_db()     — merge DB data into cart data
 *   2. _nvt_resolve_rooms_and_guests()     — parse rooms_data + guests_data
 *   3. _nvt_group_rooms_by_package()       — group rooms for API batching
 *   4. _nvt_build_api_booking_request()    — construct per-group API payload
 *   5. _nvt_persist_booking_record()       — upsert booking row (transaction)
 *   6. _nvt_submit_and_record_booking()    — call API + update status
 *
 * For multi-room bookings:
 *   - Sends ALL rooms in SINGLE API request IF same hotel, package, and dates
 *   - Sends SEPARATE API calls if rooms have different packages or dates
 */
function fn_novoton_holidays_place_order(&$order_id, &$action, &$order_status, &$cart, &$auth): void
{
    if (empty($order_id) || empty($cart['products'])) {
        return;
    }

    $commission     = ConfigProvider::getCommission();
    $disable_api    = ConfigProvider::isApiDisabled();
    $debug_logging  = ConfigProvider::isDebugLogging();

    // Ensure API class is loaded
    $src_dir = Registry::get('config.dir.addons') . 'novoton_holidays/src/';
    if (!class_exists('Tygh\Addons\NovotonHolidays\NovotonApi') && file_exists($src_dir . 'NovotonApi.php')) {
        require_once $src_dir . 'NovotonApi.php';
    }

    foreach ($cart['products'] as $cart_id => $product) {
        if (empty($product['extra']['novoton_booking'])) {
            continue;
        }

        $booking_data       = $product['extra'];
        $original_booking_id = intval($booking_data['novoton_booking_id'] ?? 0);

        // 1. Hydrate booking data from DB (single source of truth)
        $booking_data = _nvt_hydrate_booking_from_db($booking_data, $original_booking_id, $debug_logging);

        // Resolve final price
        $final_price = _nvt_resolve_final_price($booking_data, $product);
        $booking_data['final_price'] = $final_price;

        if ($debug_logging) {
            fn_log_event('general', 'runtime', [
                'message'  => 'Novoton - Final price determined',
                'booking_id' => $original_booking_id,
                'final_price' => $final_price,
                'hotel_id' => $booking_data['hotel_id'] ?? 'NOT SET',
            ]);
        }

        // 2. Resolve rooms and guests
        list($rooms_data, $guests_data) = _nvt_resolve_rooms_and_guests(
            $booking_data, $order_id, $debug_logging
        );

        // 3. Group rooms by (package + dates)
        $room_groups = _nvt_group_rooms_by_package($rooms_data, $booking_data);

        if ($debug_logging) {
            fn_log_event('general', 'runtime', [
                'message'      => 'Novoton - Room grouping result',
                'order_id'     => $order_id,
                'total_rooms'  => count($rooms_data),
                'groups_count' => count($room_groups),
                'can_combine'  => count($room_groups) === 1 ? 'YES - single API call' : 'NO - multiple API calls needed',
            ]);
        }

        // 4-6. Process each group inside a DB transaction
        $api = new \Tygh\Addons\NovotonHolidays\NovotonApi();

        db_query("START TRANSACTION");

        try {
            $group_num = 0;

            foreach ($room_groups as $group) {
                $group_num++;

                // 4. Build per-group guest list + API payload
                list($all_guests, $api_rooms, $total_api_price, $total_group_price) =
                    _nvt_build_group_guests_and_rooms($group, $guests_data, $booking_data, $commission);

                $api_data = _nvt_build_api_booking_request(
                    $group, $all_guests, $api_rooms, $booking_data, $order_id, $group_num, count($room_groups)
                );

                // 5. Persist booking record (upsert)
                $booking_record = _nvt_build_booking_record(
                    $group, $all_guests, $booking_data, $product,
                    $order_id, $group_num, count($room_groups),
                    $total_api_price, $total_group_price,
                    $api_data, $disable_api
                );

                $booking_id = _nvt_persist_booking_record(
                    $booking_record, $original_booking_id, $group_num, $order_id
                );

                if ($debug_logging) {
                    fn_log_event('general', 'runtime', [
                        'message'       => 'Novoton Booking - API Request Prepared',
                        'order_id'      => $order_id,
                        'booking_id'    => $booking_id,
                        'group'         => $group_num . ' of ' . count($room_groups),
                        'rooms_in_group' => count($group['rooms']),
                        'api_disabled'  => $disable_api ? 'YES' : 'NO',
                    ]);
                }

                // 6. Submit to API and record response
                _nvt_submit_and_record_booking(
                    $api, $api_data, $booking_id, $order_id, $group_num,
                    $total_api_price, $disable_api, $debug_logging
                );
            }

            db_query("COMMIT");

        } catch (ApiException $e) {
            db_query("ROLLBACK");
            fn_log_event('general', 'runtime', [
                'message'      => 'Novoton Booking transaction rolled back (API error)',
                'order_id'     => $order_id,
                'api_function' => $e->getApiFunction(),
                'http_code'    => $e->getHttpCode(),
                'error'        => $e->getMessage(),
            ]);
        } catch (NovotonException $e) {
            db_query("ROLLBACK");
            fn_log_event('general', 'runtime', [
                'message'  => 'Novoton Booking transaction rolled back',
                'order_id' => $order_id,
                'context'  => $e->getContext(),
                'error'    => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            db_query("ROLLBACK");
            fn_log_event('general', 'runtime', [
                'message'  => 'Novoton Booking transaction rolled back (unexpected)',
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}

// ============================================================================
// PLACE_ORDER HELPERS (private-by-convention, prefixed _nvt_)
// ============================================================================

/**
 * Hydrate booking data from the canonical DB record.
 *
 * The database is the Single Source of Truth. Cart session data may be
 * stale or incomplete (e.g. after browser refresh), so we always merge
 * the DB row back into $booking_data before processing.
 *
 * @param array $booking_data Cart extra data
 * @param int   $booking_id   Original booking ID from cart
 * @param bool  $debug        Whether to log
 * @return array Hydrated booking data
 */
function _nvt_hydrate_booking_from_db(array $booking_data, int $booking_id, bool $debug): array
{
    if ($booking_id <= 0) {
        return $booking_data;
    }

    // Use hydrated repository to avoid repeated JSON decoding
    $repo = new BookingRepository();
    $db_booking = $repo->findByIdHydrated($booking_id);

    if (empty($db_booking)) {
        return $booking_data;
    }

    // DB takes priority for critical financial and identity fields
    $priority_fields = [
        'total_price', 'base_price', 'hotel_id', 'hotel_name', 'package_name',
        'room_id', 'room_type', 'board_id', 'check_in', 'check_out', 'nights',
        'adults', 'children', 'children_ages', 'num_rooms',
        'holder_name', 'guest_name', 'special_requests',
        'terms_of_payment_raw', 'terms_of_cancellation_raw',
        'terms_of_payment_formatted', 'terms_of_cancellation_formatted',
    ];

    foreach ($priority_fields as $field) {
        if (!empty($db_booking[$field])) {
            $booking_data[$field] = $db_booking[$field];
        }
    }

    // Numeric casts
    $booking_data['total_price'] = floatval($booking_data['total_price']);
    $booking_data['base_price']  = floatval($booking_data['base_price'] ?? 0);

    // Structured JSON fields — prefer already-parsed arrays from hydrated cache
    if (!empty($db_booking['rooms_data'])) {
        $booking_data['rooms_data'] = $db_booking['rooms_data'];
    }
    if (!empty($db_booking['guests_data'])) {
        $booking_data['guests_data'] = $db_booking['guests_data'];
    }

    if ($debug) {
        fn_log_event('general', 'runtime', [
            'message'     => 'Novoton - Fetched booking data from database',
            'booking_id'  => $booking_id,
            'total_price' => $db_booking['total_price'],
            'hotel_name'  => $db_booking['hotel_name'],
        ]);
    }

    return $booking_data;
}

/**
 * Determine the authoritative price for a booking.
 *
 * Priority: DB total_price > cart price > cart base_price.
 *
 * @param array $booking_data Hydrated booking data
 * @param array $product      Cart product
 * @return float Final price (with commission)
 */
function _nvt_resolve_final_price(array $booking_data, array $product): float
{
    $price = floatval($booking_data['total_price'] ?? 0);
    if ($price > 0) {
        return $price;
    }

    $price = floatval($product['price'] ?? 0);
    if ($price > 0) {
        return $price;
    }

    return floatval($product['base_price'] ?? 0);
}

/**
 * Parse rooms_data and guests_data, with DB fallbacks.
 *
 * @param array $booking_data Hydrated booking data
 * @param int   $order_id     CS-Cart order ID
 * @param bool  $debug        Whether to log
 * @return array [rooms_data[], guests_data[]]
 */
function _nvt_resolve_rooms_and_guests(array $booking_data, int $order_id, bool $debug): array
{
    // --- rooms_data ---
    $rooms_data = [];
    if (!empty($booking_data['rooms_data'])) {
        $rooms_data = is_string($booking_data['rooms_data'])
            ? json_decode($booking_data['rooms_data'], true)
            : $booking_data['rooms_data'];
    }

    // Synthesise a single-room entry when rooms_data is absent
    if (empty($rooms_data)) {
        $children_ages = [];
        if (!empty($booking_data['children_ages'])) {
            $children_ages = is_string($booking_data['children_ages'])
                ? array_map('intval', array_filter(explode(',', $booking_data['children_ages']), function ($v) { return $v !== ''; }))
                : (array) $booking_data['children_ages'];
        }

        $rooms_data = [[
            'room_id'            => $booking_data['room_id'],
            'room_name'          => $booking_data['room_type'] ?? $booking_data['room_name'] ?? $booking_data['room_id'],
            'room_type_display'  => $booking_data['room_type'] ?? $booking_data['room_name'] ?? $booking_data['room_id'],
            'board_id'           => $booking_data['board_id'],
            'board_name'         => $booking_data['board_name'] ?? $booking_data['board_id'],
            'package_name'       => $booking_data['package_name'] ?? '',
            'check_in'           => $booking_data['check_in'],
            'check_out'          => $booking_data['check_out'],
            'adults'             => intval($booking_data['adults'] ?? 2),
            'children'           => intval($booking_data['children'] ?? 0),
            'childrenAges'       => $children_ages,
            'price'              => $booking_data['final_price'],
        ]];
    }

    // --- guests_data ---
    $guests_data = _nvt_resolve_guests_data($booking_data, $order_id, $debug);

    return [$rooms_data, $guests_data];
}

/**
 * Resolve guests_data with multiple fallback strategies.
 *
 * 1. From cart extra (already hydrated from DB if available)
 * 2. Re-fetch from DB by booking_id
 * 3. Match unassigned pending booking by hotel + dates
 *
 * @param array $booking_data Hydrated booking data
 * @param int   $order_id     CS-Cart order ID
 * @param bool  $debug        Whether to log
 * @return array Parsed guests data
 */
function _nvt_resolve_guests_data(array $booking_data, int $order_id, bool $debug): array
{
    // Primary: normalize from cart/DB data (handles both keyed and legacy array formats)
    if (!empty($booking_data['guests_data'])) {
        $guests_data = GuestDataNormalizer::normalize($booking_data['guests_data']);
        if (!empty($guests_data)) {
            return $guests_data;
        }
    }

    // Fallback 1: re-fetch from DB by booking_id
    $repo = new BookingRepository();
    $booking_id = intval($booking_data['novoton_booking_id'] ?? 0);
    if ($booking_id > 0) {
        $db_guests = $repo->getGuestsData($booking_id);
        if (!empty($db_guests)) {
            $guests_data = GuestDataNormalizer::normalize($db_guests);
            if ($debug) {
                fn_log_event('general', 'runtime', [
                    'message'      => 'Novoton - Fetched guests_data from database (cart was empty)',
                    'booking_id'   => $booking_id,
                    'guests_count' => count($guests_data),
                ]);
            }
            if (!empty($guests_data)) {
                return $guests_data;
            }
        }
    }

    // Fallback 2: match unassigned pending booking by hotel + dates
    $existing = $repo->findUnassignedByHotelDates(
        $booking_data['hotel_id'] ?? '',
        $booking_data['check_in'] ?? '',
        $booking_data['check_out'] ?? ''
    );
    if (!empty($existing['guests_data'])) {
        $guests_data = GuestDataNormalizer::normalize($existing['guests_data']);
        if ($debug) {
            fn_log_event('general', 'runtime', [
                'message'      => 'Novoton - Fetched guests_data from pending booking record',
                'holder_name'  => $existing['holder_name'] ?? '',
                'guests_count' => count($guests_data),
            ]);
        }
        return $guests_data;
    }

    return [];
}

/**
 * Group rooms by (package_name + check_in + check_out).
 *
 * Rooms with identical grouping keys can be sent in a single API call.
 *
 * @param array $rooms_data   Parsed rooms array
 * @param array $booking_data Hydrated booking data (for defaults)
 * @return array<string, array{package_name: string, check_in: string, check_out: string, rooms: array}>
 */
function _nvt_group_rooms_by_package(array $rooms_data, array $booking_data): array
{
    $groups           = [];
    $default_package  = $booking_data['package_name'] ?? '';
    $default_check_in = $booking_data['check_in'];
    $default_check_out = $booking_data['check_out'];

    foreach ($rooms_data as $room_idx => $room) {
        $package  = $room['package_name'] ?? $default_package;
        $check_in = $room['check_in']     ?? $default_check_in;
        $check_out = $room['check_out']    ?? $default_check_out;

        $group_key = md5($package . '|' . $check_in . '|' . $check_out);

        if (!isset($groups[$group_key])) {
            $groups[$group_key] = [
                'package_name' => $package,
                'check_in'     => $check_in,
                'check_out'    => $check_out,
                'rooms'        => [],
            ];
        }

        $room['original_index'] = $room_idx;
        $groups[$group_key]['rooms'][] = $room;
    }

    return $groups;
}

/**
 * Build per-group guest list and API room payload.
 *
 * For each room in the group, extracts adult/child guest names from
 * guests_data (keyed "room{N}_adult_{I}" / "room{N}_child_{I}") and
 * calculates the API price (without commission).
 *
 * @param array $group        Room group from _nvt_group_rooms_by_package()
 * @param array $guests_data  Parsed guests (keyed by room+type+index)
 * @param array $booking_data Hydrated booking data
 * @param float $commission   Commission percentage
 * @return array [all_guests[], api_rooms[], total_api_price, total_group_price]
 */
function _nvt_build_group_guests_and_rooms(
    array $group,
    array $guests_data,
    array $booking_data,
    float $commission
): array {
    $all_guests        = [];
    $api_rooms         = [];
    $total_api_price   = 0.0;
    $total_group_price = 0.0;

    foreach ($group['rooms'] as $room) {
        $room_idx       = $room['original_index'];
        $room_num       = $room_idx + 1;
        $adults_count   = intval($room['adults']   ?? 2);
        $children_count = intval($room['children'] ?? 0);
        $children_ages  = $room['childrenAges'] ?? [];
        $room_guests    = [];

        // Adults
        for ($i = 1; $i <= $adults_count; $i++) {
            $guest_key = "room{$room_num}_adult_{$i}";
            $name = _nvt_extract_guest_name($guests_data, $guest_key);

            if (empty($name)) {
                $name = ($room_num === 1 && $i === 1 && !empty($booking_data['holder_name']))
                    ? $booking_data['holder_name']
                    : "Adult {$i} Room {$room_num}";
            }

            $guest = [
                'name'     => $name,
                'birthday' => $guests_data[$guest_key]['birthday'] ?? '',
                'age'      => intval($guests_data[$guest_key]['age'] ?? 30),
                'type'     => 'adult',
                'room'     => $room_num,
            ];
            $room_guests[] = $guest;
            $all_guests[]  = $guest;
        }

        // Children
        for ($i = 1; $i <= $children_count; $i++) {
            $guest_key = "room{$room_num}_child_{$i}";
            $name = _nvt_extract_guest_name($guests_data, $guest_key);

            if (empty($name)) {
                $name = "Child {$i} Room {$room_num}";
            }

            $age = 6;
            if (isset($guests_data[$guest_key]['age'])) {
                $age = intval($guests_data[$guest_key]['age']);
            } elseif (isset($children_ages[$i - 1])) {
                $age = intval($children_ages[$i - 1]);
            }

            $guest = [
                'name'     => $name,
                'birthday' => $guests_data[$guest_key]['birthday'] ?? '',
                'age'      => $age,
                'type'     => 'child',
                'room'     => $room_num,
            ];
            $room_guests[] = $guest;
            $all_guests[]  = $guest;
        }

        // Price: reverse commission to get API (net) price
        $room_price_with_commission = floatval($room['price'] ?? 0);
        $room_api_price             = $room_price_with_commission / (1 + ($commission / 100));
        $total_api_price   += $room_api_price;
        $total_group_price += $room_price_with_commission;

        $api_rooms[] = [
            'room_id'  => $room['room_id']  ?? $booking_data['room_id'],
            'board_id' => $room['board_id'] ?? $booking_data['board_id'],
            'guests'   => $room_guests,
        ];
    }

    return [$all_guests, $api_rooms, $total_api_price, $total_group_price];
}

/**
 * Extract a guest name from the guests_data array.
 *
 * Prefers api_name (First Last format) over display_name/name.
 *
 * @param array  $guests_data Parsed guests keyed by room+type+index
 * @param string $guest_key   e.g. "room1_adult_1"
 * @return string Guest name or empty string
 */
function _nvt_extract_guest_name(array $guests_data, string $guest_key): string
{
    if (!isset($guests_data[$guest_key])) {
        return '';
    }

    $entry = $guests_data[$guest_key];

    if (!empty($entry['api_name'])) {
        return $entry['api_name'];
    }
    if (!empty($entry['name'])) {
        return $entry['name'];
    }

    return '';
}

/**
 * Construct the Novoton reservation API request payload.
 *
 * @param array $group        Room group
 * @param array $all_guests   Flat guest list for this group
 * @param array $api_rooms    API-formatted room list
 * @param array $booking_data Hydrated booking data
 * @param int   $order_id     CS-Cart order ID
 * @param int   $group_num    1-based group index
 * @param int   $total_groups Total number of groups
 * @return array API payload
 */
function _nvt_build_api_booking_request(
    array $group,
    array $all_guests,
    array $api_rooms,
    array $booking_data,
    int   $order_id,
    int   $group_num,
    int   $total_groups
): array {
    $suffix = $total_groups > 1 ? "-G{$group_num}" : '';

    $api_data = [
        'hotel_id'     => $booking_data['hotel_id'],
        'package_name' => $group['package_name'],
        'check_in'     => $group['check_in'],
        'check_out'    => $group['check_out'],
        'holder'       => $all_guests[0]['name'] ?? $booking_data['holder_name'] ?? 'Guest',
        'guests'       => $all_guests,
        'rooms'        => $api_rooms,
        'order_num'    => $order_id . $suffix,
        'remark'       => $booking_data['special_requests'] ?? '',
        'comment'      => $booking_data['special_requests'] ?? '',
    ];

    // Single-room shortcut
    if (count($group['rooms']) === 1) {
        $api_data['room_id']  = $group['rooms'][0]['room_id']  ?? $booking_data['room_id'];
        $api_data['board_id'] = $group['rooms'][0]['board_id'] ?? $booking_data['board_id'];
    }

    return $api_data;
}

/**
 * Build the booking record array for DB persistence.
 *
 * @return array Column => value map for novoton_bookings
 */
function _nvt_build_booking_record(
    array $group,
    array $all_guests,
    array $booking_data,
    array $product,
    int   $order_id,
    int   $group_num,
    int   $total_groups,
    float $total_api_price,
    float $total_group_price,
    array $api_data,
    bool  $disable_api
): array {
    $group_rooms = $group['rooms'];

    // Calculate nights safely
    try {
        $check_in_date  = new \DateTime($group['check_in']);
        $check_out_date = new \DateTime($group['check_out']);
        $nights         = $check_in_date->diff($check_out_date)->days;
    } catch (\InvalidArgumentException $e) {
        fn_log_event('general', 'error', [
            'message'   => 'Novoton - Invalid date in booking group',
            'check_in'  => $group['check_in'] ?? '',
            'check_out' => $group['check_out'] ?? '',
            'error'     => $e->getMessage(),
        ]);
        $nights = intval($booking_data['nights'] ?? 7);
    }

    $order_info    = fn_get_order_info($order_id);
    $order_user_id = intval($order_info['user_id'] ?? 0);
    $order_email   = $order_info['email'] ?? '';

    return [
        'order_id'         => $order_id,
        'product_id'       => $product['product_id'],
        'item_id'          => $product['item_id'] ?? '',
        'hotel_id'         => $booking_data['hotel_id'],
        'hotel_name'       => $booking_data['hotel_name'] ?? '',
        'package_name'     => $group['package_name'],
        'room_id'          => implode(', ', array_column($group_rooms, 'room_id')),
        'room_type'        => $group_rooms[0]['room_type_display'] ?? $group_rooms[0]['room_name'] ?? '',
        'board_id'         => $group_rooms[0]['board_id'] ?? $booking_data['board_id'],
        'board_name'       => $group_rooms[0]['board_name'] ?? $booking_data['board_name'] ?? '',
        'check_in'         => $group['check_in'],
        'check_out'        => $group['check_out'],
        'nights'           => $nights,
        'adults'           => array_sum(array_column($group_rooms, 'adults')),
        'children'         => array_sum(array_column($group_rooms, 'children')),
        'children_ages'    => $booking_data['children_ages'] ?? '',
        'num_rooms'        => count($group_rooms),
        'room_number'      => $group_num,
        'total_rooms'      => $total_groups,
        'rooms_data'       => json_encode($group_rooms),
        'guest_name'       => implode(', ', array_column($all_guests, 'name')),
        'holder_name'      => $all_guests[0]['name'] ?? $booking_data['holder_name'] ?? '',
        'guests_data'      => json_encode($all_guests),
        'base_price'       => $total_api_price,
        'total_price'      => $total_group_price,
        'currency'         => ConfigProvider::getApiCurrency(),
        'status'           => 'pending',
        'special_requests' => $booking_data['special_requests'] ?? '',
        'api_request'      => json_encode($api_data),
        'notes'                          => $disable_api ? 'API submission disabled - test mode' : '',
        'user_id'                        => $order_user_id,
        'guest_email'                    => $order_email,
        // Persist terms at booking creation so order display never needs a live API call
        'terms_of_payment_raw'           => $booking_data['terms_of_payment_raw'] ?? null,
        'terms_of_cancellation_raw'      => $booking_data['terms_of_cancellation_raw'] ?? null,
        'terms_of_payment_formatted'     => $booking_data['terms_of_payment'] ?? $booking_data['terms_of_payment_formatted'] ?? null,
        'terms_of_cancellation_formatted' => $booking_data['terms_of_cancellation'] ?? $booking_data['terms_of_cancellation_formatted'] ?? null,
    ];
}

/**
 * Upsert a booking record using the Single Source of Truth pattern.
 *
 * Priority:
 *   1. Update by original_booking_id (from cart) for group 1
 *   2. Update existing row matching (order + hotel + dates) to prevent duplicates
 *   3. Insert new row
 *
 * @param array $record              Column => value map
 * @param int   $original_booking_id Booking ID carried in cart session
 * @param int   $group_num           1-based group index
 * @param int   $order_id            CS-Cart order ID
 * @return int  Resolved booking_id
 */
function _nvt_persist_booking_record(
    array $record,
    int   $original_booking_id,
    int   $group_num,
    int   $order_id
): int {
    $repo = new BookingRepository();

    // Group 1: update the original booking from cart
    if ($group_num === 1 && $original_booking_id > 0) {
        $repo->update($original_booking_id, $record);
        return $original_booking_id;
    }

    // Dedup: find existing booking for this order + hotel + dates
    $existing_id = $repo->findIdByOrderAndHotelDates(
        $order_id, $record['hotel_id'], $record['check_in'], $record['check_out']
    );

    if ($existing_id) {
        $repo->update($existing_id, $record);
        return $existing_id;
    }

    // New booking
    $record['session_id'] = session_id();
    return $repo->create($record);
}

/**
 * Submit booking to the Novoton reservation API and update the DB row.
 *
 * Uses Constants::NOVOTON_STATUS_TO_INTERNAL for status mapping.
 *
 * @param \Tygh\Addons\NovotonHolidays\NovotonApi $api
 * @param array  $api_data       API payload
 * @param int    $booking_id     DB booking ID
 * @param int    $order_id       CS-Cart order ID
 * @param int    $group_num      Group index (for logging)
 * @param float  $total_api_price Net price (without commission)
 * @param bool   $disable_api    Skip API call (test mode)
 * @param bool   $debug          Enable debug logging
 */
function _nvt_submit_and_record_booking(
    $api,
    array $api_data,
    int   $booking_id,
    int   $order_id,
    int   $group_num,
    float $total_api_price,
    bool  $disable_api,
    bool  $debug
): void {
    $repo = new BookingRepository();

    if ($disable_api) {
        $repo->update($booking_id, ['notes' => 'API submission disabled - booking saved locally only.']);
        return;
    }

    try {
        $response = $api->createReservation($api_data);

        if ($response) {
            $novoton_id     = (string) ($response->IdNum   ?? '');
            $novoton_status = (string) ($response->Status  ?? '');
            $novoton_price  = (string) ($response->Price   ?? '');

            $update = [
                'novoton_invoice_id' => $novoton_id,
                'novoton_status'     => $novoton_status,
                'api_price'          => !empty($novoton_price) ? floatval($novoton_price) : $total_api_price,
                'api_response'       => json_encode([
                    'IdNum'    => $novoton_id,
                    'Price'    => $novoton_price,
                    'Currency' => (string) ($response->Currency ?? 'EUR'),
                    'Quota'    => (string) ($response->Quota    ?? ''),
                    'Status'   => $novoton_status,
                ]),
            ];

            // Map API status to internal status via centralized constant
            $status_map = \Tygh\Addons\NovotonHolidays\Constants::NOVOTON_STATUS_TO_INTERNAL;
            if (isset($status_map[$novoton_status])) {
                $update['status'] = $status_map[$novoton_status];
            }

            $repo->update($booking_id, $update);

            if ($debug) {
                fn_log_event('general', 'runtime', [
                    'message'    => 'Novoton Booking - API Response',
                    'order_id'   => $order_id,
                    'booking_id' => $booking_id,
                    'novoton_id' => $novoton_id,
                    'status'     => $novoton_status,
                ]);
            }
        }
    } catch (ApiException $e) {
        $repo->update($booking_id, [
            'status' => 'failed',
            'notes'  => 'API Error (' . $e->getApiFunction() . ', HTTP ' . $e->getHttpCode() . '): ' . $e->getMessage(),
        ]);

        fn_log_event('general', 'runtime', [
            'message'  => 'Novoton Booking API Error',
            'order_id' => $order_id,
            'group'    => $group_num,
            'error'    => $e->getMessage(),
        ]);
    }
}

// ============================================================================
// HOOK: get_orders_post
// ============================================================================

/**
 * Hook: after getting orders — attach booking data to order listings.
 *
 * Uses a single batch query (not N+1) to fetch all bookings for all
 * orders in the result set.
 */
function fn_novoton_holidays_get_orders_post($params, &$orders): void
{
    if (empty($orders)) {
        return;
    }

    $order_ids = array_column($orders, 'order_id');
    if (empty($order_ids)) {
        return;
    }

    $repo = new BookingRepository();
    $all_bookings = $repo->findByOrderIds($order_ids);

    if (empty($all_bookings)) {
        return;
    }

    $bookings_by_order = [];
    foreach ($all_bookings as $booking) {
        $bookings_by_order[$booking['order_id']][] = $booking;
    }

    foreach ($orders as &$order) {
        if (!empty($order['order_id']) && isset($bookings_by_order[$order['order_id']])) {
            $order['hotel_bookings'] = $bookings_by_order[$order['order_id']];
        }
    }
}

// ============================================================================
// HOOK: get_order_info
// ============================================================================

/**
 * Hook: After getting order info — format Novoton booking terms for display.
 *
 * Enriches order products with:
 *   - Hotel location (city, region, country)
 *   - Formatted dates (CS-Cart date format)
 *   - Payment & cancellation terms (from raw XML or API)
 *   - Guest display names (Last, First format)
 *   - Board display name via BoardType value object
 */
function fn_novoton_holidays_get_order_info(&$order, $additional_data): void
{
    if (!empty($_REQUEST['debug'])) {
        fn_set_notification('N', 'DEBUG', 'fn_novoton_holidays_get_order_info hook fired for order #' . ($order['order_id'] ?? '?'));
    }

    if (empty($order['products'])) {
        return;
    }

    $date_format   = Registry::get('settings.Appearance.date_format') ?: '%d %b %Y';
    $currency_code = $order['secondary_currency'] ?? 'EUR';

    // Pre-fetch hotel locations in single query (avoid N+1)
    $hotel_ids = [];
    foreach ($order['products'] as $product) {
        if (!empty($product['extra']['novoton_booking']) && !empty($product['extra']['hotel_id']) && empty($product['extra']['city'])) {
            $hotel_ids[$product['extra']['hotel_id']] = true;
        }
    }
    $hotels_cache = [];
    if (!empty($hotel_ids)) {
        $hotelRepo = new HotelRepository();
        $hotels_cache = $hotelRepo->getLocationsByIds(array_keys($hotel_ids));
    }

    foreach ($order['products'] as &$product) {
        if (!empty($_REQUEST['debug'])) {
            fn_set_notification('N', 'DEBUG', 'Product extra keys: ' . implode(', ', array_keys($product['extra'] ?? [])));
        }

        if (empty($product['extra']['novoton_booking'])) {
            continue;
        }

        $hotel_id    = $product['extra']['hotel_id']  ?? '';
        $check_in    = $product['extra']['check_in']  ?? '';
        $check_out   = $product['extra']['check_out'] ?? '';
        $total_price = floatval($product['extra']['total_price'] ?? $product['price'] ?? 0);

        // [1] Hotel location
        if (!empty($hotel_id) && empty($product['extra']['city']) && isset($hotels_cache[$hotel_id])) {
            $loc = $hotels_cache[$hotel_id];
            $product['extra']['city']    = $loc['city']    ?? '';
            $product['extra']['region']  = $loc['region']  ?? '';
            $product['extra']['country'] = $loc['country'] ?? '';
        }

        // [2] Formatted dates
        $ci_ts = !empty($check_in)  ? strtotime($check_in)  : false;
        $co_ts = !empty($check_out) ? strtotime($check_out) : false;
        if ($ci_ts !== false) {
            $product['extra']['check_in_formatted']  = fn_date_format($ci_ts, $date_format);
        }
        if ($co_ts !== false) {
            $product['extra']['check_out_formatted'] = fn_date_format($co_ts, $date_format);
        }

        // [3] Payment & cancellation terms
        _nvt_enrich_order_product_terms($product, $hotel_id, $check_in, $check_out, $total_price, $currency_code);

        // [4] Board display name
        $board_id = $product['extra']['board_id'] ?? $product['extra']['board'] ?? '';
        if (!empty($board_id)) {
            $product['extra']['board_display'] = fn_novoton_format_board_name($board_id);
        }

        // [5] Guests data formatting
        _nvt_format_order_guests($product);

        if (!empty($_REQUEST['debug'])) {
            $payment_set    = !empty($product['extra']['terms_of_payment_formatted'])      ? 'YES' : 'NO';
            $payment_amounts = !empty($product['extra']['terms_of_payment_with_amounts'])   ? 'YES' : 'NO';
            $cancel_set     = !empty($product['extra']['terms_of_cancellation_formatted']) ? 'YES' : 'NO';
            fn_set_notification('N', 'DEBUG', "terms_of_payment_formatted: {$payment_set}, with_amounts: {$payment_amounts}, cancellation: {$cancel_set}");
        }
    }
}

/**
 * Enrich an order product with formatted payment and cancellation terms.
 *
 * Terms are persisted in novoton_bookings at booking creation time.
 * Falls back to a DB lookup by booking_id if not in cart extra.
 * No live API call is made — terms are a snapshot from booking time.
 */
function _nvt_enrich_order_product_terms(
    array &$product,
    string $hotel_id,
    string $check_in,
    string $check_out,
    float  $total_price,
    string $currency_code
): void {
    $payment_raw  = $product['extra']['terms_of_payment_raw']      ?? '';
    $payment_text = $product['extra']['terms_of_payment']          ?? '';
    $cancel_raw   = $product['extra']['terms_of_cancellation_raw'] ?? '';
    $cancel_text  = $product['extra']['terms_of_cancellation']     ?? '';

    // Fallback: fetch from novoton_bookings DB record (terms are persisted at booking creation)
    if (empty($payment_raw) && empty($payment_text) && empty($cancel_raw) && empty($cancel_text)) {
        $booking_id = intval($product['extra']['novoton_booking_id'] ?? 0);
        if ($booking_id > 0) {
            $repo = new BookingRepository();
            $terms = $repo->getTerms($booking_id);
            if (!empty($terms)) {
                $payment_raw  = $terms['terms_of_payment_raw'] ?? '';
                $cancel_raw   = $terms['terms_of_cancellation_raw'] ?? '';
                $payment_text = $terms['terms_of_payment_formatted'] ?? '';
                $cancel_text  = $terms['terms_of_cancellation_formatted'] ?? '';
            }
        }
    }

    // Format payment terms
    if (!empty($payment_raw) && $total_price > 0) {
        $product['extra']['terms_of_payment_with_amounts'] = fn_novoton_format_payment_terms_with_amounts(
            $payment_raw, $total_price, $currency_code
        );
        $product['extra']['terms_of_payment_formatted'] = fn_novoton_format_payment_terms($payment_raw);
    } elseif (!empty($payment_raw)) {
        $product['extra']['terms_of_payment_formatted'] = fn_novoton_format_payment_terms($payment_raw);
    } elseif (!empty($payment_text)) {
        $product['extra']['terms_of_payment_formatted'] = $payment_text;
    }

    // Format cancellation terms
    if (!empty($cancel_raw)) {
        $product['extra']['terms_of_cancellation_formatted'] = fn_novoton_format_cancellation_terms($cancel_raw, $check_in);
    } elseif (!empty($cancel_text)) {
        $product['extra']['terms_of_cancellation_formatted'] = $cancel_text;
    }
}

/**
 * Format guests_data on an order product for email/display.
 *
 * Converts api_name (First Last) to display format (Last, First) and
 * marks the holder guest.
 */
function _nvt_format_order_guests(array &$product): void
{
    $guests_data = $product['extra']['guests_data'] ?? null;

    if (empty($guests_data)) {
        return;
    }

    $guests_data = GuestDataNormalizer::normalize($guests_data);
    if (empty($guests_data)) {
        return;
    }

    $formatted   = [];
    $holder_name = $product['extra']['holder_name'] ?? '';
    $is_first    = true;

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
        $is_holder  = false;

        if ($is_first && $guest_type === 'adult') {
            $is_holder = true;
            $is_first  = false;
        } elseif (!empty($holder_name) && stripos($display_name, $holder_name) !== false) {
            $is_holder = true;
        }

        $formatted[$key] = [
            'display_name' => $display_name,
            'name'         => $guest['name'] ?? $display_name,
            'type'         => $guest_type,
            'age'          => intval($guest['age'] ?? 0),
            'is_holder'    => $is_holder,
            'birthday'     => $guest['birthday'] ?? '',
            'room'         => $guest['room'] ?? 1,
        ];
    }

    $product['extra']['guests_data'] = $formatted;
}
