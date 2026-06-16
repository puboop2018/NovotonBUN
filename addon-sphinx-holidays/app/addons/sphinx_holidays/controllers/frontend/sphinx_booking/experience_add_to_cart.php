<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Experience Add to Cart Mode
 *
 * Creates booking records and adds to CS-Cart cart.
 * No customize step for experiences (simpler than circuits).
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $cartService = new CartService();

    if (($rateLimited = $cartService->checkRateLimit()) !== null) {
        return $rateLimited;
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $experience_id = RequestCoerce::int($_REQUEST, 'experience_id');

    if (empty($offer_id) || empty($experience_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

    if (($duplicate = $cartService->checkDuplicate($offer_id)) !== null) {
        return $duplicate;
    }

    // Pricing
    $total_price = TypeCoerce::toFloat($bookingData['total_price'] ?? 0);
    $basePrice   = TypeCoerce::toFloat($bookingData['base_price'] ?? $total_price);
    $currency    = RequestCoerce::string($_REQUEST, 'currency', ConfigProvider::getDefaultCurrency());
    $total_price = $cartService->applyCommission($total_price);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

    // Resolve product
    $product_id = $cartService->resolveProductId((string) $experience_id, RequestCoerce::int($_REQUEST, 'product_id'));
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Experience product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_search'];
    }

    // Guest validation
    $departure_date = RequestCoerce::string($_REQUEST, 'departure_date');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $departure_date);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.experience_booking_form?' . http_build_query([
            'experience_id' => $experience_id, 'departure_date' => $departure_date,
        ])];
    }

    // Extract type-specific
    $contact        = RequestCoerce::stringMap($_REQUEST, 'contact');
    $title          = RequestCoerce::string($_REQUEST, 'title');
    $duration_days  = RequestCoerce::int($_REQUEST, 'duration_days');
    $adults         = RequestCoerce::int($_REQUEST, 'adults', 1);
    $children       = RequestCoerce::int($_REQUEST, 'children');
    $children_ages  = RequestCoerce::string($_REQUEST, 'children_ages');

    $check_out = !empty($departure_date) && $duration_days > 0
        ? date('Y-m-d', (int) strtotime($departure_date . " + {$duration_days} days"))
        : $departure_date;
    $nights = max(0, $duration_days - 1);

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, (string) $experience_id, $offer_id, $title,
        $parsed_guests, $contact, $basePrice, $total_price, $currency, $bookingData
    );
    $booking_record += [
        'room_id'       => '',
        'room_type'     => 'experience',
        'board_id'      => '',
        'check_in'      => $departure_date,
        'check_out'     => $check_out,
        'nights'        => $nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => 1,
        'rooms_data'    => json_encode([]),
    ];

    $booking_id = $cartService->upsertBooking(
        $booking_record, (string) $experience_id, $departure_date, '', TypeCoerce::toString($parsed_guests['holder_name'])
    );

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'booking_type' => 'experience',
        'hotel_id' => (string) $experience_id, 'hotel_name' => $title, 'offer_id' => $offer_id,
        'check_in' => $departure_date, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $currency,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $currency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.experience_added_to_cart', ['[default]' => 'Experience booking added to cart.']))
    );
