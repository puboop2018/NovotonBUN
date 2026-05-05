<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Dto\Invoice;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\InvoiceLine;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\VatRate;

#[CoversClass(InvoiceLine::class)]
final class InvoiceLineTest extends TestCase
{
    public function testStandardProductLine(): void
    {
        $l = new InvoiceLine(
            denumire: 'Pillow',
            nrProduse: 2.0,
            cotaTva: new VatRate(21),
            codArticol: 'SKU-1',
            pretTotal: 100.0,
            codGestiune: 'MAG-1',
            descriere: 'cotton',
        );
        $f = $l->toLineFields();
        self::assertSame('Pillow', $f['Denumire']);
        self::assertSame(2.0, $f['NrProduse']);
        self::assertSame('H87', $f['UM']);
        self::assertSame(21, $f['CotaTVA']);
        self::assertSame(100.0, $f['PretTotal']);
        self::assertSame('SKU-1', $f['CodArticol']);
        self::assertSame('MAG-1', $f['CodGestiune']);
        self::assertSame('cotton', $f['Descriere']);
        self::assertArrayNotHasKey('PretUnitar', $f);
    }

    public function testDiscountFactoryProducesNegativeQuantityLine(): void
    {
        $l = InvoiceLine::discount('Reducere', 25.5, 'DISC', new VatRate(21));
        $f = $l->toLineFields();
        self::assertSame(-1.0, $f['NrProduse']);
        self::assertSame(25.5, $f['PretTotal']);
        self::assertSame('DISC', $f['CodArticol']);
        self::assertSame(21, $f['CotaTVA']);
    }

    public function testShippingFactoryAsServiceLineWithPretTotal(): void
    {
        $l = InvoiceLine::shipping('Cost transport', 19.95, 'SHIP', new VatRate(21));
        $f = $l->toLineFields();
        self::assertSame('SX', $f['UM']);
        self::assertSame(1.0, $f['NrProduse']);
        self::assertSame(19.95, $f['PretTotal']);
        self::assertArrayNotHasKey('PretUnitar', $f);
    }

    public function testShippingFactoryWithPretUnitar(): void
    {
        $l = InvoiceLine::shipping('Cost transport', 19.95, 'SHIP', new VatRate(0), usePretUnitar: true);
        $f = $l->toLineFields();
        self::assertSame('SX', $f['UM']);
        self::assertSame(19.95, $f['PretUnitar']);
        self::assertArrayNotHasKey('PretTotal', $f);
        self::assertSame(0, $f['CotaTVA']);
    }

    public function testEmptyDenumireRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvoiceLine(denumire: '   ', nrProduse: 1.0, cotaTva: new VatRate(21), pretTotal: 1.0);
    }

    public function testZeroQuantityRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvoiceLine(denumire: 'X', nrProduse: 0.0, cotaTva: new VatRate(21), pretTotal: 1.0);
    }

    public function testMissingPriceRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvoiceLine(denumire: 'X', nrProduse: 1.0, cotaTva: new VatRate(21));
    }

    public function testNegativePretTotalRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvoiceLine(denumire: 'X', nrProduse: -1.0, cotaTva: new VatRate(21), pretTotal: -5.0);
    }

    public function testInvalidUmRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvoiceLine(denumire: 'X', nrProduse: 1.0, cotaTva: new VatRate(21), um: 'XX', pretTotal: 1.0);
    }
}
