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
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $cartService = new CartService();

    if (($rateLimited = $cartService->checkRateLimit()) !== null) {
        return $rateLimited;
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $circuit_id = RequestCoerce::int($_REQUEST, 'circuit_id');

    if (empty($offer_id) || empty($circuit_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    if (($duplicate = $cartService->checkDuplicate($offer_id)) !== null) {
        return $duplicate;
    }

    // Optional: customize circuit with selected services
    $selected_services = RequestCoerce::list($_REQUEST, 'services');
    $customized = null;
    if (!empty($selected_services)) {
        try {
            $customized = Container::getApi()->customizeCircuit([
                'offer_id' => $offer_id,
                'service_codes' => $selected_services,
            ]);
            if (!empty($customized['data'])) {
                $customized = $customized['data'];
            }
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx circuit customize failed: ' . $e->getMessage()]);
        }
    }

    // Pricing
    $customizedMap = TypeCoerce::toStringMap($customized);
    $customizedPricing = TypeCoerce::toStringMap($customizedMap['pricing'] ?? null);
    $total_price   = TypeCoerce::toFloat($customizedPricing['selling_price'] ?? $bookingData['total_price'] ?? 0);
    $basePrice     = TypeCoerce::toFloat($customizedPricing['supplier_price'] ?? $bookingData['base_price'] ?? $total_price);
    $priceCurrency = TypeCoerce::toString($customizedPricing['currency'] ?? $bookingData['currency'] ?? ConfigProvider::getDefaultCurrency());
    $total_price   = $cartService->applyCommission($total_price);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Resolve product
    $product_id = $cartService->resolveProductId((string) $circuit_id, RequestCoerce::int($_REQUEST, 'product_id'));
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Circuit product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Guest validation
    $departure_date = RequestCoerce::string($_REQUEST, 'departure_date');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $departure_date);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_booking_form?' . http_build_query([
            'circuit_id' => $circuit_id, 'departure_date' => $departure_date,
        ])];
    }

    // Extract type-specific fields
    $contact         = RequestCoerce::stringMap($_REQUEST, 'contact');
    $title           = RequestCoerce::string($_REQUEST, 'title');
    $departure_name  = RequestCoerce::string($_REQUEST, 'departure_name');
    $transport_type  = RequestCoerce::string($_REQUEST, 'transport_type');
    $duration_days   = RequestCoerce::int($_REQUEST, 'duration_days');
    $duration_nights = RequestCoerce::int($_REQUEST, 'duration_nights');
    $adults          = RequestCoerce::int($_REQUEST, 'adults', 2);
    $children        = RequestCoerce::int($_REQUEST, 'children');
    $children_ages   = RequestCoerce::string($_REQUEST, 'children_ages');

    $customizedRooms = TypeCoerce::toRowList($customizedMap['rooms'] ?? null);
    $roomsJsonRaw = json_decode(RequestCoerce::string($_REQUEST, 'rooms_json', '[]'), true);
    $roomsFallback = is_array($roomsJsonRaw) ? $roomsJsonRaw : [];
    $rooms = !empty($customizedRooms) ? $customizedRooms : TypeCoerce::toRowList($roomsFallback);
    $rooms_data = [];
    foreach ($rooms as $room) {
        $childrenAgesArr = TypeCoerce::toList($room['children_ages'] ?? []);
        $rooms_data[] = [
            'room_id'      => TypeCoerce::toString($room['code'] ?? ''),
            'room_name'    => TypeCoerce::toString($room['name'] ?? ''),
            'adults'       => TypeCoerce::toInt($room['adults'] ?? $adults),
            'children'     => count($childrenAgesArr),
            'childrenAges' => $childrenAgesArr,
        ];
    }
    if (empty($rooms_data)) {
        $rooms_data = [['room_id' => '', 'room_name' => 'Standard', 'adults' => $adults, 'children' => $children]];
    }

    $check_out = !empty($departure_date) && $duration_days > 0
        ? date('Y-m-d', (int) strtotime($departure_date . " + {$duration_days} days"))
        : '';

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, (string) $circuit_id, $offer_id, $title,
        $parsed_guests, $contact, $basePrice, $total_price, $priceCurrency,
        is_array($customized) ? TypeCoerce::toStringMap($customized) : $bookingData
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
        $booking_record, (string) $circuit_id, $departure_date, '', TypeCoerce::toString($parsed_guests['holder_name'])
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
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $priceCurrency,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $priceCurrency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.circuit_added_to_cart', ['[default]' => 'Circuit booking added to cart.']))
    );
