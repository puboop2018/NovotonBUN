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

    // ── API object wrapper {is_loaded, text, rules:[{until|since, value}]} ──

    public function testPaymentObjectRulesRenderValueCurrencyAndFriendlyDate(): void
    {
        $payment = [
            'is_loaded' => true,
            'text' => null,
            'rules' => [
                ['until' => '2023-09-21', 'value' => 100.0],
                ['until' => '2023-10-14', 'value' => 500.0],
            ],
        ];

        self::assertSame(
            [
                '100 EUR — până la 21.09.2023',
                '500 EUR — până la 14.10.2023',
            ],
            TermsFormatter::lines($payment, 'EUR', 'până la', 'de la'),
        );
    }

    public function testCancellationObjectRulesRenderSinceDateAndValue(): void
    {
        $cancellation = [
            'is_loaded' => true,
            'is_free' => false,
            'text' => null,
            'rules' => [
                ['since' => '2023-09-21', 'value' => 100.0],
                ['since' => '2023-10-14', 'value' => 500.0],
            ],
        ];

        self::assertSame(
            [
                'de la 21.09.2023: 100 EUR',
                'de la 14.10.2023: 500 EUR',
            ],
            TermsFormatter::lines($cancellation, 'EUR', 'până la', 'de la'),
        );
    }

    public function testObjectSupplierTextWinsOverRules(): void
    {
        $terms = [
            'is_loaded' => true,
            'text' => "20% on booking\n80% until check-in",
            'rules' => [['until' => '2023-09-21', 'value' => 100.0]],
        ];

        self::assertSame(
            ['20% on booking', '80% until check-in'],
            TermsFormatter::lines($terms, 'EUR'),
        );
    }

    public function testDefaultConnectiveLabelsAreEnglish(): void
    {
        self::assertSame(
            ['100 EUR — until 21.09.2023'],
            TermsFormatter::lines(['rules' => [['until' => '2023-09-21', 'value' => 100.0]]], 'EUR'),
        );
        self::assertSame(
            ['from 21.09.2023: 100 EUR'],
            TermsFormatter::lines(['rules' => [['since' => '2023-09-21', 'value' => 100.0]]], 'EUR'),
        );
    }

    public function testEmptyRulesObjectYieldsNoLines(): void
    {
        self::assertSame([], TermsFormatter::lines(['is_loaded' => false, 'text' => null, 'rules' => []], 'EUR'));
    }

    public function testFreeCancellationUntilIsEarliestSinceDate(): void
    {
        $cancellation = [
            'is_free' => false,
            'rules' => [
                ['since' => '2023-10-14', 'value' => 500.0],
                ['since' => '2023-09-21', 'value' => 100.0],
            ],
        ];

        self::assertSame('21.09.2023', TermsFormatter::freeCancellationUntil($cancellation));
    }

    public function testFreeCancellationUntilNullWithoutDatedRules(): void
    {
        self::assertNull(TermsFormatter::freeCancellationUntil(['is_free' => true, 'rules' => []]));
        self::assertNull(TermsFormatter::freeCancellationUntil(['is_free' => true]));
        self::assertNull(TermsFormatter::freeCancellationUntil(null));
        self::assertNull(TermsFormatter::freeCancellationUntil('not-json'));
    }

    public function testFreeCancellationUntilAcceptsJsonString(): void
    {
        $json = '{"is_free":false,"rules":[{"since":"2026-07-01","value":50}]}';
        self::assertSame('01.07.2026', TermsFormatter::freeCancellationUntil($json));
    }
}
