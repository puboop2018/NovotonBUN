<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Pre-Order Price Verifier
 *
 * Real-time price verification at the final step of checkout.
 * Runs just before the order is placed (pre_place_order hook) to catch
 * price discrepancies between what the customer is paying and the
 * current API price.
 *
 * Scenarios:
 *   1. Form price < API price → BLOCK order, notify admin
 *   2. Form price > API price by > threshold% → ALLOW order, notify admin
 *   3. Prices match (within threshold) → ALLOW order silently
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class PreOrderPriceVerifier
{
    /**
     * Verify all Novoton booking products in the cart against live API prices.
     *
     * @param array $cart CS-Cart cart array
     * @return array{allow: bool, blocked_products: array, notifications: array}
     */
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'blocked_products' => [],
            'notifications' => [],
        ];

        if (!ConfigProvider::isPreorderPriceCheckEnabled()) {
            return $result;
        }

        if (empty($cart['products'])) {
            return $result;
        }

        $api = fn_novoton_holidays_get_api();
        if (!$api) {
            // If API is unavailable, don't block the order — log and continue
            fn_log_event('general', 'runtime', [
                'message' => 'PreOrderPriceVerifier: API unavailable, skipping price check',
            ]);
            return $result;
        }

        $threshold = ConfigProvider::getPriceHigherThreshold();
        $debug = ConfigProvider::isDebugLogging();

        foreach ($cart['products'] as $cartId => $product) {
            if (empty($product['extra']['novoton_booking'])) {
                continue;
            }

            $extra = $product['extra'];
            $formPrice = (float) ($extra['total_price'] ?? $product['price'] ?? 0);

            if ($formPrice <= 0) {
                continue;
            }

            // Build API params from cart product data
            $childrenAges = $this->parseChildrenAges($extra);

            $priceParams = [
                'hotel_id'    => $extra['hotel_id'] ?? '',
                'room_id'     => $extra['room_id'] ?? '',
                'board_id'    => $extra['board_id'] ?? '',
                'star_rating' => '',
                'check_in'    => $extra['check_in'] ?? '',
                'check_out'   => $extra['check_out'] ?? '',
                'adults'      => (int) ($extra['adults'] ?? 2),
                'children'    => $childrenAges,
            ];

            // Skip if missing required fields
            if (empty($priceParams['hotel_id']) || empty($priceParams['check_in'])) {
                continue;
            }

            $priceData = $api->getRoomPrice($priceParams);

            if (!$priceData || !isset($priceData->Price)) {
                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message'  => 'PreOrderPriceVerifier: API returned no price, allowing order',
                        'hotel_id' => $priceParams['hotel_id'],
                        'room_id'  => $priceParams['room_id'],
                    ]);
                }
                // API returned no price — don't block the order
                continue;
            }

            $rawApiPrice = (float) (string) $priceData->Price;
            $apiPriceWithCommission = $api->applyCommission($rawApiPrice);

            $checkResult = $this->comparePrice(
                $formPrice,
                $apiPriceWithCommission,
                $rawApiPrice,
                $threshold,
                $extra,
                $cartId
            );

            if (!$checkResult['allow']) {
                $result['allow'] = false;
                $result['blocked_products'][$cartId] = $checkResult;
            }

            if (!empty($checkResult['notification'])) {
                $result['notifications'][] = $checkResult['notification'];
            }
        }

        return $result;
    }

    /**
     * Compare form price to API price and determine action.
     *
     * @return array{allow: bool, notification: array|null, type: string}
     */
    private function comparePrice(
        float  $formPrice,
        float  $apiPrice,
        float  $rawApiPrice,
        float  $threshold,
        array  $extra,
        string $cartId
    ): array {
        $hotelId   = $extra['hotel_id'] ?? '';
        $hotelName = $extra['hotel_name'] ?? '';
        $roomId    = $extra['room_id'] ?? '';
        $boardId   = $extra['board_id'] ?? '';
        $checkIn   = $extra['check_in'] ?? '';
        $checkOut  = $extra['check_out'] ?? '';
        $adults    = (int) ($extra['adults'] ?? 2);
        $children  = (int) ($extra['children'] ?? 0);
        $childrenAges = $extra['children_ages'] ?? '';

        $notificationData = [
            'hotel_id'      => $hotelId,
            'hotel_name'    => $hotelName,
            'room_id'       => $roomId,
            'board_id'      => $boardId,
            'check_in'      => $checkIn,
            'check_out'     => $checkOut,
            'adults'        => $adults,
            'children'      => $children,
            'children_ages' => $childrenAges,
            'form_price'    => $formPrice,
            'api_price'     => $apiPrice,
            'api_price_raw' => $rawApiPrice,
            'cart_id'       => $cartId,
        ];

        // Case 1: Form price is LOWER than API price → BLOCK
        if ($formPrice < $apiPrice) {
            $difference = round($apiPrice - $formPrice, 2);
            $percentLower = $apiPrice > 0 ? round(($difference / $apiPrice) * 100, 1) : 0;

            fn_log_event('general', 'runtime', [
                'message'       => 'PreOrderPriceVerifier: BLOCKED — form price below API price',
                'hotel_id'      => $hotelId,
                'room_id'       => $roomId,
                'form_price'    => $formPrice,
                'api_price'     => $apiPrice,
                'difference'    => $difference,
                'percent_lower' => $percentLower,
            ]);

            $notificationData['difference'] = $difference;
            $notificationData['percent'] = $percentLower;
            $notificationData['type'] = 'price_lower';

            // Admin notification
            fn_set_notification('W', 'Price Discrepancy',
                'Order blocked: form price (' . number_format($formPrice, 2) . ' EUR) is lower than API price ('
                . number_format($apiPrice, 2) . ' EUR) for hotel ' . $hotelName . ' [' . $hotelId . ']. '
                . 'Difference: ' . number_format($difference, 2) . ' EUR (' . $percentLower . '% lower).'
            );

            return [
                'allow'        => false,
                'type'         => 'price_lower',
                'notification' => $notificationData,
            ];
        }

        // Case 2: Form price is HIGHER than API price by more than threshold%
        if ($apiPrice > 0) {
            $difference = round($formPrice - $apiPrice, 2);
            $percentHigher = round(($difference / $apiPrice) * 100, 1);

            if ($percentHigher > $threshold) {
                fn_log_event('general', 'runtime', [
                    'message'        => 'PreOrderPriceVerifier: ALERT — form price significantly above API price',
                    'hotel_id'       => $hotelId,
                    'room_id'        => $roomId,
                    'form_price'     => $formPrice,
                    'api_price'      => $apiPrice,
                    'difference'     => $difference,
                    'percent_higher' => $percentHigher,
                    'threshold'      => $threshold,
                ]);

                $notificationData['difference'] = $difference;
                $notificationData['percent'] = $percentHigher;
                $notificationData['type'] = 'price_higher';

                // Admin notification (order is still allowed)
                fn_set_notification('W', 'Price Discrepancy',
                    'Alert: form price (' . number_format($formPrice, 2) . ' EUR) is ' . $percentHigher
                    . '% higher than API price (' . number_format($apiPrice, 2) . ' EUR) for hotel '
                    . $hotelName . ' [' . $hotelId . ']. Order allowed but please investigate.'
                );

                return [
                    'allow'        => true,
                    'type'         => 'price_higher',
                    'notification' => $notificationData,
                ];
            }
        }

        // Case 3: Prices are within acceptable range
        return [
            'allow'        => true,
            'type'         => 'ok',
            'notification' => null,
        ];
    }

    /**
     * Parse children ages from cart extra data.
     *
     * @return int[]
     */
    private function parseChildrenAges(array $extra): array
    {
        $ages = [];
        $raw = $extra['children_ages'] ?? '';

        if (is_array($raw)) {
            $ages = array_map('intval', $raw);
        } elseif (is_string($raw) && $raw !== '') {
            $ages = array_map('intval', array_filter(
                explode(',', $raw),
                function ($v) { return $v !== ''; }
            ));
        }

        return $ages;
    }
}
