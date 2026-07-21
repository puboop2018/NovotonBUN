<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * CS-Cart assigns a set of GLOBAL Smarty variables on every admin page
 * (countries: [code => name], states, currencies, languages, navigation,
 * user_info). An addon re-assigning one of these names from backend code
 * silently clobbers core data for the WHOLE page — the defect that shipped
 * twice as a numeric list overwriting 'countries' (novoton dashboard/hotels,
 * fixed in 33f15ad; sphinx whitelist, renamed to
 * 'sphinx_whitelist_countries'). Prefix addon assigns with the addon name.
 *
 * Scope: backend controllers, hooks/, functions/, func.php, init.php — the
 * procedural surfaces that execute during an admin request. Frontend
 * controllers are excluded: the storefront re-assigns some of these
 * per-page by core design.
 */
final class ReservedSmartyGlobalsTest extends TestCase
{
    private const RESERVED = ['countries', 'states', 'currencies', 'languages', 'navigation', 'user_info'];

    public function testNoBackendAssignOverwritesACoreSmartyGlobal(): void
    {
        $addonRoot = dirname(__DIR__, 3);

        $files = array_values(array_filter(
            [$addonRoot . '/func.php', $addonRoot . '/init.php'],
            'is_file',
        ));
        $dirs = array_filter(
            [$addonRoot . '/controllers/backend', $addonRoot . '/hooks', $addonRoot . '/functions'],
            'is_dir',
        );
        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $pattern = '/->\s*assign\(\s*[\'"](' . implode('|', self::RESERVED) . ')[\'"]/';

        $offenders = [];
        foreach ($files as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $i => $line) {
                if (preg_match($pattern, $line, $m) === 1) {
                    $offenders[] = substr($path, strlen($addonRoot) + 1) . ':' . ($i + 1) . " ('{$m[1]}')";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Backend code assigning a CS-Cart core Smarty global — this clobbers core page data; '
                . "use an addon-prefixed name instead (e.g. 'sphinx_whitelist_countries')",
        );
    }
}
