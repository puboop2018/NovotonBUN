<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Functions;

use PHPUnit\Framework\TestCase;

/**
 * Pins the display-boundary translation of two provider free-text fields that
 * SearchService surfaces on the search card and terms modal:
 *  - MoreInfo ("Beach included" → "Acces la plajă"), an exact-match map with
 *    raw pass-through for anything unrecognized;
 *  - remark ("MIN 2 NIGHTS STAY in <dates>" → "Minim 2 nopți de cazare în
 *    perioada <dates>"), a regex over the multi-line blob that leaves package
 *    names and dates intact.
 */
final class ProviderFreeTextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/functions/formatting.php';
    }

    public function testMoreInfoBeachIncludedIsLocalized(): void
    {
        self::assertSame('Acces la plajă', fn_novoton_holidays_more_info_label('Beach included'));
        // Case / spacing variants still resolve.
        self::assertSame('Acces la plajă', fn_novoton_holidays_more_info_label('  BEACH   included '));
    }

    public function testMoreInfoUnknownTextPassesThroughUnchanged(): void
    {
        self::assertSame('Transfer included', fn_novoton_holidays_more_info_label('Transfer included'));
        self::assertSame('', fn_novoton_holidays_more_info_label('   '));
    }

    public function testRemarkMinNightsStayIsLocalized(): void
    {
        self::assertSame(
            'Minim 2 nopți de cazare în perioada 25.04.2026 - 24.05.2026',
            fn_novoton_holidays_format_remark('MIN 2 NIGHTS STAY in 25.04.2026 - 24.05.2026'),
        );
    }

    public function testRemarkTranslatesEveryLineButLeavesTheHeaderAndDates(): void
    {
        $raw = "EDART *** +BEACH 2026 / 26.12.2025 - 31.10.2026\n"
            . "MIN 2 NIGHTS STAY in 25.04.2026 - 24.05.2026\n"
            . "MIN 5 NIGHTS STAY in 16.06.2026 - 31.08.2026";
        $expected = "EDART *** +BEACH 2026 / 26.12.2025 - 31.10.2026\n"
            . "Minim 2 nopți de cazare în perioada 25.04.2026 - 24.05.2026\n"
            . "Minim 5 nopți de cazare în perioada 16.06.2026 - 31.08.2026";

        self::assertSame($expected, fn_novoton_holidays_format_remark($raw));
    }

    public function testRemarkSingularNightAndCaseInsensitive(): void
    {
        self::assertSame(
            'Minim 1 nopți de cazare în perioada 01.09.2026 - 20.09.2026',
            fn_novoton_holidays_format_remark('min 1 night stay in 01.09.2026 - 20.09.2026'),
        );
    }

    public function testRemarkWithoutThePatternPassesThroughUnchanged(): void
    {
        self::assertSame('', fn_novoton_holidays_format_remark(''));
        self::assertSame(
            'Beach included / free parking',
            fn_novoton_holidays_format_remark('Beach included / free parking'),
        );
    }
}
