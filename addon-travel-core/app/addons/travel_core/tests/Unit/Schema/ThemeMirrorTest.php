<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards template mirrors against silent drift.
 *
 * Two mirror families exist:
 *  1. THEME mirrors — novoton and travel_core ship nova_theme copies of their
 *     responsive templates. Editing only the responsive copy silently ships a
 *     stale nova_theme page (exactly this had already happened to 4 novoton
 *     templates when this guard was written — see DRIFT_ALLOWLIST).
 *  2. AREA copies — travel_core's order_booking_details.tpl must exist
 *     identically in the storefront theme, the admin backend and the mail
 *     tree (Smarty resolves templates per area; an include across areas
 *     cannot resolve).
 *
 * To change a mirrored template: change ALL copies (a straight file copy).
 * To intentionally fork a nova_theme file, add it to DRIFT_ALLOWLIST with a
 * short reason.
 */
class ThemeMirrorTest extends TestCase
{
    /**
     * nova_theme files allowed to differ from their responsive counterpart.
     * These 4 were already divergent when the guard landed (2026-07-10) and
     * need a deliberate review: either re-sync them or record here WHY nova
     * gets a different version.
     *
     * @var array<string, string> relative path (below templates/) => reason
     */
    private const DRIFT_ALLOWLIST = [
        'addons/novoton_holidays/blocks/booking_summary.tpl' => 'pre-existing divergence (64 lines) — pending review',
        'addons/novoton_holidays/hooks/index/styles.post.tpl' => 'pre-existing divergence — pending review',
        'addons/novoton_holidays/hooks/index/scripts.post.tpl' => 'pre-existing divergence — pending review',
        'addons/novoton_holidays/blocks/product_tabs/novoton_hotel_prices.tpl' => 'pre-existing divergence — pending review',
    ];

    private const THEME_MIRROR_ADDONS = [
        'addon-novoton-holidays/design/themes',
        'addon-travel-core/design/themes',
    ];

    /** Area copies that must stay byte-identical (paths from repo root). */
    private const AREA_COPY_SETS = [
        [
            'addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/order_booking_details.tpl',
            'addon-travel-core/design/backend/templates/addons/travel_core/components/order_booking_details.tpl',
            'addon-travel-core/design/backend/mail/templates/addons/travel_core/components/order_booking_details.tpl',
        ],
    ];

    public function testNovaThemeMirrorsMatchResponsive(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $drifted = [];
        $orphans = [];
        $checked = 0;

        foreach (self::THEME_MIRROR_ADDONS as $themesRoot) {
            $novaTemplates = $repoRoot . '/' . $themesRoot . '/nova_theme/templates';
            $responsiveTemplates = $repoRoot . '/' . $themesRoot . '/responsive/templates';
            if (!is_dir($novaTemplates)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($novaTemplates, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'tpl') {
                    continue;
                }
                $relative = ltrim(substr($file->getPathname(), strlen($novaTemplates)), '/');
                $responsiveTwin = $responsiveTemplates . '/' . $relative;
                $checked++;

                if (!is_file($responsiveTwin)) {
                    $orphans[] = $relative;
                    continue;
                }
                if (isset(self::DRIFT_ALLOWLIST[$relative])) {
                    continue;
                }
                if (file_get_contents($file->getPathname()) !== file_get_contents($responsiveTwin)) {
                    $drifted[] = $relative;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no nova_theme mirrors found — layout changed?');
        self::assertSame([], $orphans, "nova_theme templates with NO responsive counterpart (dead copies?):\n" . implode("\n", $orphans));
        self::assertSame(
            [],
            $drifted,
            "nova_theme mirrors drifted from responsive — copy the responsive file over, or "
            . "add to DRIFT_ALLOWLIST with a reason:\n" . implode("\n", $drifted),
        );
    }

    public function testAreaCopiesAreIdentical(): void
    {
        $repoRoot = dirname(__DIR__, 7);

        foreach (self::AREA_COPY_SETS as $set) {
            $reference = null;
            foreach ($set as $relPath) {
                $path = $repoRoot . '/' . $relPath;
                self::assertFileExists($path, "area copy missing: {$relPath}");
                $content = (string) file_get_contents($path);
                if ($reference === null) {
                    $reference = $content;
                    continue;
                }
                self::assertSame(
                    $reference,
                    $content,
                    "area copy drifted from its reference (first path in the set): {$relPath}",
                );
            }
        }
    }
}
