<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Add to Cart Mode
 *
 * Optionally customizes the package (adds optional services),
 * then creates booking records and adds to CS-Cart cart.
 *
 * Flow: verify → customize (optional) → add to cart → book at order placement
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $cartService = new CartService();

    if ($rateLimited = $cartService->checkRateLimit()) {
        return $rateLimited;
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    if ($duplicate = $cartService->checkDuplicate($offer_id)) {
        return $duplicate;
    }

    // Optional: customize package with selected services
    $selected_services = RequestCoerce::list($_REQUEST, 'services');
    $customized = null;
    if (!empty($selected_services)) {
        try {
            $customized = Container::getApi()->customizePackage([
                'offer_id' => $offer_id,
                'service_codes' => $selected_services,
            ]);
            if (!empty($customized['data'])) {
                $customized = $customized['data'];
            }
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx package customize failed: ' . $e->getMessage()]);
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
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // Resolve product
    $product_id = $cartService->resolveProductId($hotel_id, RequestCoerce::int($_REQUEST, 'product_id'));
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Package product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // Guest validation
    $check_in  = RequestCoerce::string($_REQUEST, 'check_in');
    $check_out = RequestCoerce::string($_REQUEST, 'check_out');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $check_in);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_booking_form?' . http_build_query([
            'offer_id' => $offer_id,
        ])];
    }

    // Extract type-specific
    $contact       = RequestCoerce::stringMap($_REQUEST, 'contact');
    $hotelName     = RequestCoerce::string($_REQUEST, 'hotel_name');
    $roomName      = RequestCoerce::string($_REQUEST, 'room_name');
    $boardName     = RequestCoerce::string($_REQUEST, 'board_name');
    $transport_type = RequestCoerce::string($_REQUEST, 'transport_type');
    $nights        = RequestCoerce::int($_REQUEST, 'nights');
    $adults        = RequestCoerce::int($_REQUEST, 'adults', 2);
    $children      = RequestCoerce::int($_REQUEST, 'children');
    $children_ages = RequestCoerce::string($_REQUEST, 'children_ages');
    $num_rooms     = max(1, RequestCoerce::int($_REQUEST, 'num_rooms', 1));

    if (empty($nights) && !empty($check_in) && !empty($check_out)) {
        $ci_ts = strtotime($check_in);
        $co_ts = strtotime($check_out);
        if ($ci_ts !== false && $co_ts !== false) {
            $nights = (int) round(($co_ts - $ci_ts) / 86400);
        }
    }

    $rooms_data = [];
    $roomsJsonStr = RequestCoerce::string($_REQUEST, 'rooms_json');
    if (!empty($roomsJsonStr)) {
        $roomsJsonRaw = json_decode($roomsJsonStr, true);
        $rooms_data = is_array($roomsJsonRaw) ? TypeCoerce::toRowList($roomsJsonRaw) : [];
    }
    if (empty($rooms_data)) {
        $rooms_data = [['room_id' => '', 'room_name' => $roomName, 'adults' => $adults, 'children' => $children]];
    }

    $service_codes = [];
    if (!empty($selected_services)) {
        $service_codes = $selected_services;
    }

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, $hotel_id, $offer_id, $hotelName,
        $parsed_guests, $contact, $basePrice, $total_price, $priceCurrency,
        is_array($customized) ? TypeCoerce::toStringMap($customized) : $bookingData
    );
    $firstRoom = TypeCoerce::toStringMap($rooms_data[0]);
    $booking_record += [
        'room_id'       => TypeCoerce::toString($firstRoom['room_id'] ?? ''),
        'room_type'     => 'package',
        'board_id'      => $boardName,
        'check_in'      => $check_in,
        'check_out'     => $check_out,
        'nights'        => $nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => $num_rooms,
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    $booking_id = $cartService->upsertBooking(
        $booking_record, $hotel_id, $check_in, $check_out, TypeCoerce::toString($parsed_guests['holder_name'])
    );

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'booking_type' => 'package',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => TypeCoerce::toString($firstRoom['room_id'] ?? ''), 'room_name' => $roomName,
        'board_id' => $boardName, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => $num_rooms, 'rooms_data' => $rooms_data,
        'transport_type' => $transport_type,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $priceCurrency,
        'additional_services' => $service_codes,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $priceCurrency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.package_added_to_cart', ['[default]' => 'Package booking added to cart.']))
    );
