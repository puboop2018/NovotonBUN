<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * CS-Cart registers its services (session, view, mailer, …) in a Pimple
 * container, and Pimple FREEZES a service once it has been retrieved —
 * any later `Tygh::$app['service'] = …` throws FrozenServiceException at
 * runtime. Regression: three cart-write blocks assigned a "narrowed" array
 * back to Tygh::$app['session'] and crashed every add-to-cart with
 * "Cannot override frozen service \"session\"". Mutate session state through
 * by-ref binds (`$cart = &Tygh::$app['session']['cart'];`) instead.
 */
final class PimpleContainerGuardTest extends TestCase
{
    public function testNoAssignmentsToContainerOffsets(): void
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
            // Matches container offset writes like `Tygh::$app['session'] = …`
            // but not comparisons (==) or nested offsets (`$app['session']['k'] = …`).
            if (preg_match('/Tygh::\$app\[[^\]]+\]\s*=[^=]/', $source)) {
                $offenders[] = substr($path, strlen($addonRoot) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Files assigning to Tygh::$app[...] — Pimple services are frozen at runtime '
            . '(FrozenServiceException); mutate through by-ref binds into the service instead',
        );
    }
}
