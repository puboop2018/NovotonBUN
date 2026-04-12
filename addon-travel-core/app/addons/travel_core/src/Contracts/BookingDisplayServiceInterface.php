<?php
declare(strict_types=1);
/**
 * Booking Display Service Interface
 *
 * Contract for formatting travel booking data for display in cart,
 * checkout, and order pages. Provider-agnostic.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface BookingDisplayServiceInterface
{
    /**
     * Add booking display data to a cart product.
     *
     * Populates product_options_value[] with formatted booking details
     * for display in cart, checkout, and order pages.
     *
     * Supported $config keys:
     *   - 'lang_prefix'          (string)   Lang key prefix (default: 'travel_core')
     *   - 'json_decoder'         (callable) JSON decode function: fn(string, string): array
     *   - 'board_name_formatter' (callable) Board name formatter:  fn(string): string
     *   - 'room_name_formatter'  (callable) Room name formatter:   fn(array room_data): string
     *
     * @param array<string, mixed> $product Cart product (by reference)
     * @param array<string, mixed>|null $cart Cart data
     * @param array<string, mixed> $config Provider-specific configuration overrides
     */
    public static function addBookingDisplayData(array &$product, ?array $cart = null, array $config = []): void;
}
