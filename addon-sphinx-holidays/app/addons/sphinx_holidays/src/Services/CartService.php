<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\CartServiceInterface;
use Tygh\Addons\TravelCore\Helpers\SessionAccessor;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\Services\GuestDataService;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Unified cart service for Sphinx Holidays bookings.
 *
 * Encapsulates the shared logic across all 4 add-to-cart controllers
 * (hotel, circuit, experience, package): rate limiting, duplicate detection,
 * commission calculation, product resolution, guest validation, booking
 * upsert, and CS-Cart cart assembly.
 *
 * Controllers only provide type-specific logic (offer verification,
 * service customization, type-specific booking record fields).
 */
final class CartService implements CartServiceInterface
{
    private readonly SessionAccessor $session;

    public function __construct(?SessionAccessor $session = null)
    {
        $this->session = $session ?? new SessionAccessor();
    }
    /**
     * @return array<int, mixed>|null
     */
    #[\Override]
    public function checkRateLimit(string $errorRedirect = 'index.index'): ?array
    {
        $security = Container::getSecurityService();
        $auth = $this->session->auth();
        $rateLimitId = !empty($auth['user_id']) ? (string) $auth['user_id'] : (string) session_id();

        if (!$security->checkBookingRateLimit($rateLimitId)) {
            fn_set_notification(
                'E',
                __('error'),
                __(
                    'sphinx_holidays.rate_limit_exceeded',
                    ['[default]' => 'Too many booking requests. Please try again later.'],
                ),
            );
            return [CONTROLLER_STATUS_REDIRECT, $errorRedirect];
        }

        return null;
    }

    /**
     * Check for an existing pending booking with the same offer_id.
     * Returns a redirect array if a duplicate is found, null otherwise.
     * @return array<int, mixed>|null
     */
    #[\Override]
    public function checkDuplicate(string $offerId, string $redirectUrl = 'checkout.cart'): ?array
    {
        $repo = Container::getBookingRepository();
        $pending = $repo->findPendingDuplicateByOffer($offerId, TravelConstants::STATUS_PENDING);

        if ($pending !== null) {
            fn_set_notification(
                'W',
                __('warning'),
                __(
                    'sphinx_holidays.duplicate_booking',
                    ['[default]' => 'A booking for this offer is already pending.'],
                ),
            );
            return [CONTROLLER_STATUS_REDIRECT, $redirectUrl];
        }

        return null;
    }

    /**
     * Apply configured commission (if any) to a price.
     */
    #[\Override]
    public function applyCommission(float $price): float
    {
        $commission = ConfigProvider::getCommission();
        if ($commission <= 0 || $price <= 0) {
            return $price;
        }

        $calculator = new CommissionCalculator($commission, ConfigProvider::shouldRoundPrices() ? 'Y' : 'N');
        return $calculator->apply($price);
    }

    /**
     * Sanitize raw guest data and run server-side validation.
     * Returns the parsed result or false on validation failure (notification already set).
     *
     * @return array<string, mixed>|false
     * @param array<string, mixed> $rawGuests
     */
    #[\Override]
    public function parseGuests(array $rawGuests, string $dateRef): array|false
    {
        $security = Container::getSecurityService();
        $sanitized = $security->sanitizeGuestData($rawGuests);

        return GuestDataService::parseAndValidateGuests($sanitized, $dateRef, 'sphinx');
    }

    /**
     * Resolve a CS-Cart product_id from an entity ID (hotel_id, circuit_id, etc.).
     * Falls back to a direct product_code lookup if no ID was provided by the form.
     */
    #[\Override]
    public function resolveProductId(string $entityId, int $providedId = 0): int
    {
        if ($providedId > 0) {
            return $providedId;
        }

        if ($entityId === '') {
            return 0;
        }

        return (int) db_get_field(
            'SELECT product_id FROM ?:sphinx_hotels WHERE hotel_id = ?s',
            $entityId,
        );
    }

    /**
     * Create or update a booking record using the findRecentUnassigned pattern.
     * Returns the booking_id.
     * @param array<string, mixed> $record
     */
    #[\Override]
    public function upsertBooking(
        array $record,
        string $entityId,
        string $checkIn,
        string $checkOut,
        string $holderName,
    ): int {
        $repo = Container::getBookingRepository();
        $existingId = $repo->findRecentUnassigned($entityId, $checkIn, $checkOut, $holderName);

        if ($existingId !== null) {
            $repo->update($existingId, $record);
            return $existingId;
        }

        $record['created_at'] = date('Y-m-d H:i:s');
        return $repo->create($record);
    }

    /**
     * Assemble the product entry in the CS-Cart cart and persist it.
     * Returns the controller redirect tuple.
     * @param array<string, mixed> $productExtra
     * @return array<int, mixed>
     */
    #[\Override]
    public function addToCartAndRedirect(
        int $productId,
        float $totalPrice,
        string $apiCurrency,
        array $productExtra,
        string $successMessage,
        string $redirectUrl = 'checkout.cart',
    ): array {
        $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'EUR';
        $currencyService = new CurrencyService($apiCurrency);
        $cartPrice = $currencyService->convertFromApiCurrency($totalPrice, $primaryCurrency);

        $cartId = fn_generate_cart_id($productId, $productExtra);

        // Write the assembled row into the session cart via the allowlisted
        // functions/ boundary — keeps Tygh::\$app out of this service class.
        fn_sphinx_holidays_write_cart_row($cartId, [
            'product_id' => $productId,
            'amount' => 1,
            'price' => $cartPrice,
            'base_price' => $cartPrice,
            'original_price' => $cartPrice,
            'extra' => $productExtra,
            'stored_price' => 'Y',
        ]);

        fn_set_notification('N', __('notice'), $successMessage);

        return [CONTROLLER_STATUS_REDIRECT, $redirectUrl];
    }

    /**
     * Build the base booking record fields shared across all 4 booking types.
     * Callers add/override type-specific fields before calling upsertBooking().
     *
     * @return array<string, mixed>
     * @param array<string, mixed> $parsedGuests
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $apiResponse
     */
    #[\Override]
    public function buildBaseBookingRecord(
        int $productId,
        string $entityId,
        string $offerId,
        string $hotelName,
        array $parsedGuests,
        array $contact,
        float $basePrice,
        float $totalPrice,
        string $currency,
        array $apiResponse,
    ): array {
        $auth = $this->session->auth();

        return [
            'order_id' => 0,
            'user_id' => !empty($auth['user_id']) ? (int) $auth['user_id'] : 0,
            'session_id' => session_id(),
            'product_id' => $productId,
            'hotel_id' => $entityId,
            'hotel_name' => $hotelName,
            'offer_id' => $offerId,
            'guest_name' => $parsedGuests['guest_list'] ?? '',
            'holder_name' => $parsedGuests['holder_name'] ?? '',
            'guest_email' => $contact['email'] ?? '',
            'guest_phone' => $contact['phone'] ?? '',
            'guests_data' => json_encode($parsedGuests['guests_data'] ?? [], JSON_UNESCAPED_UNICODE),
            'base_price' => $basePrice,
            'total_price' => $totalPrice,
            'currency' => $currency,
            'status' => TravelConstants::STATUS_PENDING,
            'api_response' => json_encode($apiResponse, JSON_UNESCAPED_UNICODE),
        ];
    }
}
