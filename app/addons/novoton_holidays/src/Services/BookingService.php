<?php
declare(strict_types=1);
/**
 * Novoton Booking Service
 * 
 * Handles booking creation, cart operations, and order processing.
 * Extracted from novoton_booking.php for better maintainability.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\GuestDataNormalizer;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\ValueObjects\RoomType;

class BookingService implements BookingServiceInterface
{
    /** @var \Tygh\Addons\NovotonHolidays\NovotonApi */
    private $api;

    /** @var GuestDataServiceInterface */
    private $guestService;

    /** @var RoomPriceServiceInterface */
    private $priceService;

    /** @var BookingRepositoryInterface */
    private $bookingRepo;

    /** @var bool */
    private $debug = false;

    /**
     * Constructor — all dependencies must be injected explicitly.
     *
     * Use Container::bookingService() to get a properly wired instance.
     */
    public function __construct(
        GuestDataServiceInterface $guestService,
        RoomPriceServiceInterface $priceService,
        BookingRepositoryInterface $bookingRepo,
        \Tygh\Addons\NovotonHolidays\NovotonApi $api
    ) {
        $this->api = $api;
        $this->guestService = $guestService;
        $this->priceService = $priceService;
        $this->bookingRepo = $bookingRepo;
        $this->debug = (Registry::get(\Tygh\Addons\NovotonHolidays\Constants::SETTING_DEBUG_LOGGING) ?? 'N') === 'Y';
    }
    
    /**
     * Create a new booking record
     * 
     * @param array $bookingData Booking data from form
     * @param int $product_id Associated product ID
     * @return int Booking ID
     */
    public function createBooking(array $bookingData, int $product_id): int
    {
        // Get current user/session
        $auth = Tygh::$app['session']['auth'] ?? [];
        $user_id = !empty($auth['user_id']) ? (int) $auth['user_id'] : 0;
        $session_id = session_id();
        
        // Parse and validate data
        $rooms_data = $this->parseRoomsData($bookingData);
        $guests_data = $this->guestService->parseGuestsData($bookingData);
        
        // Extract room info for database columns
        $room_info = $this->extractRoomInfo($rooms_data, $bookingData);
        
        // Calculate totals
        $totals = $this->calculateTotals($rooms_data);
        
        // Get hotel info
        $hotel_info = fn_novoton_holidays_get_hotel_data($bookingData['hotel_id']);
        
        // Build booking record
        $booking_record = [
            'order_id' => 0, // Updated when order is placed
            'user_id' => $user_id,
            'session_id' => $session_id,
            'product_id' => $product_id,
            'hotel_id' => $bookingData['hotel_id'],
            'hotel_name' => $hotel_info['hotel_name'] ?? '',
            'package_name' => $bookingData['package_name'] ?? '',
            'room_id' => $room_info['room_id'],
            'room_type' => $room_info['room_type'],
            'board_id' => $bookingData['board_id'] ?? 'BB',
            'board_name' => $bookingData['board_name'] ?? '',
            'check_in' => $bookingData['check_in'],
            'check_out' => $bookingData['check_out'],
            'nights' => $this->calculateNights($bookingData['check_in'], $bookingData['check_out']),
            'adults' => $totals['adults'],
            'children' => $totals['children'],
            'children_ages' => implode(',', $totals['ages']),
            'num_rooms' => count($rooms_data),
            'rooms_data' => json_encode($rooms_data),
            'guest_name' => $this->guestService->buildGuestList($guests_data),
            'holder_name' => $this->guestService->getHolderName($guests_data, $bookingData),
            'guest_email' => '',
            'guest_phone' => $bookingData['phone'] ?? '',
            'guests_data' => GuestDataNormalizer::toJson($guests_data),
            'base_price' => (float) ($bookingData['base_price'] ?? 0),
            'api_price' => (float) ($bookingData['api_price'] ?? 0),
            'total_price' => (float) ($bookingData['total_price'] ?? 0),
            'currency' => ConfigProvider::getApiCurrency(),
            'status' => Constants::STATUS_PENDING,
        ];
        
        // Check for duplicate booking
        $existing_id = $this->findDuplicateBooking($booking_record);
        
        if ($existing_id) {
            // Update existing
            $this->updateBooking($existing_id, $booking_record);
            return $existing_id;
        }
        
        // Create new
        $booking_id = db_query("INSERT INTO ?:novoton_bookings ?e", $booking_record);
        
        $this->log('Booking created', [
            'booking_id' => $booking_id,
            'hotel_id' => $bookingData['hotel_id'],
            'rooms' => count($rooms_data)
        ]);
        
        return $booking_id;
    }
    
    /**
     * Update existing booking
     * 
     * @param int $booking_id Booking ID
     * @param array $data Data to update
     * @return bool Success
     */
    public function updateBooking(int $booking_id, array $data): bool
    {
        // Remove fields that shouldn't be updated
        unset($data['booking_id'], $data['created_at']);
        
        $result = db_query(
            "UPDATE ?:novoton_bookings SET ?u WHERE booking_id = ?i",
            $data,
            $booking_id
        );
        
        $this->log('Booking updated', ['booking_id' => $booking_id]);
        
        return $result > 0;
    }
    
    /**
     * Get booking by ID
     * 
     * @param int $booking_id Booking ID
     * @return array|null Booking data
     */
    public function getBooking(int $booking_id): ?array
    {
        return $this->bookingRepo->findByIdHydrated($booking_id);
    }
    
    /**
     * Get bookings for order
     * 
     * @param int $order_id Order ID
     * @return array Bookings
     */
    public function getBookingsForOrder(int $order_id): array
    {
        return db_get_array(
            "SELECT * FROM ?:novoton_bookings WHERE order_id = ?i ORDER BY booking_id",
            $order_id
        );
    }
    
    /**
     * Link booking to order
     * 
     * @param int $booking_id Booking ID
     * @param int $order_id Order ID
     * @return bool Success
     */
    public function linkToOrder(int $booking_id, int $order_id): bool
    {
        // Get order info for user/email
        $order_info = fn_get_order_info($order_id);
        
        $update = [
            'order_id' => $order_id,
            'user_id' => (int) ($order_info['user_id'] ?? 0),
            'guest_email' => $order_info['email'] ?? '',
        ];
        
        return $this->updateBooking($booking_id, $update);
    }
    
    /**
     * Add booking to cart
     * 
     * @param int $booking_id Booking ID
     * @param int $product_id Product ID
     * @param array $bookingData Additional booking data
     * @return bool Success
     */
    public function addToCart(int $booking_id, int $product_id, array $bookingData): bool
    {
        $booking = $this->getBooking($booking_id);
        if (!$booking) {
            return false;
        }
        
        // Build cart product
        $product = [
            'product_id' => $product_id,
            'amount' => 1,
            'extra' => $this->buildCartExtra($booking, $bookingData),
        ];
        
        // Add to cart
        $cart = &Tygh::$app['session']['cart'];
        $auth = &Tygh::$app['session']['auth'];
        
        fn_add_product_to_cart($product, $cart, $auth);
        fn_save_cart_content($cart, $auth['user_id'] ?? 0);
        
        // Calculate cart
        fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
        
        $this->log('Added to cart', [
            'booking_id' => $booking_id,
            'product_id' => $product_id
        ]);
        
        return true;
    }
    
    /**
     * Build cart extra data
     * 
     * @param array $booking Booking record
     * @param array $bookingData Additional data
     * @return array Cart extra
     */
    private function buildCartExtra(array $booking, array $bookingData): array
    {
        return [
            'novoton_booking' => true,
            'novoton_booking_id' => $booking['booking_id'],
            'hotel_id' => $booking['hotel_id'],
            'hotel_name' => $booking['hotel_name'],
            'hotel_city' => $bookingData['hotel_city'] ?? '',
            'hotel_country' => $bookingData['hotel_country'] ?? Constants::DEFAULT_COUNTRY,
            'package_name' => $booking['package_name'],
            'room_id' => $booking['room_id'],
            'room_name' => str_replace(['%2b', '%2B'], '+', $booking['room_id']),
            'room_type_display' => $booking['room_type'],
            'board_id' => $booking['board_id'],
            'board_name' => $this->getBoardName($booking['board_id']),
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'nights' => $booking['nights'],
            'adults' => $booking['adults'],
            'children' => $booking['children'],
            'children_ages' => $booking['children_ages'],
            'num_rooms' => $booking['num_rooms'],
            'rooms_data' => $booking['rooms_data'],
            'holder_name' => $booking['holder_name'],
            'guest_name' => $booking['guest_name'],
            'guests_data' => $booking['guests_data'],
            'total_price' => $booking['total_price'],
            'terms_of_payment' => $bookingData['terms_of_payment'] ?? '',
            'terms_of_cancellation' => $bookingData['terms_of_cancellation'] ?? '',
            'terms_of_payment_raw' => $bookingData['terms_of_payment_raw'] ?? '',
            'terms_of_cancellation_raw' => $bookingData['terms_of_cancellation_raw'] ?? '',
        ];
    }
    
    /**
     * Parse rooms data from booking form
     * 
     * @param array $bookingData Form data
     * @return array Parsed rooms data
     */
    public function parseRoomsData(array $bookingData): array
    {
        $rooms_data = [];
        
        if (!empty($bookingData['rooms_data'])) {
            $rooms_data = is_string($bookingData['rooms_data'])
                ? json_decode($bookingData['rooms_data'], true)
                : $bookingData['rooms_data'];
        }
        
        // Create default room if empty
        if (empty($rooms_data) || !is_array($rooms_data)) {
            $rooms_data = [[
                'room_id' => $bookingData['room_id'] ?? '',
                'room_name' => fn_novoton_holidays_format_room_type($bookingData['room_id'] ?? ''),
                'board_id' => $bookingData['board_id'] ?? 'BB',
                'adults' => (int) ($bookingData['adults'] ?? 2),
                'children' => (int) ($bookingData['children'] ?? 0),
                'childrenAges' => $this->parseChildrenAges($bookingData),
                'price' => (float) ($bookingData['total_price'] ?? 0),
            ]];
        }
        
        return $rooms_data;
    }
    
    /**
     * Extract room info for database columns
     * 
     * @param array $rooms_data Rooms data
     * @param array $bookingData Booking data fallback
     * @return array Room info [room_id, room_type]
     */
    private function extractRoomInfo(array $rooms_data, array $bookingData): array
    {
        $room_ids = [];
        $room_types = [];
        
        foreach ($rooms_data as $room) {
            if (!empty($room['room_id'])) {
                $room_ids[] = $room['room_id'];
            }
            if (!empty($room['room_name'])) {
                $room_types[] = $room['room_name'];
            } elseif (!empty($room['room_type_display'])) {
                $room_types[] = $room['room_type_display'];
            } elseif (!empty($room['room_id'])) {
                $room_types[] = fn_novoton_holidays_format_room_type($room['room_id']);
            }
        }
        
        // Fallback to bookingData
        if (empty($room_ids) && !empty($bookingData['room_id'])) {
            $room_ids[] = $bookingData['room_id'];
            $room_types[] = fn_novoton_holidays_format_room_type($bookingData['room_id']);
        }
        
        return [
            'room_id' => implode(', ', $room_ids),
            'room_type' => implode(', ', $room_types),
        ];
    }
    
    /**
     * Calculate totals from rooms data
     * 
     * @param array $rooms_data Rooms data
     * @return array Totals [adults, children, ages, price]
     */
    private function calculateTotals(array $rooms_data): array
    {
        $totals = [
            'adults' => 0,
            'children' => 0,
            'ages' => [],
            'price' => 0,
        ];
        
        foreach ($rooms_data as $room) {
            $totals['adults'] += (int) ($room['adults'] ?? 0);
            $totals['children'] += (int) ($room['children'] ?? 0);
            $totals['price'] += (float) ($room['price'] ?? 0);
            
            if (!empty($room['childrenAges'])) {
                foreach ($room['childrenAges'] as $age) {
                    if ($age !== null && $age !== 'age_needed') {
                        $totals['ages'][] = (int) $age;
                    }
                }
            }
        }
        
        return $totals;
    }
    
    /**
     * Parse children ages from booking data
     * 
     * @param array $bookingData Booking data
     * @return array Ages
     */
    private function parseChildrenAges(array $bookingData): array
    {
        $ages = [];
        
        if (!empty($bookingData['children_ages'])) {
            if (is_string($bookingData['children_ages'])) {
                $ages = array_map('intval', array_filter(
                    explode(',', $bookingData['children_ages']),
                    function($v) { return $v !== ''; }
                ));
            } else {
                $ages = (array)$bookingData['children_ages'];
            }
        }
        
        return $ages;
    }
    
    /**
     * Calculate nights between dates
     * 
     * @param string $check_in Check-in date
     * @param string $check_out Check-out date
     * @return int Number of nights
     */
    public function calculateNights(string $check_in, string $check_out): int
    {
        $in = strtotime($check_in);
        $out = strtotime($check_out);
        
        if (!$in || !$out || $out <= $in) {
            return 0;
        }
        
        return (int)(($out - $in) / 86400);
    }
    
    /**
     * Find duplicate pending booking
     * 
     * @param array $booking_record Booking data
     * @return int|null Existing booking ID or null
     */
    private function findDuplicateBooking(array $booking_record): ?int
    {
        $existing = db_get_field(
            "SELECT booking_id FROM ?:novoton_bookings 
             WHERE order_id = 0 
             AND hotel_id = ?s 
             AND check_in = ?s 
             AND check_out = ?s 
             AND holder_name = ?s
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             LIMIT 1",
            $booking_record['hotel_id'],
            $booking_record['check_in'],
            $booking_record['check_out'],
            $booking_record['holder_name']
        );
        
        return $existing ? (int) $existing : null;
    }
    
    /**
     * Get board name from ID
     *
     * Delegates to BoardType value object (single source of truth).
     *
     * @param string $board_id Board ID (e.g. "AI", "FB+", "ALL INCL")
     * @return string Board display name
     */
    private function getBoardName(string $board_id): string
    {
        return \Tygh\Addons\NovotonHolidays\ValueObjects\BoardType::toDisplayName($board_id);
    }
    
    /**
     * Verify price via room_price API and extract terms.
     *
     * Calls the Novoton room_price API with the given parameters,
     * validates that a price is returned, applies commission, and
     * extracts terms of payment/cancellation from the response.
     *
     * @param array $params {hotel_id, room_id, board_id, check_in, check_out, adults, children_ages: int[]}
     * @return array{success: bool, total_price: float, base_price: float, terms_of_payment: string, terms_of_cancellation: string, remark: string, important: string, error: string}
     */
    public function verifyPrice(array $params): array
    {
        $priceParams = [
            'hotel_id' => $params['hotel_id'],
            'room_id' => $params['room_id'] ?? '',
            'board_id' => $params['board_id'] ?? '',
            'star_rating' => '',
            'check_in' => $params['check_in'],
            'check_out' => $params['check_out'],
            'adults' => (int) ($params['adults'] ?? 2),
            'children' => $params['children_ages'] ?? [],
        ];

        $priceData = $this->api->getRoomPrice($priceParams);

        if (!$priceData || !isset($priceData->Price)) {
            $this->log('Price verification failed', [
                'hotel_id' => $params['hotel_id'],
                'room_id' => $params['room_id'] ?? '',
                'children_ages' => $params['children_ages'] ?? [],
            ]);
            return [
                'success' => false,
                'total_price' => 0,
                'base_price' => 0,
                'terms_of_payment' => '',
                'terms_of_cancellation' => '',
                'remark' => '',
                'important' => '',
                'error' => 'price_verification_failed',
            ];
        }

        $rawPrice = (float) (string) $priceData->Price;
        $totalPrice = $this->api->applyCommission($rawPrice);

        // Extract terms
        $termsOfPayment = '';
        $termsOfCancellation = '';
        if ($priceData instanceof \SimpleXMLElement) {
            $tp = $priceData->xpath('//TermsOfPayment');
            $tc = $priceData->xpath('//TermsOfCancellation');
            if (!empty($tp[0])) {
                $termsOfPayment = $tp[0]->asXML();
            }
            if (!empty($tc[0])) {
                $termsOfCancellation = $tc[0]->asXML();
            }
        }

        return [
            'success' => true,
            'total_price' => $totalPrice,
            'base_price' => $rawPrice,
            'terms_of_payment' => $termsOfPayment,
            'terms_of_cancellation' => $termsOfCancellation,
            'remark' => isset($priceData->remark) ? (string)$priceData->remark : '',
            'important' => isset($priceData->Important) ? (string)$priceData->Important : '',
            'error' => '',
        ];
    }

    /**
     * Assemble the full cart product array for a Novoton booking.
     *
     * Combines booking record data, hotel info, and terms into the
     * structure expected by CS-Cart's cart system.
     *
     * @param int $productId CS-Cart product ID
     * @param int $bookingId Novoton booking ID
     * @param array $bookingData Raw form data
     * @param array $hotelInfo Hotel data from repository
     * @param array $guestsData Parsed guest data
     * @param array $priceResult Result from verifyPrice()
     * @param array $roomsData Parsed rooms data
     * @return array Cart product entry with 'extra' containing all booking metadata
     */
    public function assembleCartProduct(
        int $productId,
        int $bookingId,
        array $bookingData,
        array $hotelInfo,
        array $guestsData,
        array $priceResult,
        array $roomsData
    ): array {
        $boardId = $bookingData['board_id'] ?? 'BB';
        $nights = $this->calculateNights($bookingData['check_in'], $bookingData['check_out']);
        $guestNames = [];
        $holderName = '';
        foreach ($guestsData as $g) {
            if (!empty($g['name'])) {
                $guestNames[] = $g['name'];
            }
        }
        $holderName = $guestNames[0] ?? '';
        $guestList = implode(', ', $guestNames);

        // Collect children ages from guests
        $childAges = [];
        foreach ($guestsData as $g) {
            if (isset($g['type']) && $g['type'] === 'child' && isset($g['age'])) {
                $childAges[] = (int) $g['age'];
            }
        }

        $totalPrice = $priceResult['total_price'];

        return [
            'product_id' => $productId,
            'amount' => 1,
            'price' => $totalPrice,
            'base_price' => $totalPrice,
            'original_price' => $totalPrice,
            'stored_price' => 'Y',
            'extra' => [
                'novoton_booking' => true,
                'novoton_booking_id' => $bookingId,
                'hotel_id' => $bookingData['hotel_id'],
                'hotel_name' => $hotelInfo['hotel_name'] ?? '',
                'hotel_city' => $hotelInfo['city'] ?? '',
                'hotel_region' => $hotelInfo['region'] ?? '',
                'hotel_country' => $hotelInfo['country'] ?? Constants::DEFAULT_COUNTRY,
                'package_name' => $bookingData['package_name'] ?? '',
                'room_id' => $bookingData['room_id'],
                'room_name' => str_replace(['%2b', '%2B'], '+', $bookingData['room_id']),
                'room_type_display' => fn_novoton_holidays_format_room_type($bookingData['room_id']),
                'board_id' => $boardId,
                'board_name' => fn_novoton_holidays_format_board_name($boardId),
                'check_in' => $bookingData['check_in'],
                'check_out' => $bookingData['check_out'],
                'nights' => $nights,
                'adults' => (int) ($bookingData['adults'] ?? 2),
                'children' => (int) ($bookingData['children'] ?? 0),
                'children_ages' => !empty($childAges) ? implode(',', $childAges) : ($bookingData['children_ages'] ?? ''),
                'num_rooms' => (int) ($bookingData['num_rooms'] ?? 1),
                'rooms_data' => $roomsData,
                'guest_names' => $guestList,
                'holder_name' => $holderName,
                'guests_data' => json_encode($guestsData),
                'contact_email' => $bookingData['contact']['email'] ?? '',
                'contact_phone' => $bookingData['contact']['phone'] ?? '',
                'terms_of_payment' => fn_novoton_holidays_format_payment_terms($priceResult['terms_of_payment']),
                'terms_of_cancellation' => fn_novoton_holidays_format_cancellation_terms($priceResult['terms_of_cancellation'], $bookingData['check_in']),
                'terms_of_payment_raw' => $priceResult['terms_of_payment'],
                'terms_of_cancellation_raw' => $priceResult['terms_of_cancellation'],
                'remark' => $priceResult['remark'],
                'important' => $priceResult['important'],
                'total_price' => $totalPrice,
                'currency' => ConfigProvider::getApiCurrency(),
            ],
        ];
    }

    /**
     * Enrich rooms_data with display fields needed by Smarty templates.
     *
     * Adds children_ages_str and room_type_display to each room entry,
     * and syncs children ages from guest form data back to rooms.
     *
     * @param array $roomsData Rooms data array
     * @param array $guestsData Parsed guest data
     * @return array Enriched rooms data
     */
    public function enrichRoomsData(array $roomsData, array $guestsData): array
    {
        foreach ($roomsData as $roomIdx => &$room) {
            $roomNum = $roomIdx + 1;

            // Collect children ages from guests for this room
            $childAgesForRoom = [];
            foreach ($guestsData as $guest) {
                if (isset($guest['room']) && $guest['room'] == $roomNum && ($guest['type'] ?? '') === 'child') {
                    $childAgesForRoom[] = (int) ($guest['age'] ?? 0);
                }
            }

            if (!empty($childAgesForRoom)) {
                $room['childrenAges'] = $childAgesForRoom;
            }

            // Build display string for children ages
            if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
                $validAges = array_filter($room['childrenAges'], function ($age) {
                    return $age !== null && $age !== '';
                });
                $room['children_ages_str'] = !empty($validAges)
                    ? implode(', ', $validAges) . ' ' . __('novoton_holidays.years_old')
                    : '';
            } else {
                $room['children_ages_str'] = '';
            }

            // Ensure room_type_display is set
            if (empty($room['room_type_display']) && !empty($room['room_id'])) {
                $room['room_type_display'] = fn_novoton_holidays_format_room_type($room['room_id']);
                $room['room_name'] = fn_novoton_holidays_format_room_type($room['room_id']);
            }

            // Normalize room_id and room_name: restore + lost by URL decoding
            if (!empty($room['room_id'])) {
                $room['room_id'] = RoomType::normalizeRoomCode($room['room_id']);
            }
            if (!empty($room['room_name'])) {
                $room['room_name'] = RoomType::normalizeRoomCode($room['room_name']);
            }
        }
        unset($room);

        return $roomsData;
    }

    /**
     * Resolve the CS-Cart product ID for a given hotel ID.
     *
     * @param string $hotelId Novoton hotel ID
     * @param int $fallbackProductId Product ID from form (fallback)
     * @return int Product ID or 0 if not found
     */
    public function resolveProductId(string $hotelId, int $fallbackProductId = 0): int
    {
        $prefix = ConfigProvider::getFirstProductCodePrefix();
        $productCode = $prefix . $hotelId;

        $productId = (int)db_get_field(
            "SELECT product_id FROM ?:products WHERE product_code = ?s",
            $productCode
        );

        return $productId ?: $fallbackProductId;
    }

    /**
     * Log debug message
     *
     * @param string $message Message
     * @param array $context Context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonBooking: ' . $message],
                $context
            ));
        }
    }
}
