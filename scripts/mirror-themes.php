<?php

declare(strict_types=1);

/**
 * composer mirror — sync every mirrored template/asset copy from its source.
 *
 *  1. THEME mirrors: for each theme root in the manifest, every file under
 *     nova_theme/ is overwritten with its responsive/ counterpart (except the
 *     drift allowlist). Direction is always responsive -> nova_theme: edit the
 *     responsive copy, run `composer mirror`, done.
 *  2. AREA copies: each set's first path is the reference; the others are
 *     overwritten from it.
 *
 * The sync is enforced (not just provided) by ThemeMirrorTest, which reads
 * the same manifest — running this script is how you make that test pass.
 */

$repoRoot = dirname(__DIR__);

/** @var array{theme_roots: list<string>, drift_allowlist: array<string, string>, area_copy_sets: list<list<string>>} $manifest */
$manifest = require __DIR__ . '/mirror-manifest.php';

$synced = [];
$inSync = 0;
$orphans = [];

foreach ($manifest['theme_roots'] as $themesRoot) {
    $novaDir = $repoRoot . '/' . $themesRoot . '/nova_theme';
    $responsiveDir = $repoRoot . '/' . $themesRoot . '/responsive';
    if (!is_dir($novaDir)) {
        fwrite(STDERR, "warning: missing nova_theme dir: {$themesRoot}\n");
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($novaDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $relative = ltrim(substr($file->getPathname(), strlen($novaDir)), '/');
        if (isset($manifest['drift_allowlist'][$relative])) {
            continue;
        }
        $twin = $responsiveDir . '/' . $relative;
        if (!is_file($twin)) {
            // The guard test fails orphans; the script only reports them.
            $orphans[] = $themesRoot . '/nova_theme/' . $relative;
            continue;
        }
        if (file_get_contents($twin) === file_get_contents($file->getPathname())) {
            $inSync++;
            continue;
        }
        if (!copy($twin, $file->getPathname())) {
            fwrite(STDERR, "error: failed to copy {$twin}\n");
            exit(1);
        }
        $synced[] = $themesRoot . '/nova_theme/' . $relative;
    }
}

foreach ($manifest['area_copy_sets'] as $set) {
    $reference = $repoRoot . '/' . $set[0];
    if (!is_file($reference)) {
        fwrite(STDERR, "error: area-copy reference missing: {$set[0]}\n");
        exit(1);
    }
    foreach (array_slice($set, 1) as $relPath) {
        $target = $repoRoot . '/' . $relPath;
        if (is_file($target) && file_get_contents($target) === file_get_contents($reference)) {
            $inSync++;
            continue;
        }
        if (!copy($reference, $target)) {
            fwrite(STDERR, "error: failed to copy over {$relPath}\n");
            exit(1);
        }
        $synced[] = $relPath;
    }
}

foreach ($synced as $path) {
    echo "synced  {$path}\n";
}
foreach ($orphans as $path) {
    echo "orphan  {$path}  (no responsive counterpart — ThemeMirrorTest will fail this)\n";
}
echo sprintf("mirror: %d synced, %d already in sync, %d orphans\n", count($synced), $inSync, count($orphans));
