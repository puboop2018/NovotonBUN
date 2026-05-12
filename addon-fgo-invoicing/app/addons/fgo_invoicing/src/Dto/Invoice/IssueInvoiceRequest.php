<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Dto\Invoice;

use Tygh\Addons\FgoInvoicing\Dto\Billing\BillingParty;

/**
 * Top-level DTO for POST /factura/emitere.
 *
 * `toFormFields()` produces the flat key-value map that the FgoApiClient
 * sends to FGO. The Hash and Token fields are NOT computed here — those
 * are added by FgoApiClient::issueInvoice() since they depend on the
 * private key.
 */
final readonly class IssueInvoiceRequest
{
    /**
     * @param InvoiceLine[] $continut
     */
    public function __construct(
        public BillingParty $client,
        public array $continut,
        public string $valuta,
        public string $tipFactura,
        public int $idExtern,
        public string $requestId,
        public bool $verificareDuplicat = true,
        public bool $valideazaCodUnicRo = false,
        public ?string $serie = null,
        public ?string $dataEmitere = null,
        public ?string $explicatii = null,
        public ?string $text = null,
    ) {
        if ($continut === []) {
            throw new \InvalidArgumentException('IssueInvoiceRequest.continut must contain at least one line');
        }
        // Defensive: a list<mixed> can sneak past the parameter type when callers
        // build the array dynamically. Tests cover this case explicitly.
        foreach ($continut as $i => $line) {
            /** @phpstan-ignore-next-line instanceof of correctly-typed param can be vacuous */
            if (!($line instanceof InvoiceLine)) {
                throw new \InvalidArgumentException('IssueInvoiceRequest.continut[' . $i . '] must be an InvoiceLine');
            }
        }
        if ($valuta === '' || strlen($valuta) > 5) {
            throw new \InvalidArgumentException('IssueInvoiceRequest.valuta must be a non-empty ISO currency code');
        }
        if ($tipFactura === '') {
            throw new \InvalidArgumentException('IssueInvoiceRequest.tipFactura must not be empty');
        }
        if ($idExtern <= 0) {
            throw new \InvalidArgumentException('IssueInvoiceRequest.idExtern must be a positive integer');
        }
        if ($requestId === '') {
            throw new \InvalidArgumentException('IssueInvoiceRequest.requestId must not be empty');
        }
        if ($dataEmitere !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEmitere) !== 1) {
            throw new \InvalidArgumentException('IssueInvoiceRequest.dataEmitere must be ISO date YYYY-MM-DD or null');
        }
    }

    /**
     * Flatten to the form-encoded shape FGO expects.
     *
     * @return array<string, scalar>
     */
    public function toFormFields(): array
    {
        $form = [
            'Valuta' => $this->valuta,
            'TipFactura' => $this->tipFactura,
            'IdExtern' => $this->idExtern,
            'RequestId' => $this->requestId,
            'VerificareDuplicat' => $this->verificareDuplicat ? 'true' : 'false',
            'ValideazaCodUnicRo' => $this->valideazaCodUnicRo ? 'true' : 'false',
        ];

        if ($this->serie !== null && $this->serie !== '') {
            $form['Serie'] = $this->serie;
        }
        if ($this->dataEmitere !== null) {
            $form['DataEmitere'] = $this->dataEmitere;
        }
        if ($this->explicatii !== null && $this->explicatii !== '') {
            $form['Explicatii'] = $this->explicatii;
        }
        if ($this->text !== null && $this->text !== '') {
            $form['Text'] = $this->text;
        }

        foreach ($this->client->toFormFields() as $k => $v) {
            $form[$k] = $v;
        }

        foreach ($this->continut as $i => $line) {
            foreach ($line->toLineFields() as $k => $v) {
                $form["Continut[{$i}][{$k}]"] = $v;
            }
        }

        return $form;
    }
}
