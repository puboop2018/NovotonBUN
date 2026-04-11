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

use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Tygh;

class BookingService implements BookingServiceInterface
{
    private \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface $guestService;

    private RoomPriceServiceInterface $priceService;

    private BookingRepositoryInterface $bookingRepo;

    private HotelRepositoryInterface $hotelRepo;

    private GuestDataNormalizer $guestDataNormalizer;

    private CartAssemblyService $cartAssembly;

    private RoomsDataParser $roomsParser;

    private PriceVerificationService $priceVerifier;

    private bool $debug = false;

    /**
     * Constructor — all dependencies must be injected explicitly.
     *
     * The `$pricing` sub-client is only forwarded to the internally-constructed
     * PriceVerificationService. BookingService itself does not call the Novoton
     * API directly; narrowing the type from NovotonApi to PricingApiClientInterface
     * eliminates a dependency on the 46-method facade.
     *
     * Use Container::bookingService() to get a properly wired instance.
     */
    public function __construct(
        \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface $guestService,
        RoomPriceServiceInterface $priceService,
        BookingRepositoryInterface $bookingRepo,
        PricingApiClientInterface $pricing,
        ?HotelRepositoryInterface $hotelRepo = null,
        ?GuestDataNormalizer $guestDataNormalizer = null
    ) {
        $this->guestService = $guestService;
        $this->priceService = $priceService;
        $this->bookingRepo = $bookingRepo;
        $this->hotelRepo = $hotelRepo ?? new \Tygh\Addons\NovotonHolidays\Repository\HotelRepository();
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
        $this->cartAssembly = new CartAssemblyService();
        $this->roomsParser = new RoomsDataParser();
        $this->priceVerifier = new PriceVerificationService($pricing);
        $this->debug = ConfigProvider::isDebugLogging();
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
        $rooms_data = $this->roomsParser->parseRoomsData($bookingData);
        $guests_data = $this->guestService->parseGuestsData($bookingData);

        // Extract room info for database columns
        $room_info = $this->roomsParser->extractRoomInfo($rooms_data, $bookingData);

        // Calculate totals
        $totals = $this->roomsParser->calculateTotals($rooms_data);
        
        // Get hotel info
        $hotel_info = $this->hotelRepo->findById($bookingData['hotel_id']);
        
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
            'guests_data' => $this->guestDataNormalizer->toJson($guests_data),
            'base_price' => (float) ($bookingData['base_price'] ?? 0),
            'api_price' => (float) ($bookingData['api_price'] ?? 0),
            'total_price' => (float) ($bookingData['total_price'] ?? 0),
            'currency' => ConfigProvider::getApiCurrency(),
            'status' => TravelConstants::STATUS_PENDING,
        ];

        // Check for duplicate booking (delegate to repository)
        $existing = $this->bookingRepo->findExisting(
            $booking_record['hotel_id'],
            $booking_record['check_in'],
            $booking_record['check_out'],
            $booking_record['holder_name'],
            1 // hours
        );
        $existing_id = $existing ? (int) $existing['booking_id'] : null;

        if ($existing_id) {
            // Update existing (routes through repository → syncs to travel_bookings)
            $this->updateBooking($existing_id, $booking_record);
            return $existing_id;
        }

        // Create new (routes through repository → syncs to travel_bookings)
        $booking_id = $this->bookingRepo->create($booking_record);

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

        // Route through repository → syncs to travel_bookings
        $result = $this->bookingRepo->update($booking_id, $data);

        $this->log('Booking updated', ['booking_id' => $booking_id]);

        return $result;
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
        return $this->bookingRepo->findByOrderId($order_id);
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
            'extra' => $this->cartAssembly->buildCartExtra($booking, $bookingData),
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
     * Parse rooms data from booking form
     * 
     * @param array $bookingData Form data
     * @return array Parsed rooms data
     */
    public function parseRoomsData(array $bookingData): array
    {
        return $this->roomsParser->parseRoomsData($bookingData);
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
        return CartAssemblyService::calculateNights($check_in, $check_out);
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
        return $this->priceVerifier->verifyPrice($params);
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
        return $this->cartAssembly->assembleCartProduct(
            $productId, $bookingId, $bookingData, $hotelInfo, $guestsData, $priceResult, $roomsData
        );
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
        return $this->cartAssembly->enrichRoomsData($roomsData, $guestsData);
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
