<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Repository;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Persistence for `cscart_fgo_invoices`.
 *
 * One row per CS-Cart order id (UNIQUE constraint). The `insertPending`
 * method uses INSERT ... ON DUPLICATE KEY UPDATE so concurrent
 * change-order-status hooks don't double-issue.
 */
/**
 * Not `final` so unit tests can stub it with an in-memory subclass; production
 * code should still treat this as the only implementation.
 */
class InvoiceRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $row = db_get_row('SELECT * FROM ?:fgo_invoices WHERE order_id = ?i', $orderId);
        if (!is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Insert a `pending` row, or — if one already exists — return its id
     * unchanged. Returns the row id and a flag indicating whether the row
     * already existed.
     *
     * @return array{id: int, isExisting: bool, status: string}
     */
    public function insertPending(int $orderId, ?int $cartId = null): array
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('orderId must be positive');
        }

        $existing = $this->findByOrderId($orderId);
        if ($existing !== null) {
            return [
                'id' => TypeCoerce::toInt($existing['id'] ?? 0),
                'isExisting' => true,
                'status' => TypeCoerce::toString($existing['status'] ?? ''),
            ];
        }

        db_query(
            'INSERT IGNORE INTO ?:fgo_invoices (order_id, cart_id, status, created_at, updated_at)
             VALUES (?i, ?i, ?s, NOW(), NOW())',
            $orderId,
            $cartId ?? 0,
            Constants::STATUS_PENDING,
        );

        $reload = $this->findByOrderId($orderId);
        if ($reload === null) {
            throw new \RuntimeException('Failed to insert/load fgo_invoices row for order ' . $orderId);
        }
        return [
            'id' => TypeCoerce::toInt($reload['id'] ?? 0),
            'isExisting' => false,
            'status' => TypeCoerce::toString($reload['status'] ?? ''),
        ];
    }

    /**
     * @param array<string, scalar|null> $requestForm raw form fields sent to FGO (for diagnostics)
     */
    public function markIssued(int $orderId, IssueInvoiceResponse $response, array $requestForm): void
    {
        db_query(
            'UPDATE ?:fgo_invoices
             SET status = ?s,
                 success = 1,
                 invoice_number = ?s,
                 invoice_series = ?s,
                 pdf_link = ?s,
                 payment_link = ?s,
                 message = ?s,
                 request_payload = ?s,
                 payload = ?s,
                 last_error = NULL,
                 updated_at = NOW()
             WHERE order_id = ?i',
            Constants::STATUS_ISSUED,
            (string) ($response->invoiceNumber ?? ''),
            (string) ($response->invoiceSeries ?? ''),
            (string) ($response->pdfLink ?? ''),
            (string) ($response->paymentLink ?? ''),
            (string) ($response->message ?? ''),
            (string) json_encode($requestForm, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            (string) json_encode($response->raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $orderId,
        );
    }

    /**
     * @param array<string, scalar|null> $requestForm
     * @param array<string, mixed>|null $rawResponse
     */
    public function markFailed(int $orderId, string $errorMessage, array $requestForm, ?array $rawResponse = null): void
    {
        db_query(
            'UPDATE ?:fgo_invoices
             SET status = ?s,
                 success = 0,
                 last_error = ?s,
                 message = ?s,
                 retry_count = retry_count + 1,
                 request_payload = ?s,
                 payload = ?s,
                 updated_at = NOW()
             WHERE order_id = ?i',
            Constants::STATUS_FAILED,
            $errorMessage,
            mb_substr($errorMessage, 0, 250),
            (string) json_encode($requestForm, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $rawResponse !== null
                ? (string) json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
                : '',
            $orderId,
        );
    }

    public function markCanceled(int $orderId): void
    {
        $this->updateStatus($orderId, Constants::STATUS_CANCELED);
    }

    public function markReversed(int $orderId): void
    {
        $this->updateStatus($orderId, Constants::STATUS_REVERSED);
    }

    public function markDeleted(int $orderId): void
    {
        $this->updateStatus($orderId, Constants::STATUS_DELETED);
    }

    public function attachAwb(int $orderId, string $awb): void
    {
        db_query(
            'UPDATE ?:fgo_invoices SET awb = ?s, updated_at = NOW() WHERE order_id = ?i',
            $awb,
            $orderId,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = db_get_array(
            'SELECT * FROM ?:fgo_invoices ORDER BY id DESC LIMIT ?i',
            $limit,
        );
        if (!is_array($rows)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    private function updateStatus(int $orderId, string $status): void
    {
        db_query(
            'UPDATE ?:fgo_invoices SET status = ?s, updated_at = NOW() WHERE order_id = ?i',
            $status,
            $orderId,
        );
    }
}
