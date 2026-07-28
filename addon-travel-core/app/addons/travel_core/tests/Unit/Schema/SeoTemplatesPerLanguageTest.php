<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the per-language SEO template contract end to end.
 *
 * Templates used to be ONE language-less string set per provider, and the
 * engine wrote the SAME rendered text into every storefront language. Now:
 * template strings carry a per-language dimension ("<key>__<lang>" settings
 * with a stored-shared → built-in-language → built-in-base fallback chain),
 * every write path renders per language, and both providers' admin pages
 * edit one field set per storefront language through a SHARED component.
 * (Behaviour itself is covered by ApplySeoFieldsTest — this test keeps the
 * wiring from silently regressing to the single-language shape.)
 */
final class SeoTemplatesPerLanguageTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testEngineCarriesTheLanguageDimension(): void
    {
        $src = (string) file_get_contents(self::addonRoot() . '/functions/seo.php');

        self::assertStringContainsString(
            'function fn_travel_core_apply_seo_fields(string $addonName, array $placeholders, int $productId = 0, ?string $hotelId = null, string $langCode = \'\'): array',
            $src,
        );
        // Resolution chain lives in one helper — stored values are read for
        // the LANGUAGE KEY ONLY (no language-less stored fallback; fresh-addon
        // policy), defaults fall back __<lang> → base.
        self::assertStringContainsString('function _travel_core_seo_template_for(', $src);
        self::assertStringContainsString("\$stored = \$settings[\$templateKey . '__' . \$langCode] ?? '';", $src);
        self::assertStringContainsString("[\$templateKey . '__' . \$langCode, \$templateKey]", $src);
        // …and fill-if-empty inspects the RENDERED language's own values.
        self::assertStringContainsString('$productId,
            $langCode,', $src);

        // The per-product localizer + the admin form/save helpers.
        self::assertStringContainsString('function fn_travel_core_seo_localize(', $src);
        self::assertStringContainsString('function fn_travel_core_seo_lang_form_data(', $src);
        self::assertStringContainsString('function fn_travel_core_seo_save_lang_templates(', $src);
        // Bulk apply renders through the localizer (per language), not once.
        self::assertStringContainsString(
            '$wroteAny = fn_travel_core_seo_localize($addonName, $placeholders, $productId, $hotelId, $languages);',
            $src,
        );
    }

    public function testEveryWritePathRendersPerLanguage(): void
    {
        $sites = [
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Helpers/ProductFactory.php',
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/Commands/AddProductsCommand.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Helpers/SphinxProductFactory.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Cron/Commands/UpdateProductsCommand.php',
        ];
        foreach ($sites as $rel) {
            self::assertStringContainsString(
                'fn_travel_core_seo_localize(',
                (string) file_get_contents(self::repoRoot() . $rel),
                $rel,
            );
        }
    }

    public function testBothProvidersShipRomanianDefaults(): void
    {
        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/func.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/func.php',
        ] as $rel) {
            $src = (string) file_get_contents(self::repoRoot() . $rel);
            self::assertStringContainsString("'seo_meta_description__ro'", $src, $rel);
            self::assertStringContainsString("'seo_meta_keywords__ro'", $src, $rel);
        }
    }

    public function testBothAdminPagesEditPerLanguageThroughTheSharedComponent(): void
    {
        $component = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/backend/templates/addons/travel_core/components/seo_lang_fields.tpl',
        );
        self::assertStringContainsString('name="seo_lang[{$seo_lc}][{$f.key}]"', $component);
        self::assertStringContainsString('{foreach $seo_languages as $seo_lc => $seo_lang_name}', $component);
        // The toggles stay GLOBAL — one set gating every language.
        self::assertStringContainsString('name="seo[{$toggle_key}]"', $component);

        foreach ([
            '/addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/views/novoton_seo_templates/manage.tpl',
            '/addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/views/sphinx_seo_templates/manage.tpl',
        ] as $rel) {
            $tpl = (string) file_get_contents(self::repoRoot() . $rel);
            self::assertStringContainsString(
                '{include file="addons/travel_core/components/seo_lang_fields.tpl"}',
                $tpl,
                $rel,
            );
            // The language-less template fields must not return.
            self::assertStringNotContainsString('name="seo[seo_page_title]"', $tpl, $rel);
        }

        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/backend/novoton_seo_templates.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/backend/sphinx_seo_templates.php',
        ] as $rel) {
            $controller = (string) file_get_contents(self::repoRoot() . $rel);
            self::assertStringContainsString('fn_travel_core_seo_save_lang_templates(', $controller, $rel);
            self::assertStringContainsString('fn_travel_core_seo_lang_form_data(', $controller, $rel);
            // The global save loop must skip the six template keys — writing
            // '' there would erase the legacy/shared fallback values.
            self::assertStringContainsString('in_array($key, $templateKeys, true)', $controller, $rel);
        }
    }
}
