<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "Travel Core Template" product-detail template
 * (blocks/product_templates/travelcore_template.tpl):
 *
 *  - CS-Cart lists it in Settings → Appearance → "Product detailed page
 *    view" purely by file placement, labelled by the BARE lang var named
 *    after the file — so the file location AND the seeded label are load-
 *    bearing.
 *  - Its one structural change vs the stock default_template: the
 *    products:main_info_title hook region (title + brand) renders inside a
 *    full-width .travel-pdp-header ABOVE the image gallery — which is what
 *    puts the hotel name and the travel_core location line
 *    (main_info_title.post.tpl) at the top, left-aligned. The hook must
 *    appear exactly ONCE: a second occurrence would render every addon's
 *    title-area contribution (location line, review stars, …) twice.
 */
final class TravelcoreProductTemplateTest extends TestCase
{
    private const REL = 'templates/addons/travel_core/blocks/product_templates/travelcore_template.tpl';

    private static function themePath(string $theme): string
    {
        return dirname(__DIR__, 6) . '/design/themes/' . $theme . '/' . self::REL;
    }

    public function testTemplateExistsInBothThemesByteIdentical(): void
    {
        $responsive = self::themePath('responsive');
        $nova = self::themePath('nova_theme');
        self::assertFileExists($responsive);
        self::assertFileExists($nova, 'run composer mirror');
        self::assertSame(
            (string) file_get_contents($responsive),
            (string) file_get_contents($nova),
            'nova_theme copy must be byte-identical (composer mirror)',
        );
    }

    public function testHeaderRendersTheTitleHookOnceAboveTheGallery(): void
    {
        $src = (string) file_get_contents(self::themePath('responsive'));

        self::assertSame(
            1,
            substr_count($src, '{hook name="products:main_info_title"}'),
            'the title hook moved — it must never be duplicated',
        );

        $header = strpos($src, '<div class="travel-pdp-header">');
        $hook = strpos($src, '{hook name="products:main_info_title"}');
        $title = strpos($src, 'ty-product-block-title');
        $travelColumns = strpos($src, '<div class="travel-pdp-columns">');
        $gallery = strpos($src, 'ty-product-block__img-wrapper');
        $left = strpos($src, 'ty-product-block__left');
        $columns = strpos($src, 'ty-product-block__columns-wrapper');

        self::assertNotFalse($header);
        self::assertNotFalse($hook);
        self::assertNotFalse($title);
        self::assertNotFalse($travelColumns);
        self::assertNotFalse($gallery);
        self::assertNotFalse($left);
        self::assertNotFalse($columns);

        self::assertGreaterThan($header, $hook, 'hook lives inside the header');
        self::assertGreaterThan($hook, $title, 'h1 renders inside the hook');
        // Row order: header, then OUR columns container wrapping gallery +
        // info column — the theme's wrapper rules only reach direct children,
        // so both columns must live inside .travel-pdp-columns.
        self::assertLessThan($travelColumns, $header, 'header precedes the columns container');
        self::assertLessThan($gallery, $travelColumns, 'gallery lives inside .travel-pdp-columns');
        self::assertLessThan($left, $gallery, 'gallery precedes the info column');
        self::assertLessThan($columns, $left, 'info column wraps the stock columns-wrapper');

        // The product code moved into the header, right of the title — the
        // sku capture carries element ids, so it must render exactly once,
        // between the title hook and the columns container.
        self::assertSame(
            1,
            substr_count($src, '{$smarty.capture.$sku nofilter}'),
            'the sku capture renders once (moved, not copied)',
        );
        $code = strpos($src, '<div class="travel-pdp-header__code ty-product-block__sku">');
        self::assertNotFalse($code);
        self::assertGreaterThan($hook, $code, 'code box sits after the title hook');
        self::assertLessThan($travelColumns, $code, 'code box lives inside the header row');
    }

    public function testReserveButtonReplacesAddToCartWheneverTemplateIsSelected(): void
    {
        $src = (string) file_get_contents(self::themePath('responsive'));

        // Selecting the template is the whole switch: like default/bigpicture,
        // its behavior must not depend on runtime state. The old hotel gate
        // ($travel_booking_product_id) must not return — only the PHP-side
        // booking-form injection stays hotel-gated.
        self::assertStringNotContainsString('$travel_is_hotel_pdp', $src);
        self::assertStringContainsString(
            '<div class="ty-product-block__button travel-pdp-reserve-mode">',
            $src,
            'reserve mode is unconditional on this template',
        );
        // Same spot, theme-primary styling, anchors to the availability widget.
        self::assertStringContainsString(
            '<a href="#travel-booking-root" class="ty-btn ty-btn__primary ty-btn__big travel-pdp-reserve-btn">{__("travel_core.reserve_now")}</a>',
            $src,
        );

        // Lang key seeded through every parity-checked source.
        $addonRoot = dirname(__DIR__, 3);
        $langKeys = require $addonRoot . '/lang_keys.php';
        self::assertSame('Reserve', $langKeys['travel_core.reserve_now']['en'] ?? null);
        self::assertSame('Rezervați acum', $langKeys['travel_core.reserve_now']['ro'] ?? null);
        $xml = (string) file_get_contents($addonRoot . '/addon.xml');
        self::assertSame(2, substr_count($xml, 'id="travel_core.reserve_now"'));
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(dirname(__DIR__, 6) . '/var/langs/' . $lang . '/addons/travel_core.po');
            self::assertStringContainsString('msgctxt "Languages::travel_core.reserve_now"', $po, $lang . '.po');
        }

        // CSS contract: the stock submit hides ONLY under the modifier; the
        // reserve button fills the card; the anchor target clears sticky
        // headers; the code value is regular weight.
        foreach (['responsive', 'nova_theme'] as $theme) {
            $css = (string) file_get_contents(
                dirname(__DIR__, 6) . '/design/themes/' . $theme . '/css/addons/travel_core/booking-pages.css',
            );
            self::assertStringContainsString('.travel-pdp-reserve-mode .ty-btn__add-to-cart', $css, $theme);
            self::assertStringContainsString('.travel-pdp-reserve-btn {', $css, $theme);
            self::assertStringContainsString('scroll-margin-top', $css, $theme);
            self::assertStringContainsString(
                ".travel-pdp-header__code .ty-control-group__item {\n    font-weight: 400;",
                $css,
                $theme . ': code value must be regular weight',
            );
        }
    }

    public function testCatalogPriceAndQtyStayHidden(): void
    {
        $src = (string) file_get_contents(self::themePath('responsive'));

        // Hotel pricing is date-driven: the cached catalog price (#430), the
        // old-price strike-through (#440) and the qty stepper (#490) must
        // not render, on the page or via the sticky bottom bar.
        self::assertStringNotContainsString('{$smarty.capture.$price nofilter}', $src);
        self::assertStringNotContainsString('{$smarty.capture.$old_price nofilter}', $src);
        self::assertStringNotContainsString('{$smarty.capture.$qty nofilter}', $src);
        self::assertStringContainsString('$show_price_product_bottom_fixed|default:false', $src);
        self::assertStringContainsString('$show_list_price_product_bottom_fixed|default:false', $src);
    }

    public function testHeaderCssShipsInBothThemeStylesheets(): void
    {
        foreach (['responsive', 'nova_theme'] as $theme) {
            $css = (string) file_get_contents(
                dirname(__DIR__, 6) . '/design/themes/' . $theme . '/css/addons/travel_core/booking-pages.css',
            );
            self::assertStringContainsString('.travel-pdp-header {', $css, $theme);
            self::assertStringContainsString('.travel-pdp-columns {', $css, $theme);
            self::assertStringContainsString('.travel-pdp-header__code {', $css, $theme);
        }
    }

    public function testDropdownLabelIsSeededWithPoParity(): void
    {
        $addonRoot = dirname(__DIR__, 3);

        $langKeys = require $addonRoot . '/lang_keys.php';
        self::assertIsArray($langKeys);
        self::assertArrayHasKey(
            'travelcore_template',
            $langKeys,
            'the BARE filename key labels the Appearance dropdown entry',
        );
        self::assertSame('Travel Core Template', $langKeys['travelcore_template']['en'] ?? null);

        $repoAddonRoot = dirname(__DIR__, 6);
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents($repoAddonRoot . '/var/langs/' . $lang . '/addons/travel_core.po');
            self::assertStringContainsString('msgctxt "Languages::travelcore_template"', $po, $lang . '.po');
        }

        // Belt and braces: the key also ships in addon.xml language_variables
        // (install-time import + it wins in the runtime seeder union), with
        // the IDENTICAL value so the .po parity rule holds.
        $xml = (string) file_get_contents($addonRoot . '/addon.xml');
        self::assertSame(
            2,
            substr_count($xml, 'id="travelcore_template"'),
            'en + ro language_variables entries in addon.xml',
        );

        // The seeder must reach installed stores from ANY area: the old
        // admin-only gate left storefront visitors staring at raw keys until
        // someone opened an admin page after a deploy.
        $init = (string) file_get_contents($addonRoot . '/init.php');
        $langBlock = strpos($init, "if (function_exists('fn_travel_core_seed_language_keys')) {");
        self::assertNotFalse($langBlock, 'lang self-heal must not be admin-gated');
    }
}
