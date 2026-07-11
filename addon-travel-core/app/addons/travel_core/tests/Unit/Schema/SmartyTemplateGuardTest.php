<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Smarty 5 safety guards for travel_core's OWN design templates.
 *
 * Novoton carries an equivalent scan (SmartyCompatTest) for its templates,
 * but travel_core templates — including the admin travel_bookings view whose
 * "Not matching {capture}{/capture}" crash resurfaces whenever a stale
 * pre-fix compiled copy is served from var/cache — had no guard at all.
 * The current view.tpl is proven to render cleanly under stock Smarty 5;
 * these tests keep every travel_core template in that state so the only
 * remaining cause of that error class is a stale compiled template
 * (fix: admin → Clear cache, per docs/INSTALL.md).
 */
final class SmartyTemplateGuardTest extends TestCase
{
    /**
     * {capture}/{/capture} must stay balanced in every template (Smarty
     * comments stripped first). An imbalance surfaces at render end as
     * "Not matching {capture}{/capture}".
     */
    public function testTemplatesKeepCapturesBalanced(): void
    {
        $offenders = [];
        foreach (self::designTemplates() as $path) {
            $source = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($path));
            $opens = preg_match_all('/\{capture\b/', $source);
            $closes = preg_match_all('~\{/capture\}~', $source);
            if ($opens !== $closes) {
                $offenders[] = self::rel($path) . " ({$opens} open / {$closes} close)";
            }
        }

        self::assertNotEmpty(
            self::designTemplates(),
            'expected travel_core design templates to scan',
        );
        self::assertSame([], $offenders, 'templates with unbalanced {capture} blocks');
    }

    /**
     * {json_decode(...)} in a template is a stock-Smarty-5 CompilerException —
     * the whole page dies at compile time regardless of the branch it sits in.
     * Decode in the controller and pass prepared values instead.
     */
    public function testNoStockFatalConstructsInTemplates(): void
    {
        $offenders = [];
        foreach (self::designTemplates() as $path) {
            $source = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($path));
            if (str_contains($source, 'json_decode(')) {
                $offenders[] = self::rel($path);
            }
        }

        self::assertSame([], $offenders, 'templates calling json_decode() — prepare the value in PHP instead');
    }

    /**
     * Arithmetic on a foreach KEY variable ({$idx+1} with key=idx) is a latent
     * PHP 8 TypeError: the moment the iterated array is keyed by strings
     * (sphinx guests_json: "room1_adult_1" => {…}), "room1_adult_1" + 1 throws
     * inside the open {capture} and Smarty masks it as "Not matching
     * {capture}{/capture}" — the exact production crash on
     * travel_bookings.view. Use {foreach … name=n} +
     * {$smarty.foreach.n.iteration} for display counters instead.
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
                    $offenders[] = self::rel($path) . ' ($' . $keyVar . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'templates doing arithmetic on a foreach KEY (string keys throw under PHP 8) — use {foreach … name=n} + {$smarty.foreach.n.iteration}',
        );
    }

    /** @return list<string> every travel_core .tpl (backend + both storefront themes) */
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

    private static function rel(string $path): string
    {
        $root = dirname(__DIR__, 6) . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
