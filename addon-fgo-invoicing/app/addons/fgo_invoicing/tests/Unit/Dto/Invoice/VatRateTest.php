<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Dto\Invoice;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\VatRate;

#[CoversClass(VatRate::class)]
final class VatRateTest extends TestCase
{
    public function testAcceptsAllStandardRates(): void
    {
        foreach ([0, 5, 9, 11, 21] as $r) {
            self::assertSame($r, (new VatRate($r))->percent);
        }
    }

    public function testRejectsUnknownRate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VatRate(19);
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function snapProvider(): iterable
    {
        return [
            'exact 21' => [21.0, 21],
            'exact 19 → 21' => [19.0, 21],
            'fractional 18.7' => [18.7, 21],
            'fractional 4.7' => [4.7, 5],
            'fractional 9.3' => [9.3, 9],
            'fractional 10.5' => [10.5, 11],
            'zero' => [0.0, 0],
            'negative' => [-5.0, 0],
            'NaN' => [NAN, 0],
            'huge' => [99.0, 21],
        ];
    }

    #[DataProvider('snapProvider')]
    public function testSnap(float $input, int $expected): void
    {
        self::assertSame($expected, VatRate::snap($input)->percent);
    }

    public function testFromSubtotalAndTaxComputesPercentage(): void
    {
        // 100 net + 21 tax → 21%
        self::assertSame(21, VatRate::fromSubtotalAndTax(100.0, 21.0)->percent);
        // 100 net + 9 tax → 9%
        self::assertSame(9, VatRate::fromSubtotalAndTax(100.0, 9.0)->percent);
        // 100 net + 0 tax → 0%
        self::assertSame(0, VatRate::fromSubtotalAndTax(100.0, 0.0)->percent);
        // 0 subtotal → 0% (no division by zero)
        self::assertSame(0, VatRate::fromSubtotalAndTax(0.0, 0.0)->percent);
    }
}
