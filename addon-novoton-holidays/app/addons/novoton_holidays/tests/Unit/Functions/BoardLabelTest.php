<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Functions;

use PHPUnit\Framework\TestCase;

/**
 * Pins the localized board label — "Demipensiune (HB +)": the Romanian
 * meal-plan name with the raw provider code in parens, the same format the
 * search card shows, now used by every novoton surface via
 * fn_novoton_holidays_format_board_name().
 *
 * The unknown-code contract (bare echo, NO parens) is load-bearing:
 * booking_form.php detects "formatter didn't change it" via strict equality
 * to trigger its board_name-parameter fallback.
 */
final class BoardLabelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/functions/formatting.php';
    }

    public function testSpacedProviderVariantResolves(): void
    {
        self::assertSame('Demipensiune (HB +)', fn_novoton_holidays_board_label('HB +'));
    }

    public function testCanonicalCodesGetNameWithCodeInParens(): void
    {
        self::assertSame('Demipensiune (HB)', fn_novoton_holidays_board_label('HB'));
        self::assertSame('Demipensiune (HB+)', fn_novoton_holidays_board_label('HB+'));
        self::assertSame('Pensiune Completă (FB)', fn_novoton_holidays_board_label('FB'));
        self::assertSame('Pensiune Completă (FB+)', fn_novoton_holidays_board_label('FB+'));
        self::assertSame('Mic Dejun Inclus (BB)', fn_novoton_holidays_board_label('BB'));
        self::assertSame('Doar Cameră (RO)', fn_novoton_holidays_board_label('RO'));
        self::assertSame('All Inclusive (AI)', fn_novoton_holidays_board_label('AI'));
        self::assertSame('Ultra All Inclusive (UAI)', fn_novoton_holidays_board_label('UAI'));
        self::assertSame('Self Catering (SC)', fn_novoton_holidays_board_label('SC'));
    }

    public function testLongFormAliasesResolve(): void
    {
        self::assertSame('All Inclusive (ALL INCL)', fn_novoton_holidays_board_label('ALL INCL'));
        self::assertSame('Doar Cameră (ROOM ONLY)', fn_novoton_holidays_board_label('ROOM ONLY'));
    }

    public function testOuterWhitespaceIsTrimmedFromTheShownCode(): void
    {
        self::assertSame('Self Catering (SC)', fn_novoton_holidays_board_label(' SC '));
    }

    public function testNameEqualToCodeIsNotDoubled(): void
    {
        // A raw value that IS the display name renders once, no parens.
        self::assertSame('All Inclusive', fn_novoton_holidays_board_label('All Inclusive'));
    }

    public function testUnknownCodeEchoesBareWithoutParens(): void
    {
        self::assertSame('XYZ', fn_novoton_holidays_board_label('XYZ'));
        self::assertSame('', fn_novoton_holidays_board_label('   '));
    }

    public function testWrapperDelegatesToTheLabel(): void
    {
        self::assertSame(
            fn_novoton_holidays_board_label('HB +'),
            fn_novoton_holidays_format_board_name('HB +'),
        );
    }
}
