<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Every table this addon creates must be dropped by BOTH uninstall paths:
 *
 *  1. addon.xml `<item for="uninstall">` SQL — the fallback that runs even
 *     when the PHP uninstall hook never fires (a live store once kept all
 *     sphinx tables because only the PHP path existed and it didn't run);
 *  2. the PHP uninstall function — the richer cleanup that also handles
 *     guarded cross-addon rows.
 */
final class UninstallCompletenessTest extends TestCase
{
    private const PHP_UNINSTALL_SOURCE = '/func.php';

    /** @return array{created: list<string>, xmlDrops: list<string>} */
    private static function parseAddonXml(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);

        $created = [];
        $xmlDrops = [];
        foreach ($xml->xpath('//queries/item') ?: [] as $item) {
            $for = (string) ($item['for'] ?? '');
            $sql = (string) $item;
            if (($for === '' || $for === 'install')
                && preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?\?:(\w+)`?/i', $sql, $m)
            ) {
                $created[] = $m[1];
            }
            if ($for === 'uninstall' && preg_match('/DROP TABLE IF EXISTS\s+`?\?:(\w+)`?/i', $sql, $m)) {
                $xmlDrops[] = $m[1];
            }
        }

        return ['created' => $created, 'xmlDrops' => $xmlDrops];
    }

    public function testEveryCreatedTableIsDroppedByAddonXmlFallback(): void
    {
        ['created' => $created, 'xmlDrops' => $xmlDrops] = self::parseAddonXml();

        self::assertNotEmpty($created, 'sanity: addon.xml must create tables');
        self::assertSame(
            [],
            array_values(array_diff($created, $xmlDrops)),
            'created but missing from addon.xml <item for="uninstall"> DROPs (the PHP-independent fallback)',
        );
    }

    public function testEveryCreatedTableIsDroppedByPhpUninstall(): void
    {
        ['created' => $created] = self::parseAddonXml();

        $source = file_get_contents(dirname(__DIR__, 3) . self::PHP_UNINSTALL_SOURCE);
        self::assertIsString($source);
        preg_match_all('/DROP TABLE IF EXISTS\s+`?\?:(\w+)`?/i', $source, $m);

        self::assertSame(
            [],
            array_values(array_diff($created, $m[1])),
            'created but missing from the PHP uninstall drop list',
        );
    }
}
