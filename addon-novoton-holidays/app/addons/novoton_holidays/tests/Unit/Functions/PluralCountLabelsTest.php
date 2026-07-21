<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Functions;

use PHPUnit\Framework\TestCase;

/**
 * The availability-badge counts use CS-Cart plural forms
 * ("[n] singular|[n] plural") so Romanian renders "1 ofertă" (singular) vs
 * "2 oferte" (plural) correctly — the previous {if count==1} conditionals
 * were replaced. The keys are seeded through lang_keys.php (novoton gained a
 * self-seeder) so they reach already-installed stores, not just fresh ones.
 */
final class PluralCountLabelsTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private static function langKeys(): array
    {
        $vars = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertIsArray($vars);

        return $vars;
    }

    public function testOffersKeyHasRomanianSingularAndPluralForms(): void
    {
        $vars = self::langKeys();

        self::assertArrayHasKey('novoton_holidays.n_offers', $vars);
        // Two [n] forms separated by "|": singular first (n==1), plural second.
        self::assertSame('[n] Ofertă|[n] Oferte', $vars['novoton_holidays.n_offers']['ro']);
        self::assertSame('[n] Offer|[n] Offers', $vars['novoton_holidays.n_offers']['en']);

        foreach (['n_rooms', 'n_adults', 'n_children', 'n_offers'] as $k) {
            $key = 'novoton_holidays.' . $k;
            self::assertArrayHasKey($key, $vars, "{$key} must be seeded for the badge counts");
            self::assertStringContainsString('|', $vars[$key]['ro'], "{$key} must carry two plural forms");
            self::assertStringContainsString('[n]', $vars[$key]['ro']);
        }
    }

    public function testNovotonHasALanguageSelfSeeder(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        self::assertStringContainsString('function fn_novoton_holidays_seed_language_keys(', $func);
        self::assertStringContainsString('function fn_novoton_holidays_language_seed_hash(', $func);

        // init.php reseeds on an admin load when the hash changes.
        $init = (string) file_get_contents(dirname(__DIR__, 3) . '/init.php');
        self::assertStringContainsString('fn_novoton_holidays_seed_language_keys()', $init);
        self::assertStringContainsString("AREA === 'A'", $init);
        self::assertStringContainsString('novoton_holidays._lang_seed_hash', $init);
    }
}
