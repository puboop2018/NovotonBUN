<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Verifies hotel room prices via the Novoton API.
 *
 * Extracted from BookingService (SRP) — isolates the external API call,
 * commission application, and terms extraction into a focused service.
 *
 * Complements PreOrderPriceVerifier (which handles session-cached checks
 * at checkout time). This service handles the full API verification
 * during booking creation.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */
class PriceVerificationService implements PriceVerificationServiceInterface
{
    /** @var \Tygh\Addons\NovotonHolidays\NovotonApi */
    private $api;

    /** @var bool */
    private bool $debug;

    public function __construct(\Tygh\Addons\NovotonHolidays\NovotonApi $api)
    {
        $this->api = $api;
        $this->debug = ConfigProvider::isDebugLogging();
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
    #[\Override]
    public function verifyPrice(array $params): array
    {
        $priceParams = [
            'hotel_id' => $params['hotel_id'],
            'room_id' => $params['room_id'] ?? '',
            'board_id' => $params['board_id'] ?? '',
            'star_rating' => '',
            'check_in' => $params['check_in'],
            'check_out' => $params['check_out'],
            'adults' => (int) ($params['adults'] ?? 2),
            'children' => $params['children_ages'] ?? [],
        ];

        $priceData = $this->api->getRoomPrice($priceParams);

        if (!$priceData || !isset($priceData->Price)) {
            $this->log('Price verification failed', [
                'hotel_id' => $params['hotel_id'],
                'room_id' => $params['room_id'] ?? '',
                'children_ages' => $params['children_ages'] ?? [],
            ]);
            return [
                'success' => false,
                'total_price' => 0,
                'base_price' => 0,
                'terms_of_payment' => '',
                'terms_of_cancellation' => '',
                'remark' => '',
                'important' => '',
                'error' => 'price_verification_failed',
            ];
        }

        $rawPrice = (float) (string) $priceData->Price;
        $totalPrice = $this->api->applyCommission($rawPrice);

        // Extract terms
        $termsOfPayment = '';
        $termsOfCancellation = '';
        if ($priceData instanceof \SimpleXMLElement) {
            $tp = $priceData->xpath('//TermsOfPayment');
            $tc = $priceData->xpath('//TermsOfCancellation');
            if (!empty($tp[0])) {
                $termsOfPayment = $tp[0]->asXML();
            }
            if (!empty($tc[0])) {
                $termsOfCancellation = $tc[0]->asXML();
            }
        }

        return [
            'success' => true,
            'total_price' => $totalPrice,
            'base_price' => $rawPrice,
            'terms_of_payment' => $termsOfPayment,
            'terms_of_cancellation' => $termsOfCancellation,
            'remark' => isset($priceData->remark) ? (string) $priceData->remark : '',
            'important' => isset($priceData->Important) ? (string) $priceData->Important : '',
            'error' => '',
        ];
    }

    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonPrice: ' . $message],
                $context
            ));
        }
    }
}
