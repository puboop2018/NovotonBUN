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
            'unavailable' => [],  // Cart IDs of unavailable Sphinx offers (to be removed by caller)
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

            // If offer is no longer available, mark for removal instead of blocking the entire order.
            // This allows mixed-provider carts (Novoton + Sphinx) to proceed with the available items.
            if (empty($verifyResult) || !($verifyResult['available'] ?? false)) {
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
            $pctChange = $formPrice > 0 ? ($diff / $formPrice) * 100 : 0;

            if ($diff < 0.01) {
                continue; // Prices match
            }

            // "No Surprises" threshold: if price increased by more than X%,
            // warn the customer instead of silently correcting.
            // Price decreases are always silently applied (customer benefit).
            $noSurprisesThreshold = self::getNoSurprisesThreshold();

            $notificationData = [
                'hotel_id' => $extra['hotel_id'] ?? '',
                'hotel_name' => $extra['hotel_name'] ?? '',
                'offer_id' => $offerId,
                'form_price' => $formPrice,
                'api_price' => $apiPrice,
                'cart_id' => (string)$cartId,
                'type' => $formPrice < $apiPrice ? 'price_increase' : 'price_decrease',
                'pct_change' => round($pctChange, 1),
            ];

            if ($formPrice < $apiPrice) {
                // Price went UP since add-to-cart
                if ($noSurprisesThreshold > 0 && $pctChange > $noSurprisesThreshold) {
                    // "No Surprises": warn customer about significant price increase
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: price increased by ' . round($pctChange, 1) . '% — notifying customer',
                        'offer_id' => $offerId,
                        'form_price' => $formPrice,
                        'api_price' => $apiPrice,
                    ]);

                    $result['corrections'][$cartId] = [
                        'api_price' => $apiPrice,
                        'api_price_raw' => (float)($verifyResult['price'] ?? 0),
                    ];
                    $result['notifications'][] = $notificationData;

                    // Show customer-facing notification about price change
                    $hotelName = $extra['hotel_name'] ?? 'your hotel';
                    fn_set_notification('W', __('warning'),
                        __('sphinx_holidays.price_changed_warning', [
                            '[hotel]' => $hotelName,
                            '[old_price]' => number_format($formPrice, 2),
                            '[new_price]' => number_format($apiPrice, 2),
                            '[currency]' => $extra['currency'] ?? 'EUR',
                            '[default]' => "The price for \"{$hotelName}\" has changed from "
                                . number_format($formPrice, 2) . ' to ' . number_format($apiPrice, 2)
                                . ' ' . ($extra['currency'] ?? 'EUR')
                                . '. Your cart has been updated with the new price.',
                        ])
                    );
                } else {
                    // Small increase or threshold disabled: silently correct upward
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
                }
            } elseif ($formPrice > $apiPrice) {
                // Price went DOWN — always apply silently (customer benefit)
                $result['corrections'][$cartId] = [
                    'api_price' => $apiPrice,
                    'api_price_raw' => (float)($verifyResult['price'] ?? 0),
                ];

                if ($pctChange > 20) {
                    // Significant decrease — log for admin awareness
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx PreOrderPriceVerifier: form price above API by ' . round($pctChange, 1) . '% — applying lower price',
                        'offer_id' => $offerId,
                        'form_price' => $formPrice,
                        'api_price' => $apiPrice,
                    ]);
                    $result['notifications'][] = $notificationData;
                }
            }
        }

        return $result;
    }

    /**
     * Get the "No Surprises" price change threshold percentage.
     *
     * 0 = disabled (all price changes are silently applied).
     * Default: 2% — any increase > 2% triggers a customer warning.
     */
    private static function getNoSurprisesThreshold(): float
    {
        return ConfigProvider::getNoSurprisesThreshold();
    }
}
