<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Add to Cart Mode (Hotels)
 *
 * Verifies the offer, creates records in sphinx_bookings and travel_bookings,
 * then adds the product to the CS-Cart cart.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $cartService = new CartService();

    // Rate limit + input
    if (($rateLimited = $cartService->checkRateLimit()) !== null) {
        return $rateLimited;
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $product_id = RequestCoerce::int($_REQUEST, 'product_id');

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Verify offer price with Sphinx API
    try {
        $verifyResult = Container::getApi()->verifyHotelOffer($offer_id);
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Sphinx add_to_cart verify failed: ' . $e->getMessage()]);
        $verifyResult = null;
    }

    if (!\Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::isVerifiedAvailable(
        $verifyResult,
        ConfigProvider::shouldRequireImmediateAvailability(),
    )) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx add_to_cart: offer rejected by verify',
            'offer_id' => $offer_id,
            'verify_response' => substr((string) json_encode($verifyResult, JSON_UNESCAPED_UNICODE), 0, 1000),
        ]);
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // The gate above rejects null/empty responses — narrow for the reads below.
    $verifyResult = TypeCoerce::toStringMap($verifyResult);

    // Price with commission
    $basePrice = \Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::extractPrice($verifyResult);
    $total_price = $cartService->applyCommission($basePrice);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Resolve product
    $product_id = $cartService->resolveProductId($hotel_id, $product_id);
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Hotel product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Guest validation
    $check_in = TypeCoerce::toString($verifyResult['check_in'] ?? $bookingData['check_in'] ?? '');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $check_in);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.booking_form?' . http_build_query([
            'offer_id' => $offer_id, 'hotel_id' => $hotel_id, 'product_id' => $product_id,
        ])];
    }

    // Extract offer details
    $contact     = RequestCoerce::stringMap($_REQUEST, 'contact');
    $hotelName   = TypeCoerce::toString($verifyResult['hotel_name'] ?? '');
    $roomName    = TypeCoerce::toString($verifyResult['room_name'] ?? $verifyResult['room_type'] ?? '');
    $boardName   = TypeCoerce::toString($verifyResult['board_name'] ?? $verifyResult['board_type'] ?? '');
    $boardId     = TypeCoerce::toString($verifyResult['board_code'] ?? $boardName);
    $roomId      = TypeCoerce::toString($verifyResult['room_code'] ?? $roomName);
    $check_out   = TypeCoerce::toString($verifyResult['check_out'] ?? $bookingData['check_out'] ?? '');
    $adults      = TypeCoerce::toInt($verifyResult['adults'] ?? $bookingData['adults'] ?? 2);
    $children    = TypeCoerce::toInt($verifyResult['children'] ?? $bookingData['children'] ?? 0);
    $currency    = ConfigProvider::getDefaultCurrency();

    $nights = 0;
    if (!empty($check_in) && !empty($check_out)) {
        $ci_ts = strtotime($check_in);
        $co_ts = strtotime($check_out);
        if ($ci_ts !== false && $co_ts !== false) {
            $nights = (int) round(($co_ts - $ci_ts) / 86400);
        }
    }

    $all_child_ages = [];
    foreach (TypeCoerce::toRowList($parsed_guests['guests_data']) as $guest) {
        if (TypeCoerce::toString($guest['type'] ?? '') === 'child' && isset($guest['age'])) {
            $all_child_ages[] = TypeCoerce::toInt($guest['age']);
        }
    }
    $children_ages = !empty($all_child_ages) ? implode(',', $all_child_ages) : TypeCoerce::toString($bookingData['children_ages'] ?? '');

    $rooms_data = [[
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'adults' => $adults, 'children' => $children, 'childrenAges' => $all_child_ages,
        'price' => $total_price,
    ]];

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, $hotel_id, $offer_id, $hotelName,
        $parsed_guests, $contact, $basePrice, $total_price, $currency, $verifyResult
    );
    $booking_record += [
        'room_id'       => $roomId,
        'room_type'     => $roomName,
        'board_id'      => $boardId,
        'check_in'      => $check_in,
        'check_out'     => $check_out,
        'nights'        => $nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => 1,
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    // Payment & cancellation terms from the verify response: raw JSON on the
    // booking row (the orders sync later overwrites with authoritative data)
    // and formatted display lines in the cart extras for order pages.
    $payment_terms_raw = TypeCoerce::toList($verifyResult['payment_terms'] ?? []);
    $cancellation_fees_raw = TypeCoerce::toList($verifyResult['cancellation_fees'] ?? []);
    if ($payment_terms_raw !== []) {
        $booking_record['payment_terms_json'] = json_encode($payment_terms_raw, JSON_UNESCAPED_UNICODE);
    }
    if ($cancellation_fees_raw !== []) {
        $booking_record['cancellation_fees_json'] = json_encode($cancellation_fees_raw, JSON_UNESCAPED_UNICODE);
    }

    $booking_id = $cartService->upsertBooking(
        $booking_record, $hotel_id, $check_in, $check_out, TypeCoerce::toString($parsed_guests['holder_name'])
    );

    // Assemble cart extras
    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => 1, 'rooms_data' => $rooms_data,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $currency,
        'payment_terms' => \Tygh\Addons\SphinxHolidays\Services\TermsFormatter::lines($payment_terms_raw),
        'cancellation_fees' => \Tygh\Addons\SphinxHolidays\Services\TermsFormatter::lines($cancellation_fees_raw),
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $currency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.added_to_cart', ['[default]' => 'Hotel booking added to cart.']))
    );
