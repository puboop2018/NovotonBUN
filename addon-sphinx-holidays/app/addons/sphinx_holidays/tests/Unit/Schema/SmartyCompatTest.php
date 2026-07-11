<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * CS-Cart 4.19.1+ ships Smarty 5, where the engine class is \Smarty\Smarty
 * and the global \Smarty class no longer exists. Any `instanceof \Smarty`
 * guard is therefore always false — it silently disables the guarded code
 * (regression: the product-page booking form, hotel JSON-LD, and the debug
 * panel all vanished because their view assignments sat behind such guards).
 * Duck-type instead: is_object($view) && method_exists($view, 'assign').
 */
final class SmartyCompatTest extends TestCase
{
    public function testNoAlwaysFalseGlobalSmartyInstanceofChecks(): void
    {
        $addonRoot = dirname(__DIR__, 3);
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($addonRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/instanceof\s+\\\\?Smarty\b(?!\\\\)/', $source)) {
                $offenders[] = substr($path, strlen($addonRoot) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Files checking `instanceof \Smarty` — always false on Smarty 5 (CS-Cart >= 4.19.1); duck-type the view instead',
        );
    }

    /**
     * Every design template must keep {capture}/{/capture} balanced (after
     * stripping Smarty comments). An imbalance surfaces at runtime as
     * "Not matching {capture}{/capture}" — the error the admin booking view
     * produced whenever a stale/broken template was in play.
     */
    public function testTemplatesKeepCapturesBalanced(): void
    {
        $offenders = [];
        foreach (self::designTemplates() as $path) {
            $source = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($path));
            $opens = preg_match_all('/\{capture\b/', $source);
            $closes = preg_match_all('~\{/capture\}~', $source);
            if ($opens !== $closes) {
                $offenders[] = basename($path) . " ({$opens} open / {$closes} close)";
            }
        }

        self::assertSame([], $offenders, 'templates with unbalanced {capture} blocks');
    }

    /**
     * {json_decode(...)} in a template is a stock-Smarty-5 CompilerException —
     * the whole page dies at compile time regardless of the branch it sits in.
     * Decode/encode in the controller and pass prepared values instead.
     * (is_string()/is_array() guards are allowed: CS-Cart's engine whitelists
     * them, proven by the live cart/checkout pages that use them.)
     */
    public function testNoStockFatalConstructsInTemplates(): void
    {
        $offenders = [];
        foreach (self::designTemplates() as $path) {
            $source = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($path));
            if (str_contains($source, 'json_decode(')) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame([], $offenders, 'templates calling json_decode() — prepare the value in PHP instead');
    }

    /**
     * Arithmetic on a foreach KEY variable ({$idx+1} with key=idx) is a latent
     * PHP 8 TypeError: the moment the iterated array is keyed by strings
     * (sphinx guests_json: "room1_adult_1" => {…}), "room1_adult_1" + 1 throws
     * inside the open {capture} and Smarty masks it as "Not matching
     * {capture}{/capture}" — the production crash on travel_bookings.view.
     * Use {foreach … name=n} + {$smarty.foreach.n.iteration} instead.
     */
    public function testNoArithmeticOnForeachKeyVariables(): void
    {
        $offenders = [];
        foreach (self::designTemplates() as $path) {
            $source = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($path));

            $keyVars = [];
            if (preg_match_all('/\{foreach\b[^}]*\bkey\s*=\s*["\']?\$?(\w+)/', $source, $m)) {
                $keyVars = $m[1];
            }
            if (preg_match_all('/\{foreach\s+[^}]*\bas\s+\$(\w+)\s*=>/', $source, $m)) {
                $keyVars = array_merge($keyVars, $m[1]);
            }

            foreach (array_unique($keyVars) as $keyVar) {
                if (preg_match('/\{\$' . preg_quote($keyVar, '/') . '\s*[+\-*\/]/', $source)) {
                    $offenders[] = basename($path) . ' ($' . $keyVar . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'templates doing arithmetic on a foreach KEY (string keys throw under PHP 8) — use {foreach … name=n} + {$smarty.foreach.n.iteration}',
        );
    }

    /** @return list<string> */
    private static function designTemplates(): array
    {
        $designRoot = dirname(__DIR__, 6) . '/design';
        if (!is_dir($designRoot)) {
            return [];
        }
        $templates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($designRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'tpl') {
                $templates[] = $file->getPathname();
            }
        }
        return $templates;
    }
}
