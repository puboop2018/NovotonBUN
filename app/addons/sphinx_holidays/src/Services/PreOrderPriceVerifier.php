<?php
declare(strict_types=1);
/**
 * Sphinx Holidays - Pre-Order Price Verifier
 *
 * Re-verifies Sphinx hotel offer prices at checkout (pre_place_order hook).
 * If the offer is no longer available or the price has changed, applies
 * corrections or blocks the order.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    /**
     * {@inheritdoc}
     */
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'corrections' => [],
            'notifications' => [],
        ];

        if (empty($cart['products'])) {
            return $result;
        }

        $api = null;

        foreach ($cart['products'] as $cartId => $product) {
            if (empty($product['extra']['sphinx_booking'])) {
                continue;
            }

            $extra = $product['extra'];
            $offerId = $extra['offer_id'] ?? '';
            $formPrice = (float)($extra['total_price'] ?? $product['price'] ?? 0);

            if (empty($offerId) || $formPrice <= 0) {
                continue;
            }

            // Lazy-load the API
            if ($api === null) {
                try {
                    $api = Container::getApi();
                } catch (\Throwable $e) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: API unavailable, skipping',
                    ]);
                    return $result;
                }
            }

            try {
                $verifyResult = $api->verifyHotelOffer($offerId);
            } catch (\Throwable $e) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: offer verify failed',
                    'offer_id' => $offerId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // If offer is no longer available, block the order
            if (empty($verifyResult) || !($verifyResult['available'] ?? false)) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: offer unavailable',
                    'offer_id' => $offerId,
                ]);

                fn_set_notification('E', __('error'),
                    __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available.'])
                );

                $result['allow'] = false;
                return $result;
            }

            // Re-calculate price with commission
            $apiPrice = (float)($verifyResult['price'] ?? 0);
            if ($apiPrice <= 0) {
                continue;
            }

            $commission = ConfigProvider::getCommission();
            $roundPrices = ConfigProvider::shouldRoundPrices();
            if ($commission > 0) {
                $calculator = new CommissionCalculator($commission, $roundPrices);
                $apiPrice = $calculator->apply($apiPrice);
            }

            // Compare prices
            $diff = abs($formPrice - $apiPrice);
            $threshold = $formPrice > 0 ? ($diff / $formPrice) * 100 : 0;

            if ($diff < 0.01) {
                continue; // Prices match
            }

            $notificationData = [
                'hotel_id' => $extra['hotel_id'] ?? '',
                'hotel_name' => $extra['hotel_name'] ?? '',
                'offer_id' => $offerId,
                'form_price' => $formPrice,
                'api_price' => $apiPrice,
                'cart_id' => (string)$cartId,
                'type' => $formPrice < $apiPrice ? 'price_lower' : 'price_higher',
            ];

            // If form price is lower than API, correct upward
            if ($formPrice < $apiPrice) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: correcting price upward',
                    'offer_id' => $offerId,
                    'form_price' => $formPrice,
                    'api_price' => $apiPrice,
                ]);

                $result['corrections'][$cartId] = [
                    'api_price' => $apiPrice,
                    'api_price_raw' => (float)($verifyResult['price'] ?? 0),
                ];
                $result['notifications'][] = $notificationData;
            } elseif ($threshold > 20) {
                // Form price significantly higher — notify admin but allow
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: form price above API by ' . round($threshold, 1) . '%',
                    'offer_id' => $offerId,
                    'form_price' => $formPrice,
                    'api_price' => $apiPrice,
                ]);

                $result['notifications'][] = $notificationData;
            }
        }

        return $result;
    }
}
