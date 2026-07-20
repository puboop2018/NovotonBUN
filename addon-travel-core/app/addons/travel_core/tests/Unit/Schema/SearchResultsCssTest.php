<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the search-results stylesheet: the two theme copies must stay
 * byte-identical (ThemeMirrorTest only walks .tpl files — CSS had no mirror
 * guard), the terms link renders on its OWN line under the instant-
 * confirmation badge, and the terms-modal timeline classes exist for
 * renderTimeline() in the sphinx search template.
 */
final class SearchResultsCssTest extends TestCase
{
    private static function css(string $theme): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/' . $theme . '/css/addons/travel_core/search-results.css';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testThemeCopiesAreByteIdentical(): void
    {
        self::assertSame(self::css('responsive'), self::css('nova_theme'));
    }

    public function testTermsLinkSitsOnItsOwnLineHuggingItsText(): void
    {
        $css = self::css('responsive');

        $rule = self::extractRule($css, '.travel-search-results-page .travel-terms-link');
        // block = own line under the badge; fit-content = the dashed
        // underline hugs the text instead of spanning the column.
        self::assertStringContainsString('display: block', $rule);
        self::assertStringContainsString('width: fit-content', $rule);
        self::assertStringNotContainsString('inline-block', $rule);
    }

    public function testTimelineClassesExistForTheTermsModal(): void
    {
        $css = self::css('responsive');

        foreach ([
            '.travel-terms-timeline__heading',
            '.travel-terms-timeline__node',
            '.travel-terms-timeline__row',
            '.travel-terms-timeline__row--cancel',
            '.travel-terms-timeline__tag--nonref',
        ] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
    }

    public function testHotelHeaderTypographyDefersToThePdpTitleClass(): void
    {
        $css = self::css('responsive');

        $rule = self::extractRule($css, '.travel-search-results-page .travel-hotel-header h1');
        self::assertStringContainsString('margin: 0', $rule);
        foreach (['font-size', 'font-weight', 'color'] as $prop) {
            self::assertStringNotContainsString(
                $prop,
                $rule,
                "{$prop} must come from ty-product-block-title (PDP parity), not this rule",
            );
        }
    }

    public function testLocationLineAndMapLinkMatchThePdpSpec(): void
    {
        $css = self::css('responsive');

        // main_info_title.post.tpl inline spec: 14px / 1.4 / normal / #666 / 4px gap.
        $rule = self::extractRule($css, '.travel-search-results-page .travel-hotel-location');
        self::assertStringContainsString('margin: 4px 0 0', $rule);
        self::assertStringContainsString('font-size: 14px', $rule);
        self::assertStringContainsString('line-height: 1.4', $rule);
        self::assertStringContainsString('font-weight: normal', $rule);
        self::assertStringContainsString('color: #666', $rule);

        $mapRule = self::extractRule($css, '.travel-search-results-page .travel-hotel-map-link');
        self::assertStringContainsString('font-size: 13px', $mapRule);
        self::assertStringNotContainsString(
            'margin-left',
            $mapRule,
            'spacing comes from the " - " separator in the markup, like the PDP',
        );
    }

    /** The body of the FIRST css block whose selector line contains $selector. */
    private static function extractRule(string $css, string $selector): string
    {
        $start = strpos($css, $selector);
        self::assertNotFalse($start, "selector not found: {$selector}");
        $open = strpos($css, '{', $start);
        $close = strpos($css, '}', (int) $open);
        self::assertNotFalse($open);
        self::assertNotFalse($close);

        return substr($css, (int) $open, (int) $close - (int) $open);
    }
}
