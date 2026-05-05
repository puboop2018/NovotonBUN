<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Dto\Billing;

use Tygh\Addons\FgoInvoicing\Api\FgoSigner;
use Tygh\Addons\FgoInvoicing\Constants;

/**
 * Customer block on an FGO invoice request. Maps to the `Client[...]`
 * keys of /factura/emitere.
 *
 * - `tip = "PJ"` (legal entity / company) — `codUnic` (CIF), `nrRegCom`,
 *   and optionally `platitorTva` are populated.
 * - `tip = "PF"` (private individual) — `codUnic` may carry the CNP if the
 *   merchant collects it, otherwise null.
 *
 * `strain` is `true` when the customer's country is not RO.
 *
 * Customer name is stored as-is here; the diacritics-flattened form goes
 * into `Client[Denumire]` and the `Hash` field in the request envelope.
 *
 * @see Tygh\Addons\FgoInvoicing\Services\BillingMapper
 */
final readonly class BillingParty
{
    public function __construct(
        public string $denumire,
        public string $tip,
        public int $idExtern,
        public string $email,
        public string $telefon,
        public string $tara,
        public string $judet,
        public string $localitate,
        public string $adresa,
        public bool $strain,
        public ?string $codUnic = null,
        public ?string $nrRegCom = null,
        public bool $platitorTva = false,
    ) {
        if ($denumire === '') {
            throw new \InvalidArgumentException('BillingParty.denumire must not be empty');
        }
        if (!in_array($tip, [Constants::TIP_COMPANY, Constants::TIP_PERSON], true)) {
            throw new \InvalidArgumentException('BillingParty.tip must be PJ or PF, got "' . $tip . '"');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('BillingParty.email is not a valid e-mail address');
        }
        if (strlen($tara) !== 0 && strlen($tara) !== 2) {
            throw new \InvalidArgumentException('BillingParty.tara must be empty or a 2-letter ISO code');
        }
    }

    /**
     * Build a flat `Client[...]` map suitable for the form-encoded body.
     *
     * @return array<string, scalar>
     */
    public function toFormFields(): array
    {
        $out = [
            'Client[Denumire]' => FgoSigner::convertDiacritics2($this->denumire),
            'Client[Tip]' => $this->tip,
            'Client[IdExtern]' => $this->idExtern,
            'Client[Email]' => $this->email,
            'Client[Telefon]' => $this->telefon,
            'Client[Tara]' => $this->tara,
            'Client[Judet]' => $this->judet,
            'Client[Localitate]' => $this->localitate,
            'Client[Adresa]' => $this->adresa,
        ];

        if ($this->strain) {
            $out['Client[Strain]'] = 'true';
        }
        if ($this->codUnic !== null && $this->codUnic !== '') {
            $out['Client[CodUnic]'] = $this->codUnic;
        }
        if ($this->nrRegCom !== null && $this->nrRegCom !== '') {
            $out['Client[NrRegCom]'] = $this->nrRegCom;
        }
        if ($this->platitorTva) {
            $out['Client[PlatitorTVA]'] = 'true';
        }
        return $out;
    }

    public function isCompany(): bool
    {
        return $this->tip === Constants::TIP_COMPANY;
    }
}
