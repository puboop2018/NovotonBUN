<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Dto\Billing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Dto\Billing\BillingParty;

#[CoversClass(BillingParty::class)]
final class BillingPartyTest extends TestCase
{
    public function testCompanyToFormFieldsIncludesTaxFields(): void
    {
        $b = new BillingParty(
            denumire:    'SC ACME SRL',
            tip:         'PJ',
            idExtern:    42,
            email:       'office@acme.ro',
            telefon:     '+40700111222',
            tara:        'RO',
            judet:       'București',
            localitate:  'Sector 1',
            adresa:      'Str. Mare 1',
            strain:      false,
            codUnic:     '12345678',
            nrRegCom:    'J40/12345/2020',
            platitorTva: true,
        );

        $f = $b->toFormFields();
        self::assertSame('SC ACME SRL', $f['Client[Denumire]']);
        self::assertSame('PJ', $f['Client[Tip]']);
        self::assertSame(42, $f['Client[IdExtern]']);
        self::assertSame('12345678', $f['Client[CodUnic]']);
        self::assertSame('J40/12345/2020', $f['Client[NrRegCom]']);
        self::assertSame('true', $f['Client[PlatitorTVA]']);
        self::assertArrayNotHasKey('Client[Strain]', $f);
        self::assertTrue($b->isCompany());
    }

    public function testForeignCustomerCarriesStrainFlag(): void
    {
        $b = new BillingParty(
            denumire:   'Hans Müller',
            tip:        'PF',
            idExtern:   7,
            email:      'hans@example.de',
            telefon:    '',
            tara:       'DE',
            judet:      'Berlin',
            localitate: 'Berlin',
            adresa:     'Strasse 1',
            strain:     true,
        );

        $f = $b->toFormFields();
        self::assertSame('true', $f['Client[Strain]']);
        self::assertArrayNotHasKey('Client[CodUnic]', $f);
        self::assertArrayNotHasKey('Client[NrRegCom]', $f);
        self::assertArrayNotHasKey('Client[PlatitorTVA]', $f);
        self::assertFalse($b->isCompany());
    }

    public function testDiacriticsAreFlattenedInFormFields(): void
    {
        $b = new BillingParty(
            denumire:   'Ștefan Țăndărei',
            tip:        'PF',
            idExtern:   1,
            email:      'a@b.ro',
            telefon:    '',
            tara:       'RO',
            judet:      'Cluj',
            localitate: 'Cluj-Napoca',
            adresa:     'Str. X 1',
            strain:     false,
        );
        self::assertSame('Stefan Tandarei', $b->toFormFields()['Client[Denumire]']);
        // The original-cased unflattened name is preserved on the DTO.
        self::assertSame('Ștefan Țăndărei', $b->denumire);
    }

    public function testEmptyDenumireRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BillingParty(
            denumire:   '',
            tip:        'PF',
            idExtern:   1,
            email:      '',
            telefon:    '',
            tara:       'RO',
            judet:      '',
            localitate: '',
            adresa:     '',
            strain:     false,
        );
    }

    public function testInvalidTipRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BillingParty(
            denumire:   'X',
            tip:        'XX',
            idExtern:   1,
            email:      '',
            telefon:    '',
            tara:       'RO',
            judet:      '',
            localitate: '',
            adresa:     '',
            strain:     false,
        );
    }

    public function testInvalidEmailRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BillingParty(
            denumire:   'X',
            tip:        'PF',
            idExtern:   1,
            email:      'not-an-email',
            telefon:    '',
            tara:       'RO',
            judet:      '',
            localitate: '',
            adresa:     '',
            strain:     false,
        );
    }

    public function testTaraOf3CharsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BillingParty(
            denumire:   'X',
            tip:        'PF',
            idExtern:   1,
            email:      '',
            telefon:    '',
            tara:       'ROU',
            judet:      '',
            localitate: '',
            adresa:     '',
            strain:     false,
        );
    }
}
