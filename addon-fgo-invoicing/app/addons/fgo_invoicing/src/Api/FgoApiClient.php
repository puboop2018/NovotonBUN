<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Api;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * High-level FGO API client.
 *
 * Wraps FgoHttpClient with the authentication-field assembly (CodUnic +
 * Hash + Token) for each endpoint, plus Success/Message envelope handling.
 *
 * All endpoints use `application/x-www-form-urlencoded` bodies — that's
 * what the PrestaShop reference plugin v0.6.0.0 does for every call. The
 * WooCommerce plugin v0.6.3.7 switched `/factura/emitere` to JSON (to
 * sidestep an ASP.NET request-validation issue with HTML-flavoured product
 * names); we stick with form-encoded for parity with PrestaShop, and only
 * pre-strip HTML tags from product names in the BillingMapper.
 *
 * `Success=false` from FGO is surfaced as FgoApiException so callers can
 * persist the original message in `cscart_fgo_invoices.last_error`.
 */
/**
 * Not `final` so unit tests can stub it with anonymous classes; production
 * code should still treat it as the only implementation.
 */
class FgoApiClient
{
    public function __construct(
        private readonly FgoHttpClient $http,
        private readonly string $clientCode,
        private readonly string $privateKey,
        private readonly string $platformUrl = '',
        private readonly string $platformVersion = '',
        private readonly string $addonVersion = '0.1.0',
    ) {
    }

    /**
     * Validate credentials. Returns the decoded response on success, throws
     * on failure (network, decode, or Success=false).
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $form = $this->credentialFields()
            + [
                'Hash' => FgoSigner::checkHash($this->clientCode, $this->privateKey),
                'Token' => FgoSigner::checkToken(),
            ];

        return $this->postOrThrow(Constants::PATH_CHECK, $form);
    }

    /**
     * Issue an invoice/proforma/aviz. The caller passes the already-flattened
     * form fields (Client[...], Continut[N][...], Valuta, TipFactura, Serie,
     * IdExtern, Explicatii, Text, VerificareDuplicat, ValideazaCodUnicRo,
     * RequestId, plus the customer name in `Client[Denumire]` for hashing).
     *
     * The client adds CodUnic/Hash/Token + platform metadata.
     *
     * @param array<string, scalar|null> $payload
     * @return array<string, mixed>
     */
    public function issueInvoice(array $payload): array
    {
        $customerName = (string) ($payload['Client[Denumire]'] ?? '');
        $orderId = (string) ($payload['IdExtern'] ?? '0');
        $clientIdExt = (string) ($payload['Client[IdExtern]'] ?? '0');

        $form = $this->credentialFields()
            + [
                'Hash' => FgoSigner::issueHash($this->clientCode, $this->privateKey, $customerName),
                'Token' => FgoSigner::issueToken($orderId, $clientIdExt),
            ];

        // Drop nulls so http_build_query does not emit "field=".
        foreach ($payload as $k => $v) {
            if ($v === null) {
                continue;
            }
            $form[$k] = $v;
        }

        return $this->postOrThrow(Constants::PATH_EMITERE, $form);
    }

    /** @return array<string, mixed> */
    public function cancelInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->postOrThrow(Constants::PATH_ANULARE, $this->existingInvoiceFields($invoiceSeries, $invoiceNumber));
    }

    /** @return array<string, mixed> */
    public function stornoInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->postOrThrow(Constants::PATH_STORNARE, $this->existingInvoiceFields($invoiceSeries, $invoiceNumber));
    }

    /** @return array<string, mixed> */
    public function deleteInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->postOrThrow(Constants::PATH_STERGERE, $this->existingInvoiceFields($invoiceSeries, $invoiceNumber));
    }

    /** @return array<string, mixed> */
    public function attachAwb(string $invoiceSeries, string $invoiceNumber, string $awb): array
    {
        $fields = $this->existingInvoiceFields($invoiceSeries, $invoiceNumber);
        $fields['AWB'] = $awb;
        return $this->postOrThrow(Constants::PATH_AWB, $fields);
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @return array<string, scalar>
     */
    private function credentialFields(): array
    {
        return [
            'CodUnic' => $this->clientCode,
            'Platforma' => Constants::PLATFORM_NAME,
            'PlatformaUrl' => $this->platformUrl,
            'Versiune' => $this->platformVersion,
            'VersiuneAddon' => $this->addonVersion,
        ];
    }

    /**
     * @return array<string, scalar>
     */
    private function existingInvoiceFields(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->credentialFields()
            + [
                'Serie' => $invoiceSeries,
                'Numar' => $invoiceNumber,
                'Hash' => FgoSigner::existingInvoiceHash($this->clientCode, $this->privateKey, $invoiceNumber),
                'Token' => FgoSigner::existingInvoiceToken($invoiceSeries, $invoiceNumber),
            ];
    }

    /**
     * @param array<string, scalar|null> $form
     * @return array<string, mixed>
     */
    private function postOrThrow(string $path, array $form): array
    {
        $cleaned = [];
        foreach ($form as $k => $v) {
            if ($v === null) {
                continue;
            }
            $cleaned[$k] = $v;
        }

        $decoded = $this->http->post($path, $cleaned);

        if ($decoded === null) {
            throw new FgoApiException(
                'FGO HTTP failure on ' . $path . ': ' . $this->http->getLastError(),
                null,
                $this->http->getLastHttpCode() ?: null,
            );
        }

        $success = ($decoded['Success'] ?? false) === true;
        if (!$success) {
            $msg = TypeCoerce::toString($decoded['Message'] ?? 'FGO returned Success=false');
            if ($msg === '') {
                $msg = 'FGO returned Success=false';
            }
            throw new FgoApiException($msg, $decoded, $this->http->getLastHttpCode() ?: null);
        }

        return $decoded;
    }
}
