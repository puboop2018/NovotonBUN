<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * Orchestrates issuing an FGO invoice for a CS-Cart order.
 *
 * Invariants:
 *   - At most one `issued` row per `order_id` (DB-enforced UNIQUE).
 *   - Concurrent triggers (place_order_post + change_order_status arriving
 *     in the same request) hit the same row through `insertPending` and
 *     short-circuit when status is already `issued`.
 *   - Failures persist `status=failed`, increment `retry_count`, and write
 *     the FGO `Message` (truncated) into `last_error`. The pending row is
 *     reused on subsequent re-issue attempts.
 *   - On success, if `auto_email_pdf` is on, the customer e-mail is sent
 *     with the signed PDF link. Failure to send the e-mail does NOT roll
 *     back issuance — the invoice has already been emitted on FGO's side.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly FgoApiClient $api,
        private readonly InvoiceRepository $repo,
        private readonly BillingMapper $mapper,
    ) {
    }

    /**
     * @param array<string, mixed>|null $orderInfo Pre-fetched order_info; fetched lazily when null.
     *
     * @return array{status: string, invoice_id?: string, error?: string}
     */
    public function issueForOrder(int $orderId, ?array $orderInfo = null): array
    {
        if ($orderId <= 0) {
            return ['status' => 'invalid', 'error' => 'orderId must be positive'];
        }

        $row = $this->repo->insertPending($orderId);
        if ($row['status'] === Constants::STATUS_ISSUED) {
            return ['status' => Constants::STATUS_ISSUED, 'invoice_id' => 'already-issued'];
        }

        if ($orderInfo === null) {
            $orderInfo = $this->loadOrderInfo($orderId);
            if ($orderInfo === null) {
                $err = 'fn_get_order_info returned no data for order ' . $orderId;
                $this->repo->markFailed($orderId, $err, []);
                $this->logEvent('error', 'order-not-found', ['order_id' => $orderId]);
                return ['status' => Constants::STATUS_FAILED, 'error' => $err];
            }
        }

        try {
            $request = $this->mapper->mapOrderInfo($orderInfo);
            $form = $request->toFormFields();

            $rawResponse = $this->api->issueInvoice($form);
            $response = IssueInvoiceResponse::fromApiResponse($rawResponse);

            $this->repo->markIssued($orderId, $response, $form);
            $this->maybeEmail($orderInfo, $response);
            $this->logEvent('info', 'issued', [
                'order_id' => $orderId,
                'invoice_number' => $response->invoiceNumber,
                'invoice_series' => $response->invoiceSeries,
            ]);
            return [
                'status' => Constants::STATUS_ISSUED,
                'invoice_id' => (string) ($response->invoiceNumber ?? ''),
            ];
        } catch (FgoApiException $e) {
            $form ??= [];
            $this->repo->markFailed($orderId, $e->getMessage(), $form, $e->rawResponse);
            $this->logEvent('error', 'fgo-rejected', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'http' => $e->httpStatus,
            ]);
            return ['status' => Constants::STATUS_FAILED, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $form ??= [];
            $msg = '[' . $e::class . '] ' . $e->getMessage();
            $this->repo->markFailed($orderId, $msg, $form);
            $this->logEvent('error', 'unexpected', [
                'order_id' => $orderId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return ['status' => Constants::STATUS_FAILED, 'error' => $msg];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadOrderInfo(int $orderId): ?array
    {
        if (!function_exists('fn_get_order_info')) {
            return null;
        }
        /** @var array<string, mixed>|null $info */
        $info = fn_get_order_info($orderId);
        return is_array($info) && $info !== [] ? $info : null;
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    private function maybeEmail(array $orderInfo, IssueInvoiceResponse $response): void
    {
        if (!ConfigProvider::autoEmailPdf()) {
            return;
        }
        if ($response->pdfLink === null || $response->pdfLink === '') {
            return;
        }
        $email = trim(TypeCoerce::toString($orderInfo['email'] ?? ''));
        if ($email === '' || !function_exists('fn_fgo_invoicing_send_invoice_email')) {
            return;
        }
        try {
            fn_fgo_invoicing_send_invoice_email([
                'to' => $email,
                'order_id' => TypeCoerce::toInt($orderInfo['order_id'] ?? 0),
                'invoice_number' => $response->invoiceNumber ?? '',
                'invoice_series' => $response->invoiceSeries ?? '',
                'pdf_link' => $response->pdfLink,
                'payment_link' => $response->paymentLink ?? '',
                'company_name' => trim(TypeCoerce::toString($orderInfo['fgo_billing_company'] ?? $orderInfo['b_company'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            $this->logEvent('warn', 'email-send-failed', [
                'order_id' => TypeCoerce::toInt($orderInfo['order_id'] ?? 0),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $level, string $event, array $context): void
    {
        if (!function_exists('fn_log_event')) {
            return;
        }
        if (!ConfigProvider::debugLogging() && $level === 'info') {
            return;
        }
        fn_log_event('fgo_invoicing', 'runtime', [
            'message' => '[' . $level . '] ' . $event,
            'context' => $context,
        ]);
    }
}
