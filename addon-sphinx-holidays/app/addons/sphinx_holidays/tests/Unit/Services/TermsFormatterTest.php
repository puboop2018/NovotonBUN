<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\TermsFormatter;

final class TermsFormatterTest extends TestCase
{
    public function testEmptyAndUnparseableInputsYieldNoLines(): void
    {
        self::assertSame([], TermsFormatter::lines(null));
        self::assertSame([], TermsFormatter::lines(''));
        self::assertSame([], TermsFormatter::lines('   '));
        self::assertSame([], TermsFormatter::lines([]));
        self::assertSame([], TermsFormatter::lines(42));
        self::assertSame([], TermsFormatter::lines([['unrelated' => 'keys']]));
    }

    public function testListOfStringsPassesThrough(): void
    {
        self::assertSame(
            ['Deposit 30% on booking', 'Balance 14 days before arrival'],
            TermsFormatter::lines(['Deposit 30% on booking', 'Balance 14 days before arrival']),
        );
    }

    public function testStructuredEntriesComposeTextAmountAndDate(): void
    {
        $lines = TermsFormatter::lines([
            ['description' => 'Deposit', 'amount' => 296.1, 'currency' => 'EUR', 'due_date' => '2026-07-01'],
            ['label' => 'Free cancellation', 'until' => '2026-07-07'],
            ['text' => 'Late cancellation fee', 'percent' => 50],
        ]);

        self::assertSame(
            [
                'Deposit — 296.1 EUR (2026-07-01)',
                'Free cancellation (2026-07-07)',
                'Late cancellation fee — 50%',
            ],
            $lines,
        );
    }

    public function testJsonStringInputDecodes(): void
    {
        $json = '[{"description":"Non-refundable","percent":100}]';

        self::assertSame(['Non-refundable — 100%'], TermsFormatter::lines($json));
    }

    public function testBareSentenceStringBecomesSingleLine(): void
    {
        self::assertSame(
            ['Full payment at booking.'],
            TermsFormatter::lines('Full payment at booking.'),
        );
    }
}
