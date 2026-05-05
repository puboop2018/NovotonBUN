<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Dto\Invoice;

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Decoded shape of the FGO `/factura/emitere` response.
 *
 * Real responses look like:
 *   {
 *     "Success": true,
 *     "Message": "...",
 *     "Factura": {"Numar": "12345", "Serie": "F", "Link": "https://...",
 *                 "LinkPlata": "https://..."},
 *     "InfoStoc": [{"CodArticol": "...", "Stoc": 45}, ...]
 *   }
 *
 * `Success=false` is intercepted by FgoApiClient and surfaced as
 * FgoApiException, so successful instances of this DTO always carry a
 * truthy `success`.
 */
final readonly class IssueInvoiceResponse
{
    /**
     * @param array<int, array<string, mixed>> $infoStoc
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public bool $success,
        public ?string $message,
        public ?string $invoiceNumber,
        public ?string $invoiceSeries,
        public ?string $pdfLink,
        public ?string $paymentLink,
        public array $infoStoc,
        public array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function fromApiResponse(array $decoded): self
    {
        $factura = is_array($decoded['Factura'] ?? null) ? $decoded['Factura'] : [];
        $infoStocRaw = $decoded['InfoStoc'] ?? [];
        $infoStocArr = is_array($infoStocRaw) ? $infoStocRaw : [];

        $cleanStoc = [];
        foreach ($infoStocArr as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            /** @var array<string, mixed> $row */
            $row = [];
            foreach ($entry as $k => $v) {
                $row[(string) $k] = $v;
            }
            $cleanStoc[] = $row;
        }

        return new self(
            success:       TypeCoerce::toBool($decoded['Success'] ?? false),
            message:       isset($decoded['Message']) ? TypeCoerce::toString($decoded['Message']) : null,
            invoiceNumber: isset($factura['Numar']) ? TypeCoerce::toString($factura['Numar']) : null,
            invoiceSeries: isset($factura['Serie']) ? TypeCoerce::toString($factura['Serie']) : null,
            pdfLink:       isset($factura['Link']) ? TypeCoerce::toString($factura['Link']) : null,
            paymentLink:   isset($factura['LinkPlata']) ? TypeCoerce::toString($factura['LinkPlata']) : null,
            infoStoc:      $cleanStoc,
            raw:           $decoded,
        );
    }
}
