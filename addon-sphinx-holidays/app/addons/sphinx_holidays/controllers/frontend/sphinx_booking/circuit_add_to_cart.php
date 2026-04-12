<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Add to Cart Mode
 *
 * Optionally customizes the circuit (adds optional services),
 * then creates booking records and adds to CS-Cart cart.
 *
 * Flow: quote → customize (optional) → book → add to cart
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;

    $cartService = new CartService();

    if ($rateLimited = $cartService->checkRateLimit()) {
        return $rateLimited;
    }

    $bookingData = $_REQUEST;
    $offer_id = trim($bookingData['offer_id'] ?? '');
    $circuit_id = (int) ($bookingData['circuit_id'] ?? 0);

    if (empty($offer_id) || empty($circuit_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    if ($duplicate = $cartService->checkDuplicate($offer_id)) {
        return $duplicate;
    }

    // Optional: customize circuit with selected services
    $selected_services = $bookingData['services'] ?? [];
    $customized = null;
    if (!empty($selected_services) && is_array($selected_services)) {
        try {
            $customized = Container::getApi()->customizeCircuit([
                'offer_id' => $offer_id,
                'service_codes' => array_values($selected_services),
            ]);
            if (!empty($customized['data'])) {
                $customized = $customized['data'];
            }
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx circuit customize failed: ' . $e->getMessage()]);
        }
    }

    // Pricing
    $total_price   = (float) ($customized['pricing']['selling_price'] ?? $bookingData['total_price'] ?? 0);
    $basePrice     = (float) ($customized['pricing']['supplier_price'] ?? $bookingData['base_price'] ?? $total_price);
    $priceCurrency = $customized['pricing']['currency'] ?? $bookingData['currency'] ?? ConfigProvider::getDefaultCurrency();
    $total_price   = $cartService->applyCommission($total_price);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Resolve product
    $product_id = $cartService->resolveProductId((string) $circuit_id, (int) ($bookingData['product_id'] ?? 0));
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Circuit product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Guest validation
    $departure_date = trim($bookingData['departure_date'] ?? '');
    $parsed_guests = $cartService->parseGuests($bookingData['guests'] ?? [], $departure_date);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_booking_form?' . http_build_query([
            'circuit_id' => $circuit_id, 'departure_date' => $departure_date,
        ])];
    }

    // Extract type-specific fields
    $contact         = $bookingData['contact'] ?? [];
    $title           = trim($bookingData['title'] ?? '');
    $departure_name  = trim($bookingData['departure_name'] ?? '');
    $transport_type  = trim($bookingData['transport_type'] ?? '');
    $duration_days   = (int) ($bookingData['duration_days'] ?? 0);
    $duration_nights = (int) ($bookingData['duration_nights'] ?? 0);
    $adults          = (int) ($bookingData['adults'] ?? 2);
    $children        = (int) ($bookingData['children'] ?? 0);
    $children_ages   = trim($bookingData['children_ages'] ?? '');

    $rooms = $customized['rooms'] ?? json_decode($bookingData['rooms_json'] ?? '[]', true) ?: [];
    $rooms_data = [];
    foreach ($rooms as $room) {
        $rooms_data[] = [
            'room_id'      => $room['code'] ?? '',
            'room_name'    => $room['name'] ?? '',
            'adults'       => (int) ($room['adults'] ?? $adults),
            'children'     => count($room['children_ages'] ?? []),
            'childrenAges' => $room['children_ages'] ?? [],
        ];
    }
    if (empty($rooms_data)) {
        $rooms_data = [['room_id' => '', 'room_name' => 'Standard', 'adults' => $adults, 'children' => $children]];
    }

    $check_out = !empty($departure_date) && $duration_days > 0
        ? date('Y-m-d', strtotime($departure_date . " + {$duration_days} days"))
        : '';

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, (string) $circuit_id, $offer_id, $title,
        $parsed_guests, $contact, $basePrice, $total_price, $priceCurrency,
        $customized ?? $bookingData
    );
    $booking_record += [
        'room_id'       => $rooms_data[0]['room_id'],
        'room_type'     => 'circuit',
        'board_id'      => $transport_type,
        'check_in'      => $departure_date,
        'check_out'     => $check_out,
        'nights'        => $duration_nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => count($rooms_data),
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    $booking_id = $cartService->upsertBooking(
        $booking_record, (string) $circuit_id, $departure_date, '', $parsed_guests['holder_name']
    );

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'booking_type' => 'circuit',
        'hotel_id' => (string) $circuit_id, 'hotel_name' => $title, 'offer_id' => $offer_id,
        'room_id' => $rooms_data[0]['room_id'], 'room_name' => $rooms_data[0]['room_name'],
        'board_id' => $transport_type, 'board_name' => ucfirst($transport_type),
        'check_in' => $departure_date, 'check_out' => $check_out, 'nights' => $duration_nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => count($rooms_data), 'rooms_data' => $rooms_data,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => $contact['email'] ?? '', 'contact_phone' => $contact['phone'] ?? '',
        'total_price' => $total_price, 'currency' => $priceCurrency,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $priceCurrency, $product_extra,
        __('sphinx_holidays.circuit_added_to_cart', ['[default]' => 'Circuit booking added to cart.'])
    );
