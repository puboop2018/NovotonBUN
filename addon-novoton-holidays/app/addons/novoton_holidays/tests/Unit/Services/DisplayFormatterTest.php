<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Services\DisplayFormatter;

/**
 * Behavior tests for the display formatting extracted from
 * functions/formatting.php — board labels, provider free-text localization,
 * hotel display names, dates and price rendering.
 */
final class DisplayFormatterTest extends TestCase
{
    public function testBoardLabelLocalizesKnownCodesAndKeepsTheRawCodeInParens(): void
    {
        self::assertSame('Demipensiune (HB +)', DisplayFormatter::boardLabel('HB +'));
        self::assertSame('Demipensiune (HB)', DisplayFormatter::boardLabel('HB'));
        self::assertSame('All Inclusive (AI)', DisplayFormatter::boardLabel('AI'));
        self::assertSame('All Inclusive (ALL INCL)', DisplayFormatter::boardLabel('ALL INCL'));
        self::assertSame('Ultra All Inclusive (UAI)', DisplayFormatter::boardLabel('UAI'));
        self::assertSame('Mic Dejun Inclus (BB)', DisplayFormatter::boardLabel('BB'));
    }

    public function testBoardLabelReturnsUnknownCodesBareForTheFallbackDetection(): void
    {
        // booking_form.php detects "formatter didn't change it" via strict
        // equality — "XYZ (XYZ)" would break that and read as noise.
        self::assertSame('XYZ', DisplayFormatter::boardLabel('XYZ'));
        self::assertSame('', DisplayFormatter::boardLabel('  '));
    }

    public function testResolveLangPrefersOverrideThenStorefrontThenRomanian(): void
    {
        self::assertSame('en', DisplayFormatter::resolveLang('EN', 'ro'));
        self::assertSame('bg', DisplayFormatter::resolveLang(null, 'BG'));
        self::assertSame('ro', DisplayFormatter::resolveLang(null, null));
        self::assertSame('ro', DisplayFormatter::resolveLang('', ''));
    }

    public function testMoreInfoLabelTranslatesOnlyOnTheRomanianStorefront(): void
    {
        self::assertSame('Acces la plajă', DisplayFormatter::moreInfoLabel('Beach included', 'ro'));
        self::assertSame('Acces la plajă', DisplayFormatter::moreInfoLabel('  beach   INCLUDED ', 'ro'));
        self::assertSame('Beach included', DisplayFormatter::moreInfoLabel('Beach included', 'en'));
        self::assertSame('Spa on site', DisplayFormatter::moreInfoLabel('Spa on site', 'ro'), 'unknown phrases pass through');
    }

    public function testFormatRemarkTranslatesTheMinimumStayPatternOnly(): void
    {
        self::assertSame(
            'Minim 2 nopți de cazare în perioada 25.04.2026 - 24.05.2026',
            DisplayFormatter::formatRemark('MIN 2 NIGHTS STAY in 25.04.2026 - 24.05.2026', 'ro'),
        );
        self::assertSame(
            'EDART *** +BEACH 2026 / Minim 5 nopți de cazare în perioada 01.06 - 01.07',
            DisplayFormatter::formatRemark('EDART *** +BEACH 2026 / MIN 5 NIGHT STAY in 01.06 - 01.07', 'ro'),
        );
        self::assertSame(
            'MIN 2 NIGHTS STAY in 25.04.2026',
            DisplayFormatter::formatRemark('MIN 2 NIGHTS STAY in 25.04.2026', 'en'),
            'non-RO storefronts keep the provider wording',
        );
    }

    public function testNormalizeResortNameCollapsesPunctuationVariants(): void
    {
        self::assertSame(
            DisplayFormatter::normalizeResortName('ST.CONSTANTINE & ELENA'),
            DisplayFormatter::normalizeResortName('ST. CONSTANTINE AND ELENA'),
        );
        self::assertSame('GOLDEN SANDS', DisplayFormatter::normalizeResortName("  golden-sands  "));
    }

    public function testFormatDateConvertsCsCartStrftimeFormats(): void
    {
        self::assertSame('02.08.2026', DisplayFormatter::formatDate('2026-08-02', '%d.%m.%Y'));
        self::assertSame('08/02/2026', DisplayFormatter::formatDate('2026-08-02', '%m/%d/%Y'));
        // Default fallback format when the setting is empty.
        self::assertSame('02.08.2026', DisplayFormatter::formatDate('2026-08-02'));
        // Timestamps work; garbage passes through unchanged.
        self::assertSame('02.08.2026', DisplayFormatter::formatDate((int) mktime(12, 0, 0, 8, 2, 2026)));
        self::assertSame('not-a-date', DisplayFormatter::formatDate('not-a-date'));
        self::assertSame('', DisplayFormatter::formatDate(''));
    }

    public function testFormatHotelDisplayNameTitleCasesAndAppendsTypeForShortNames(): void
    {
        $detector = new PropertyTypeDetector();

        // Short name without a type keyword gains the detected type label.
        self::assertSame('Edart Hotel', DisplayFormatter::formatHotelDisplayName('EDART', 'hotel', $detector));
        self::assertSame('Blue Bay Resort', DisplayFormatter::formatHotelDisplayName('BLUE BAY', 'resort', $detector));
        // Unknown detected type falls back to Hotel.
        self::assertSame('Edart Hotel', DisplayFormatter::formatHotelDisplayName('EDART', '', $detector));

        // A name that already contains a type keyword is only Title Cased.
        self::assertSame('Edart Hotel', DisplayFormatter::formatHotelDisplayName('EDART HOTEL', 'resort', $detector));

        // 3+ word names stay as-is (Title Cased) with no appended type.
        self::assertSame('Grand Blue Bay', DisplayFormatter::formatHotelDisplayName('GRAND BLUE BAY', 'hotel', $detector));

        // Roman numerals survive Title Case.
        self::assertStringContainsString('II', DisplayFormatter::formatHotelDisplayName('MELIA II', 'hotel', $detector));
    }

    public function testBuildHotelTitleAssemblesNameLocationAndYear(): void
    {
        self::assertSame(
            'Edart Hotel - Sunny Beach, Bulgaria 2026',
            DisplayFormatter::buildHotelTitle('EDART HOTEL', 'SUNNY BEACH', 'BULGARIA', 2026),
        );
        self::assertSame('Edart Hotel', DisplayFormatter::buildHotelTitle('EDART HOTEL', '', '', ''));
    }

    public function testFormatPriceRoundsWhenTheSettingIsOnAndRendersSupDecimalsOtherwise(): void
    {
        // Round hotel prices ON: integer with dot thousands separator.
        self::assertSame('2.853', DisplayFormatter::formatPrice(2853.4, 1.0, '', true));
        self::assertSame('453 £', DisplayFormatter::formatPrice(452.73, 1.0, '£', true));

        // OFF: meaningful decimals render as a superscript pair.
        self::assertSame('7.419<sup class="price-decimal">99</sup>', DisplayFormatter::formatPrice(7419.99, 1.0, '', false));
        self::assertSame('2.853', DisplayFormatter::formatPrice(2853.0, 1.0, '', false), 'whole numbers stay plain');

        // Coefficient converts before formatting; symbol appends.
        self::assertSame('906 £', DisplayFormatter::formatPrice(453.0, 2.0, '£', true));
    }
}
