<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the design-token bridge (travel_core styles.less).
 *
 * The bridge is the ONLY source of the --nvt-* CSS custom properties every
 * travel stylesheet consumes. It moved here from novoton_holidays (2026-07)
 * so tokens exist whichever provider addon is enabled; before the move,
 * sphinx-only shops had no token definitions, and a scoped token block in
 * search-results.css shadowed the bridge on results pages — silently breaking
 * Theme Editor colors and theme-preset adaptivity there.
 */
final class DesignTokensTest extends TestCase
{
    private const THEMES = ['responsive', 'nova_theme'];

    private const BRIDGE = 'design/themes/%s/css/addons/travel_core/styles.less';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function read(string $relPath): string
    {
        $path = self::repoRoot() . '/' . $relPath;
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        return $content;
    }

    /** Strip // and slash-star comments so token names in prose don't count. */
    private static function stripLineComments(string $css): string
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        return (string) preg_replace('~//[^\n]*~', '', $css);
    }

    /** @return list<string> custom properties DECLARED (e.g. --nvt-primary) */
    private static function declaredTokens(string $css): array
    {
        preg_match_all('~(--nvt-[a-z0-9-]+)\s*:~', self::stripLineComments($css), $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> custom properties CONSUMED via var(--nvt-…) */
    private static function consumedTokens(string $css): array
    {
        preg_match_all('~var\(\s*(--nvt-[a-z0-9-]+)~', self::stripLineComments($css), $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> travel_core css/less files of one theme (relative paths) */
    private static function themeStylesheets(string $theme): array
    {
        $dir = self::repoRoot() . "/addon-travel-core/design/themes/$theme/css/addons/travel_core";
        $files = glob("$dir/*.{css,less}", GLOB_BRACE) ?: [];
        self::assertNotSame([], $files, "no stylesheets found for theme $theme");
        $root = self::repoRoot() . '/';
        return array_map(static fn (string $f): string => substr($f, strlen($root)), $files);
    }

    public function testEveryConsumedTokenIsEmittedByTheBridge(): void
    {
        $bridge = self::read('addon-travel-core/' . sprintf(self::BRIDGE, 'responsive'));
        $declared = self::declaredTokens($bridge);
        self::assertNotSame([], $declared, 'bridge must declare --nvt-* tokens');

        $missing = [];
        foreach (self::themeStylesheets('responsive') as $file) {
            foreach (self::consumedTokens(self::read($file)) as $token) {
                if (!in_array($token, $declared, true)) {
                    $missing[$token][] = basename($file);
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'tokens consumed but not emitted by the :root bridge in styles.less: '
                . json_encode($missing),
        );
    }

    public function testThemeMirrorsAreIdentical(): void
    {
        foreach (self::themeStylesheets('responsive') as $file) {
            $mirror = str_replace('/responsive/', '/nova_theme/', $file);
            self::assertSame(
                self::read($file),
                self::read($mirror),
                "theme mirror drifted: $mirror",
            );
        }
    }

    public function testBridgeIsRegisteredFirstInBothThemes(): void
    {
        foreach (self::THEMES as $theme) {
            $tpl = self::read(
                "addon-travel-core/design/themes/$theme/templates/addons/travel_core/hooks/index/styles.post.tpl",
            );
            preg_match_all('~\{style\s+src="([^"]+)"~', $tpl, $m);
            self::assertNotSame([], $m[1], "$theme styles.post.tpl must register styles");
            self::assertSame(
                'addons/travel_core/styles.less',
                $m[1][0],
                "$theme: the token bridge must be the FIRST registered style — "
                    . 'every other sheet consumes its custom properties',
            );
        }
    }

    /**
     * Only the bridge may DEFINE --nvt-* custom properties. A definition in a
     * plain .css file (especially a scoped one) shadows the bridge and breaks
     * Theme Editor / theme-preset adaptivity — that exact bug shipped once in
     * search-results.css.
     */
    public function testNoPlainCssFileDefinesTokens(): void
    {
        $offenders = [];
        foreach (self::themeStylesheets('responsive') as $file) {
            if (str_ends_with($file, '.less')) {
                continue;
            }
            $declared = self::declaredTokens(self::read($file));
            if ($declared !== []) {
                $offenders[basename($file)] = $declared;
            }
        }

        self::assertSame([], $offenders, 'css files must consume tokens, never define them: '
            . json_encode($offenders));
    }

    /**
     * The primary "book"/"search" button color contract lives in ONE place —
     * the shared .travel-btn--primary rule in booking-engine.css. The three
     * button contexts (engine search button, results book button, forms book
     * button) used to each declare their own background/hover and drifted in
     * blue shade. Guard: only booking-engine.css may set the button
     * background; the results/forms rules must be box-model only.
     */
    public function testPrimaryButtonColorIsCentralised(): void
    {
        $engine = self::read('addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-engine.css');
        self::assertStringContainsString(
            '.travel-btn--primary',
            $engine,
            'booking-engine.css must define the canonical .travel-btn--primary contract',
        );
        self::assertMatchesRegularExpression(
            '~\.travel-btn--primary[^{]*\{[^}]*--nvt-search-btn-bg~s',
            $engine,
            'the shared button contract must set background from --nvt-search-btn-bg',
        );

        // The per-context book-button rules must NOT re-declare the brand color.
        foreach (['search-results.css', 'booking-pages.css'] as $sheet) {
            $css = self::read("addon-travel-core/design/themes/responsive/css/addons/travel_core/$sheet");
            preg_match_all(
                '~\.travel-offer-book-btn[^{]*\{([^}]*)\}~s',
                $css,
                $blocks,
            );
            foreach ($blocks[1] as $body) {
                self::assertStringNotContainsString(
                    'search-btn-bg',
                    $body,
                    "$sheet: .travel-offer-book-btn must not re-declare the button "
                        . 'background — it comes from the shared .travel-btn--primary contract',
                );
            }
        }
    }

    public function testNovotonNoLongerEmitsTheBridge(): void
    {
        foreach (self::THEMES as $theme) {
            $less = self::read(
                "addon-novoton-holidays/design/themes/$theme/css/addons/novoton_holidays/styles.less",
            );
            self::assertSame(
                [],
                self::declaredTokens($less),
                "$theme: novoton styles.less must not declare --nvt-* tokens "
                    . '(the bridge lives in travel_core now)',
            );
            self::assertDoesNotMatchRegularExpression(
                '~^\s*:root\s*\{~m',
                self::stripLineComments($less),
                "$theme: novoton styles.less must not emit a :root block",
            );
        }
    }

    public function testThemeEditorPickersBindToDefinedLessVariables(): void
    {
        $schemaPath = self::repoRoot()
            . '/addon-travel-core/app/addons/travel_core/schemas/theme_editor/schema.post.php';
        self::assertFileExists($schemaPath);
        $schema = self::read('addon-travel-core/app/addons/travel_core/schemas/theme_editor/schema.post.php');

        preg_match_all("~'variable_name'\s*=>\s*'([a-z0-9-]+)'~", $schema, $m);
        self::assertNotSame([], $m[1], 'theme_editor schema must register color pickers');

        $bridge = self::stripLineComments(
            self::read('addon-travel-core/' . sprintf(self::BRIDGE, 'responsive')),
        );
        foreach ($m[1] as $variable) {
            self::assertMatchesRegularExpression(
                '~@' . preg_quote($variable, '~') . '\s*:~',
                $bridge,
                "picker variable '$variable' has no LESS variable definition — dead picker "
                    . '(this is how novoton-cal-cheapest-bg shipped broken)',
            );
        }

        // Labels must live in travel_core so they survive a novoton uninstall.
        preg_match_all("~'description'\s*=>\s*'([a-z0-9_.]+)'~", $schema, $d);
        foreach (['en', 'ro'] as $lang) {
            $po = self::read("addon-travel-core/var/langs/$lang/addons/travel_core.po");
            foreach ($d[1] as $labelKey) {
                self::assertStringContainsString(
                    "Languages::$labelKey\"",
                    $po,
                    "picker label '$labelKey' missing from travel_core $lang .po",
                );
            }
        }
    }

    public function testNovotonDoesNotRegisterThemeEditorPickers(): void
    {
        self::assertFileDoesNotExist(
            self::repoRoot()
                . '/addon-novoton-holidays/app/addons/novoton_holidays/schemas/theme_editor/schema.post.php',
            'theme_editor pickers moved to travel_core — a novoton copy would double-register them',
        );
    }
}
