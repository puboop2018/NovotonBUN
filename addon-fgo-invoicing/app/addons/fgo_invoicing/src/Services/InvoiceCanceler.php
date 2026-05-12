<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * Wraps cancel / storno / delete / AWB admin actions. Unlike the issuer,
 * these never auto-fire — they're only invoked from the admin controller.
 */
final class InvoiceCanceler
{
    public function __construct(
        private readonly FgoApiClient $api,
        private readonly InvoiceRepository $repo,
    ) {
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function cancel(int $orderId): array
    {
        return $this->actOnExisting($orderId, function (string $serie, string $numar): void {
            $this->api->cancelInvoice($serie, $numar);
        }, fn (int $oid) => $this->repo->markCanceled($oid));
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function storno(int $orderId): array
    {
        return $this->actOnExisting($orderId, function (string $serie, string $numar): void {
            $this->api->stornoInvoice($serie, $numar);
        }, fn (int $oid) => $this->repo->markReversed($oid));
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function delete(int $orderId): array
    {
        return $this->actOnExisting($orderId, function (string $serie, string $numar): void {
            $this->api->deleteInvoice($serie, $numar);
        }, fn (int $oid) => $this->repo->markDeleted($oid));
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function attachAwb(int $orderId, string $awb): array
    {
        if ($awb === '') {
            return ['status' => 'invalid', 'error' => 'AWB must not be empty'];
        }
        return $this->actOnExisting($orderId, function (string $serie, string $numar) use ($awb): void {
            $this->api->attachAwb($serie, $numar, $awb);
        }, fn (int $oid) => $this->repo->attachAwb($oid, $awb));
    }

    /**
     * @param callable(string, string): void $apiCall
     * @param callable(int): void $persistCall
     * @return array{status: string, error?: string}
     */
    private function actOnExisting(int $orderId, callable $apiCall, callable $persistCall): array
    {
        $row = $this->repo->findByOrderId($orderId);
        if ($row === null) {
            return ['status' => 'invalid', 'error' => 'No FGO invoice exists for order ' . $orderId];
        }
        $serie = TypeCoerce::toString($row['invoice_series'] ?? '');
        $numar = TypeCoerce::toString($row['invoice_number'] ?? '');
        if ($serie === '' || $numar === '') {
            return ['status' => 'invalid', 'error' => 'FGO invoice for order ' . $orderId . ' has no series/number'];
        }
        try {
            $apiCall($serie, $numar);
            $persistCall($orderId);
            return ['status' => 'ok'];
        } catch (FgoApiException $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}
