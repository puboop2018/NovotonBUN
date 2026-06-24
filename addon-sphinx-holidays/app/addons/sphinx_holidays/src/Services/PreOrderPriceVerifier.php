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
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    /**
     * {@inheritdoc}
     * @param array<string, mixed> $cart
     * @return array{allow: bool, corrections: array<string, mixed>, notifications: array<int, array<string, mixed>>, unavailable: array<string, mixed>}
     */
    #[\Override]
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'corrections' => [],
            'notifications' => [],
            'unavailable' => [],  // Cart IDs of unavailable Sphinx offers (to be removed by caller)
        ];

        if (empty($cart['products'])) {
            return $result;
        }

        $api = null;

        foreach (TypeCoerce::toStringMap($cart['products']) as $cartId => $product) {
            $productData = TypeCoerce::toStringMap($product);
            $extra = TypeCoerce::toStringMap($productData['extra'] ?? null);
            if (empty($extra['sphinx_booking'])) {
                continue;
            }

            $offerId = TypeCoerce::toString($extra['offer_id'] ?? '');
            $formPrice = TypeCoerce::toFloat($extra['total_price'] ?? $productData['price'] ?? 0);

            if (empty($offerId) || $formPrice <= 0) {
                continue;
            }

            // Lazy-load the API
            if ($api === null) {
                try {
                    $api = Container::getApi();
                } catch (\Throwable) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: API unavailable, skipping',
                    ]);
                    return $result;
                }
            }

            try {
                $verifyResult = TypeCoerce::toStringMap($api->verifyHotelOffer($offerId));
            } catch (\Throwable $e) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: offer verify failed',
                    'offer_id' => $offerId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // If offer is no longer available, mark for removal instead of blocking the entire order.
            // This allows mixed-provider carts (Novoton + Sphinx) to proceed with the available items.
            if (empty($verifyResult) || !(bool) ($verifyResult['available'] ?? false)) {
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: offer unavailable — marking for removal',
                    'offer_id' => $offerId,
                    'hotel_name' => $extra['hotel_name'] ?? '',
                ]);

                $result['unavailable'][$cartId] = [
                    'offer_id' => $offerId,
                    'hotel_name' => $extra['hotel_name'] ?? '',
                ];
                continue;
            }

            // Re-calculate price with commission
            $apiPrice = TypeCoerce::toFloat($verifyResult['price'] ?? 0);
            if ($apiPrice <= 0) {
                continue;
            }

            $apiPrice = Container::getCartService()->applyCommission($apiPrice);

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
                    'api_price_raw' => TypeCoerce::toFloat($verifyResult['price'] ?? 0),
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
