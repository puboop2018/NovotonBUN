<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Block Manager schema invariants that keep the Sphinx widgets
 * out of CS-Cart's shared 'template' block-type pool — the pool the core
 * header Search block selects its template from.
 *
 * Regression context: the widgets were once injected into
 * $schema['template']['templates'] (with paths that did not even resolve).
 * Because the core value of that key is a directory-scan STRING
 * ('blocks/static_templates'), the injection replaced the whole core pool and
 * polluted the Search block's template list.
 */
final class BlockManagerSchemaTest extends TestCase
{
    private const DEDICATED_TYPES = ['sphinx_booking_engine', 'sphinx_best_deals'];

    /** Core schema shape: 'templates' is a directory-scan string. */
    private const CORE_TEMPLATE_TYPE = [
        'templates' => 'blocks/static_templates',
        'wrappers'  => 'blocks/wrappers',
    ];

    /** @return array<string, mixed> */
    private static function applySchema(): array
    {
        $schema = ['template' => self::CORE_TEMPLATE_TYPE];

        $result = (static function (array $schema) {
            return include dirname(__DIR__, 3) . '/schemas/block_manager/blocks.post.php';
        })($schema);

        self::assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }

    public function testSharedTemplatePoolIsUntouched(): void
    {
        $schema = self::applySchema();

        self::assertSame(
            self::CORE_TEMPLATE_TYPE,
            $schema['template'] ?? null,
            'The shared template pool feeds the core Search block — the addon must never modify it',
        );
    }

    public function testDedicatedBlockTypesAreRegistered(): void
    {
        $schema = self::applySchema();

        foreach (self::DEDICATED_TYPES as $type) {
            self::assertArrayHasKey($type, $schema);
            self::assertIsArray($schema[$type]);
            self::assertIsArray($schema[$type]['templates'] ?? null, "$type must declare a templates array");
            self::assertNotEmpty($schema[$type]['templates'], "$type must register at least one template");
            self::assertSame('blocks/wrappers', $schema[$type]['wrappers'] ?? null, "$type must use standard wrappers");
        }
    }

    public function testRegisteredTemplateFilesExistInResponsiveTheme(): void
    {
        $schema = self::applySchema();
        $templatesRoot = dirname(__DIR__, 6) . '/design/themes/responsive/templates/';

        foreach (self::DEDICATED_TYPES as $type) {
            self::assertIsArray($schema[$type]);
            self::assertIsArray($schema[$type]['templates']);
            foreach (array_keys($schema[$type]['templates']) as $tplPath) {
                self::assertIsString($tplPath);
                self::assertFileExists(
                    $templatesRoot . $tplPath,
                    "$type registers '$tplPath' which does not resolve to a shipped template file",
                );
            }
        }
    }

    /**
     * best_deals.tpl reads $block.properties.deals_type / deals_limit /
     * destination_id — the block-type settings must keep those exact keys.
     */
    public function testBestDealsSettingsMatchTemplateContract(): void
    {
        $schema = self::applySchema();

        self::assertIsArray($schema['sphinx_best_deals']);
        $settings = $schema['sphinx_best_deals']['settings'] ?? null;
        self::assertIsArray($settings);
        foreach (['deals_type', 'deals_limit', 'destination_id'] as $key) {
            self::assertArrayHasKey($key, $settings, "best_deals.tpl reads \$block.properties.$key");
        }
    }

    public function testNoTemplatesShipInAutoScannedStaticTemplatesDir(): void
    {
        $designRoot = dirname(__DIR__, 6) . '/design';
        $offenders = [];

        $staticDirs = glob($designRoot . '/themes/*/templates/blocks/static_templates', GLOB_ONLYDIR) ?: [];
        foreach ($staticDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'tpl') {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Files under blocks/static_templates/ are auto-discovered into the shared pool '
            . '(and become selectable for the core Search block) — ship widgets as dedicated block types instead',
        );
    }
}
