<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData;

/**
 * The alias seed data extracted from func.php's fn_sphinx_holidays_seed_aliases
 * (its largest inert block). Shape + a few load-bearing mappings the
 * storefront labels depend on.
 */
final class SphinxAliasSeedDataTest extends TestCase
{
    public function testEveryAliasGroupMapsToNonEmptyCanonicalCodes(): void
    {
        $groups = [
            SphinxAliasSeedData::boardAliases(),
            SphinxAliasSeedData::roomAliases(),
            SphinxAliasSeedData::starAliases(),
            SphinxAliasSeedData::propertyTypeAliases(),
            SphinxAliasSeedData::hotelFacilityAliases(),
            SphinxAliasSeedData::roomFacilityAliases(),
        ];

        foreach ($groups as $aliases) {
            self::assertNotSame([], $aliases);
            foreach ($aliases as $canonical) {
                self::assertNotSame('', $canonical);
            }
        }
        // Beach group is a declared-but-empty extension point.
        self::assertSame([], SphinxAliasSeedData::beachAccessAliases());
    }

    public function testLoadBearingBoardMappingsSurviveTheExtraction(): void
    {
        $boards = SphinxAliasSeedData::boardAliases();

        // The Romanian storefront labels hang off these (Demipensiune arc).
        self::assertSame('HB', $boards['Demipensiune']);
        self::assertSame('FB', $boards['Pensiune completă']);
        self::assertSame('BB', $boards['Mic dejun']);
        self::assertSame('UAI', $boards['Ultra All Inclusive']);
    }

    public function testFuncPhpReadsTheDataClassInsteadOfInlineArrays(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');

        self::assertStringContainsString('SphinxAliasSeedData::boardAliases()', $func);
        self::assertStringContainsString('SphinxAliasSeedData::hotelFacilityAliases()', $func);
        // The big inline facility map is gone from func.php.
        self::assertStringNotContainsString("'124' => 'air_conditioning'", $func);
    }
}
