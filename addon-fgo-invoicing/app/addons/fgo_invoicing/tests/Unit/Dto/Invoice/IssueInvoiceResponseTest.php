<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Dto\Invoice;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;

#[CoversClass(IssueInvoiceResponse::class)]
final class IssueInvoiceResponseTest extends TestCase
{
    public function testParsesSuccessResponseWithFacturaAndInfoStoc(): void
    {
        $decoded = [
            'Success' => true,
            'Message' => 'Invoice issued',
            'Factura' => [
                'Numar' => '12345',
                'Serie' => 'F',
                'Link' => 'https://files.fgo.ro/x.pdf',
                'LinkPlata' => 'https://pay.fgo.ro/x',
            ],
            'InfoStoc' => [
                ['CodArticol' => 'SKU1', 'Stoc' => 45],
                'not-an-array',
                ['CodArticol' => 'SKU2', 'Stoc' => 12],
            ],
        ];
        $r = IssueInvoiceResponse::fromApiResponse($decoded);
        self::assertTrue($r->success);
        self::assertSame('Invoice issued', $r->message);
        self::assertSame('12345', $r->invoiceNumber);
        self::assertSame('F', $r->invoiceSeries);
        self::assertSame('https://files.fgo.ro/x.pdf', $r->pdfLink);
        self::assertSame('https://pay.fgo.ro/x', $r->paymentLink);
        self::assertCount(2, $r->infoStoc, 'non-array entries are filtered out');
        self::assertSame($decoded, $r->raw);
    }

    public function testParsesEmptyOrPartialResponse(): void
    {
        $r = IssueInvoiceResponse::fromApiResponse(['Success' => true]);
        self::assertTrue($r->success);
        self::assertNull($r->message);
        self::assertNull($r->invoiceNumber);
        self::assertNull($r->invoiceSeries);
        self::assertNull($r->pdfLink);
        self::assertNull($r->paymentLink);
        self::assertSame([], $r->infoStoc);
    }
}
