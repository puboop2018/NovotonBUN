<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Dto\Invoice;

use Tygh\Addons\FgoInvoicing\Constants;

/**
 * One row in `Continut` on an FGO invoice request.
 *
 * Discounts and shipping are represented as ordinary lines:
 *   - shipping line: positive `pretTotal`, `nrProduse = 1`, `um = SX`.
 *   - discount line: positive `pretTotal`, `nrProduse = -1` (negative qty).
 *   - refund line:   positive `pretTotal`, `nrProduse = -1`.
 *
 * `pretTotal` is the gross amount (with tax) in shop currency.
 * `pretUnitar` is its alternative net-unit form; provide one of the two.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $denumire,
        public float $nrProduse,
        public VatRate $cotaTva,
        public string $um = Constants::UM_PIECE,
        public ?string $codArticol = null,
        public ?float $pretTotal = null,
        public ?float $pretUnitar = null,
        public ?string $codGestiune = null,
        public ?string $descriere = null,
    ) {
        if (trim($denumire) === '') {
            throw new \InvalidArgumentException('InvoiceLine.denumire must not be empty');
        }
        if ($nrProduse === 0.0) {
            throw new \InvalidArgumentException('InvoiceLine.nrProduse must not be zero');
        }
        if ($pretTotal === null && $pretUnitar === null) {
            throw new \InvalidArgumentException('InvoiceLine requires either pretTotal or pretUnitar');
        }
        if ($pretTotal !== null && $pretTotal < 0.0) {
            throw new \InvalidArgumentException('InvoiceLine.pretTotal must be non-negative; encode discounts via negative nrProduse');
        }
        if ($pretUnitar !== null && $pretUnitar < 0.0) {
            throw new \InvalidArgumentException('InvoiceLine.pretUnitar must be non-negative');
        }
        if (!in_array($um, [Constants::UM_PIECE, Constants::UM_SERVICE], true)) {
            throw new \InvalidArgumentException('InvoiceLine.um must be H87 (piece) or SX (service)');
        }
    }

    /**
     * Discount as a negative-qty line.
     */
    public static function discount(string $description, float $amount, ?string $articleCode, VatRate $vat): self
    {
        return new self(
            denumire: $description,
            nrProduse: -1.0,
            cotaTva: $vat,
            um: Constants::UM_PIECE,
            codArticol: $articleCode,
            pretTotal: abs($amount),
        );
    }

    /**
     * Shipping as a positive-qty service line.
     */
    public static function shipping(string $description, float $amount, ?string $articleCode, VatRate $vat, bool $usePretUnitar = false): self
    {
        return $usePretUnitar
            ? new self(
                denumire: $description,
                nrProduse: 1.0,
                cotaTva: $vat,
                um: Constants::UM_SERVICE,
                codArticol: $articleCode,
                pretUnitar: round($amount, 4),
            )
            : new self(
                denumire: $description,
                nrProduse: 1.0,
                cotaTva: $vat,
                um: Constants::UM_SERVICE,
                codArticol: $articleCode,
                pretTotal: round($amount, 2),
            );
    }

    /**
     * Flatten to the form-encoded shape FGO expects under the `Continut[i]`
     * prefix. The caller (IssueInvoiceRequest) attaches the index.
     *
     * @return array<string, scalar>
     */
    public function toLineFields(): array
    {
        $out = [
            'Denumire' => $this->denumire,
            'NrProduse' => $this->nrProduse,
            'UM' => $this->um,
            'CotaTVA' => $this->cotaTva->percent,
        ];
        if ($this->pretTotal !== null) {
            $out['PretTotal'] = $this->pretTotal;
        }
        if ($this->pretUnitar !== null) {
            $out['PretUnitar'] = $this->pretUnitar;
        }
        if ($this->codArticol !== null && $this->codArticol !== '') {
            $out['CodArticol'] = $this->codArticol;
        }
        if ($this->codGestiune !== null && $this->codGestiune !== '') {
            $out['CodGestiune'] = $this->codGestiune;
        }
        if ($this->descriere !== null && $this->descriere !== '') {
            $out['Descriere'] = $this->descriere;
        }
        return $out;
    }
}
