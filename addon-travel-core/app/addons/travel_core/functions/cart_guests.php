<?php

declare(strict_types=1);

/**
 * Travel Core — shared cart guest-extras refresh.
 *
 * The one implementation of "the customer edited tourist details on a carted
 * booking — reflect the new guest fields on the cart line" used by BOTH
 * providers' update_booking controllers. Lives at the functions/ boundary
 * because it works through the $_SESSION cart (superglobals and CS-Cart's
 * procedural cart API stay out of src/ services).
 *
 * @package TravelCore
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Write guest extras onto the cart line belonging to a booking, then persist.
 *
 * Target resolution is integrity-checked: the $cart_id line is used only when
 * its extras reference THIS booking (a stale link must never edit another
 * line); otherwise the cart is scanned for the line whose $booking_id_key
 * extra matches (cart_id hashes change when the cart is rebuilt, e.g. after
 * session expiry — novoton's historical fallback, now shared).
 *
 * @param string $cart_id        Cart line id hint from the edit form ('' to force the scan)
 * @param int    $booking_id     Provider booking id the line must reference
 * @param string $booking_id_key Extra key carrying that id (travel_booking_id / novoton_booking_id)
 * @param array<string, string> $extra_fields Guest extras to write (guest_names, holder_name, guests_data, ...)
 * @param bool   $recalculate    Also run fn_calculate_cart_content + re-save (novoton's
 *                               save -> recalc -> save sequence; extras are saved FIRST because
 *                               the recalc reloads product data from the stored cart)
 * @return bool Whether a cart line was found and updated
 */
function fn_travel_core_update_cart_guest_extras(string $cart_id, int $booking_id, string $booking_id_key, array $extra_fields, bool $recalculate = false): bool
{
    if ($booking_id <= 0) {
        return false;
    }

    // By-ref bind into $_SESSION — the authoritative session store. Never
    // assign to Tygh::$app['session']: it is a frozen Pimple service and
    // offsetSet throws FrozenServiceException.
    $cart = &$_SESSION['cart'];
    if (!is_array($cart)) {
        return false;
    }
    $cart['products'] = is_array($cart['products'] ?? null) ? $cart['products'] : [];

    $line_matches_booking = static function ($item) use ($booking_id, $booking_id_key): bool {
        if (!is_array($item)) {
            return false;
        }
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : [];

        return TypeCoerce::toInt($extra[$booking_id_key] ?? 0) === $booking_id;
    };

    // Integrity-checked exact hit first, then the rebuilt-cart fallback scan.
    $target_cart_id = null;
    if ($cart_id !== '' && isset($cart['products'][$cart_id]) && $line_matches_booking($cart['products'][$cart_id])) {
        $target_cart_id = $cart_id;
    } else {
        foreach ($cart['products'] as $cid => $item) {
            if ($line_matches_booking($item)) {
                $target_cart_id = $cid;
                break;
            }
        }
    }

    if ($target_cart_id === null) {
        return false;
    }

    $target_product = &$cart['products'][$target_cart_id];
    if (!is_array($target_product)) {
        $target_product = [];
    }
    $target_product['extra'] = is_array($target_product['extra'] ?? null) ? $target_product['extra'] : [];
    $target_extra = &$target_product['extra'];
    foreach ($extra_fields as $key => $value) {
        $target_extra[$key] = $value;
    }
    unset($target_product, $target_extra);

    $auth = &$_SESSION['auth'];
    if (!is_array($auth)) {
        $auth = [];
    }
    $authUserId = TypeCoerce::toInt($auth['user_id'] ?? 0);

    // Persist extras BEFORE recalculating — fn_calculate_cart_content()
    // reloads product data from the stored cart, which would overwrite the
    // extras just set if they haven't been saved first.
    fn_save_cart_content($cart, $authUserId);

    if ($recalculate) {
        fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
        fn_save_cart_content($cart, $authUserId);
    }

    return true;
}
