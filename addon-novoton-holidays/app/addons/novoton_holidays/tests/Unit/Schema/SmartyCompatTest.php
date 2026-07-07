<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

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
}
