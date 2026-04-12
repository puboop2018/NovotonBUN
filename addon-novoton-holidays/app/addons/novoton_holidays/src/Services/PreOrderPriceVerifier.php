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
 * "Silent Sync" optimisation: the add_to_cart controller caches the
 * verified API price + timestamp in the user session. If the cached
 * entry is younger than the configurable TTL (default 180 s), we
 * trust it and skip the API round-trip, making checkout feel instant.
 *
 * Scenarios:
 *   1. Form price < API price → CORRECT cart price to API price, notify admin
 *   2. Form price > API price by > threshold% → ALLOW order, notify admin
 *   3. Prices match (within threshold) → ALLOW order silently
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Tygh;

class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    /**
     * Verify all Novoton booking products in the cart against live API prices.
     *
     * @param array<string, mixed> $cart CS-Cart cart array
     * @return array{allow: bool, corrections: array, notifications: array}
     *   - allow: always true (we correct, never block)
     *   - corrections: cart_id => ['api_price' => float, 'api_price_raw' => float]
     *   - notifications: list of discrepancy data for admin emails
     */
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'corrections' => [],
            'notifications' => [],
        ];

        if (!ConfigProvider::isPreorderPriceCheckEnabled()) {
            return $result;
        }

        if (empty($cart['products'])) {
            return $result;
        }

        $threshold = ConfigProvider::getPriceHigherThreshold();
        $cacheTtl  = ConfigProvider::getPreorderCacheTtl();
        $debug     = ConfigProvider::isDebugLogging();

        // Lazy-load the API only when actually needed (cache miss).
        // $pricing is bound from $api->pricing() after the null check so
        // every API call inside the loop goes through the narrow
        // PricingApiClientInterface rather than the deprecated facade.
        $pricing = null;

        foreach ($cart['products'] as $cartId => $product) {
            if (empty($product['extra']['novoton_booking'])) {
                continue;
            }

            $extra     = $product['extra'];
            $formPrice = (float) ($extra['total_price'] ?? $product['price'] ?? 0);

            if ($formPrice <= 0) {
                continue;
            }

            $childrenAges = $this->parseChildrenAges($extra);

            // ── Silent Sync: try session cache first ──
            $cached = $this->getCachedPrice($extra, $childrenAges, $cacheTtl);

            if ($cached !== null) {
                $apiPriceWithCommission = (float) $cached['api_price'];
                $rawApiPrice            = (float) $cached['api_price_raw'];

                if ($debug) {
                    fn_log_event('general', 'runtime', [
                        'message'  => 'PreOrderPriceVerifier: using session-cached price (Silent Sync)',
                        'hotel_id' => $extra['hotel_id'] ?? '',
                        'age_sec'  => time() - (int) $cached['timestamp'],
                        'api_price' => $apiPriceWithCommission,
                    ]);
                }
            } else {
                // Cache miss / stale — call the API
                if ($pricing === null) {
                    $api = fn_novoton_holidays_get_api();
                    if (!$api) {
                        fn_log_event('general', 'runtime', [
                            'message' => 'PreOrderPriceVerifier: API unavailable, skipping price check',
                        ]);
                        return $result;
                    }
                    $pricing = $api->pricing();
                }

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

                if (empty($priceParams['hotel_id']) || empty($priceParams['check_in'])) {
                    continue;
                }

                $priceData = $pricing->getRoomPrice($priceParams);

                if (!$priceData || !isset($priceData->Price)) {
                    if ($debug) {
                        fn_log_event('general', 'runtime', [
                            'message'  => 'PreOrderPriceVerifier: API returned no price, allowing order',
                            'hotel_id' => $priceParams['hotel_id'],
                            'room_id'  => $priceParams['room_id'],
                        ]);
                    }
                    continue;
                }

                $rawApiPrice            = (float) (string) $priceData->Price;
                $apiPriceWithCommission = $pricing->applyCommission($rawApiPrice);
            }

            $checkResult = $this->comparePrice(
                $formPrice,
                $apiPriceWithCommission,
                $rawApiPrice,
                $threshold,
                $extra,
                $cartId
            );

            if (!empty($checkResult['correction'])) {
                $result['corrections'][$cartId] = $checkResult['correction'];
            }

            if (!empty($checkResult['notification'])) {
                $result['notifications'][] = $checkResult['notification'];
            }
        }

        return $result;
    }

    /**
     * Look up the session price cache written by add_to_cart.
     *
     * @param array<string, mixed> $extra   Cart product extra data
     * @param int[] $childrenAges Parsed children ages
     * @param int   $ttl     Max age in seconds
     * @return array<string, mixed>|null    Cached entry or null if miss/stale
     */
    private function getCachedPrice(array $extra, array $childrenAges, int $ttl): ?array
    {
        $sessionCache = Tygh::$app['session']['novoton_price_cache'] ?? [];
        if (empty($sessionCache)) {
            return null;
        }

        $agesStr = implode(',', $childrenAges);

        $cacheKey = md5(implode('|', [
            $extra['hotel_id'] ?? '',
            $extra['room_id'] ?? '',
            $extra['board_id'] ?? '',
            $extra['check_in'] ?? '',
            $extra['check_out'] ?? '',
            (int) ($extra['adults'] ?? 2),
            $agesStr,
        ]));

        if (!isset($sessionCache[$cacheKey])) {
            return null;
        }

        $entry = $sessionCache[$cacheKey];
        $age   = time() - (int) ($entry['timestamp'] ?? 0);

        if ($age > $ttl) {
            return null; // stale
        }

        return $entry;
    }

    /**
     * Compare form price to API price and determine action.
     *
     * @return array{allow: bool, correction: array|null, notification: array|null, type: string}
     */
    private function comparePrice(
        float  $formPrice,
        float  $apiPrice,
        float  $rawApiPrice,
        float  $threshold,
        array  $extra,
        $cartId
    ): array {
        $cartId = (string) $cartId;
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

        // Use PriceChangeDetector for consistent "No Surprises" UX
        $detector = Container::getInstance()->priceChangeDetector();
        $changeInfo = $detector->analyse(
            $formPrice,
            $apiPrice,
            ConfigProvider::getApiCurrency(),
            'checkout',
            [
                'hotel_name' => $hotelName,
                'hotel_id'   => $hotelId,
                'room_id'    => $roomId,
            ]
        );

        // Case 1: Form price is LOWER than API price → CORRECT (never block)
        // Same behaviour as the add_to_cart price floor: silently upgrade to
        // the API price so the customer can complete their order.
        if ($formPrice < $apiPrice) {
            $difference = round($apiPrice - $formPrice, 2);
            $percentLower = $apiPrice > 0 ? round(($difference / $apiPrice) * 100, 1) : 0;

            fn_log_event('general', 'runtime', [
                'message'       => 'PreOrderPriceVerifier: CORRECTED — form price below API price, upgrading cart',
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

            // Admin notification (visible in CS-Cart backend)
            fn_set_notification('W', 'Price Discrepancy',
                'Price corrected at checkout: form price (' . number_format($formPrice, 2) . ' EUR) was lower than API price ('
                . number_format($apiPrice, 2) . ' EUR) for hotel ' . $hotelName . ' [' . $hotelId . ']. '
                . 'Difference: ' . number_format($difference, 2) . ' EUR (' . $percentLower . '% lower). '
                . 'Cart was updated to the API price.'
            );

            // User-facing price change alert (orange badge for increase)
            if ($changeInfo['significant']) {
                $detector->storeAlert($changeInfo, $cartId);
                fn_set_notification('W', __('novoton_holidays.price_change'),
                    __('novoton_holidays.price_updated_at_checkout', [
                        '[old_price]' => fn_format_price($formPrice),
                        '[new_price]' => fn_format_price($apiPrice),
                    ])
                );
            }

            return [
                'allow'        => true,
                'type'         => 'price_lower',
                'correction'   => [
                    'api_price'     => $apiPrice,
                    'api_price_raw' => $rawApiPrice,
                ],
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
                    'correction'   => null,
                    'notification' => $notificationData,
                ];
            }

            // Price decrease detected — show green "Price Dropped!" if significant
            if ($changeInfo['significant'] && $changeInfo['direction'] === 'decrease') {
                $detector->storeAlert($changeInfo, $cartId);
                fn_set_notification('N', __('novoton_holidays.price_dropped'),
                    __('novoton_holidays.price_dropped_to', [
                        '[new_price]' => fn_format_price($apiPrice),
                    ])
                );
            }
        }

        // Case 3: Prices are within acceptable range
        return [
            'allow'        => true,
            'type'         => 'ok',
            'correction'   => null,
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
