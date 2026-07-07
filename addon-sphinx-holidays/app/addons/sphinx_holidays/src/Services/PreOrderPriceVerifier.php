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

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface;
use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CheckoutPriceGuard;

class PreOrderPriceVerifier implements PreOrderPriceVerifierInterface
{
    /**
     * {@inheritdoc}
     * @param array<string, mixed> $cart
     * @return array{allow: bool, corrections: array<string, mixed>, notifications: array<int, array<string, mixed>>, unavailable: array<string, mixed>, reconfirm: bool}
     */
    #[\Override]
    public function verify(array $cart): array
    {
        $result = [
            'allow' => true,
            'corrections' => [],
            'notifications' => [],
            'unavailable' => [],  // Cart IDs of unavailable Sphinx offers (to be removed by caller)
            'reconfirm' => false, // a correction exceeded the absorb allowance — hook must block for re-confirmation
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
            // Tolerant availability semantics (verify responses may carry an explicit
            // `available`, a `confirmation`, or just a priced offer) — OfferAvailability.
            if (!OfferAvailability::isVerifiedAvailable(
                $verifyResult === [] ? null : $verifyResult,
                ConfigProvider::shouldRequireImmediateAvailability(),
            )) {
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

            // Re-calculate price with commission (shared price chain: price /
            // selling_price / pricing.selling_price)
            $apiPrice = OfferAvailability::extractPrice($verifyResult);
            if ($apiPrice <= 0) {
                continue;
            }

            $apiPrice = Container::getCartService()->applyCommission($apiPrice);

            // Shared "No Surprises" policy — identical knobs for all providers,
            // configured in travel_core settings (CheckoutPriceGuard).
            $comparison = CheckoutPriceGuard::policy()->compare($formPrice, $apiPrice);

            if ($comparison->outcome === PriceComparisonOutcome::Match) {
                continue; // Prices match
            }

            $notificationData = [
                'hotel_id' => $extra['hotel_id'] ?? '',
                'hotel_name' => $extra['hotel_name'] ?? '',
                'offer_id' => $offerId,
                'form_price' => $formPrice,
                'api_price' => $apiPrice,
                'cart_id' => (string)$cartId,
                'type' => $comparison->outcome === PriceComparisonOutcome::CorrectUp ? 'price_lower' : 'price_higher',
            ];

            if ($comparison->outcome === PriceComparisonOutcome::CorrectUp) {
                // Small increase within the absorb allowance: honour the price
                // the customer was shown — the merchant absorbs the difference.
                if ($comparison->difference <= CheckoutPriceGuard::absorbIncrease()) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: ABSORBED — increase within allowance, honouring shown price',
                        'offer_id' => $offerId,
                        'form_price' => $formPrice,
                        'api_price' => $apiPrice,
                    ]);

                    $notificationData['type'] = 'price_absorbed';
                    $result['notifications'][] = $notificationData;
                    continue;
                }

                // Form price is lower than API — correct upward, never block;
                // the hook blocks this click so the customer re-confirms.
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
                $result['reconfirm'] = true;
            } else {
                // AboveThreshold: form price significantly higher — notify admin but allow
                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx PreOrderPriceVerifier: form price above API by ' . round($comparison->percentDelta, 1) . '%',
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
