<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Dto\Invoice;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Dto\Billing\BillingParty;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\InvoiceLine;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceRequest;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\VatRate;

#[CoversClass(IssueInvoiceRequest::class)]
final class IssueInvoiceRequestTest extends TestCase
{
    private function client(): BillingParty
    {
        return new BillingParty(
            denumire: 'Ion Popescu',
            tip: 'PF',
            idExtern: 7,
            email: 'a@b.ro',
            telefon: '',
            tara: 'RO',
            judet: 'Cluj',
            localitate: 'Cluj',
            adresa: 'Str. X 1',
            strain: false,
        );
    }

    public function testFlattensClientAndContinutWithIndices(): void
    {
        $req = new IssueInvoiceRequest(
            client:    $this->client(),
            continut:  [
                new InvoiceLine(denumire: 'A', nrProduse: 1.0, cotaTva: new VatRate(21), pretTotal: 100.0),
                new InvoiceLine(denumire: 'B', nrProduse: 2.0, cotaTva: new VatRate(9), pretTotal: 50.0),
            ],
            valuta:    'RON',
            tipFactura:'Factura',
            idExtern:  1234,
            requestId: 'req-1',
            verificareDuplicat: true,
            valideazaCodUnicRo: false,
            serie:     'F',
            explicatii:'Comanda nr. 1234',
        );

        $f = $req->toFormFields();
        self::assertSame('RON', $f['Valuta']);
        self::assertSame('Factura', $f['TipFactura']);
        self::assertSame(1234, $f['IdExtern']);
        self::assertSame('req-1', $f['RequestId']);
        self::assertSame('true', $f['VerificareDuplicat']);
        self::assertSame('false', $f['ValideazaCodUnicRo']);
        self::assertSame('F', $f['Serie']);
        self::assertSame('Comanda nr. 1234', $f['Explicatii']);
        self::assertSame('Ion Popescu', $f['Client[Denumire]']);
        self::assertSame(7, $f['Client[IdExtern]']);
        self::assertSame('A', $f['Continut[0][Denumire]']);
        self::assertSame(100.0, $f['Continut[0][PretTotal]']);
        self::assertSame(21, $f['Continut[0][CotaTVA]']);
        self::assertSame('B', $f['Continut[1][Denumire]']);
        self::assertSame(2.0, $f['Continut[1][NrProduse]']);
        self::assertSame(9, $f['Continut[1][CotaTVA]']);
    }

    public function testEmptyContinutRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IssueInvoiceRequest(
            client: $this->client(),
            continut: [],
            valuta: 'RON',
            tipFactura: 'Factura',
            idExtern: 1,
            requestId: 'r',
        );
    }

    public function testNonInvoiceLineInContinutRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line  intentionally bad shape */
        new IssueInvoiceRequest(
            client: $this->client(),
            continut: [['foo' => 'bar']],
            valuta: 'RON',
            tipFactura: 'Factura',
            idExtern: 1,
            requestId: 'r',
        );
    }

    public function testEmptyValutaRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IssueInvoiceRequest(
            client: $this->client(),
            continut: [new InvoiceLine(denumire: 'A', nrProduse: 1.0, cotaTva: new VatRate(0), pretTotal: 1.0)],
            valuta: '',
            tipFactura: 'Factura',
            idExtern: 1,
            requestId: 'r',
        );
    }

    public function testNonPositiveOrderIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IssueInvoiceRequest(
            client: $this->client(),
            continut: [new InvoiceLine(denumire: 'A', nrProduse: 1.0, cotaTva: new VatRate(0), pretTotal: 1.0)],
            valuta: 'RON',
            tipFactura: 'Factura',
            idExtern: 0,
            requestId: 'r',
        );
    }

    public function testInvalidDataEmitereRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IssueInvoiceRequest(
            client: $this->client(),
            continut: [new InvoiceLine(denumire: 'A', nrProduse: 1.0, cotaTva: new VatRate(0), pretTotal: 1.0)],
            valuta: 'RON',
            tipFactura: 'Factura',
            idExtern: 1,
            requestId: 'r',
            dataEmitere: '2026/01/02',
        );
    }
}
