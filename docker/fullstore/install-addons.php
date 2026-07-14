<?php

declare(strict_types=1);

/**
 * Idempotent, dependency-ordered addon installer for the sandbox.
 *
 * The console installer already installs the four addons listed in
 * install/config.php; this is a re-runnable safety net that guarantees the
 * order (travel_core must precede the providers) and lets you exercise the
 * install/uninstall lifecycle from the CLI:
 *
 *   docker compose exec app php /usr/local/bin/install-addons.php
 *
 * Anything already active is skipped. Bootstrapping CS-Cart from the CLI is
 * version-sensitive, so every step is guarded — a failure here never blocks
 * the container (the store still installs via the console installer).
 */

$docroot = '/var/www/html';

if (!is_file($docroot . '/init.php')) {
    fwrite(STDERR, "[install-addons] init.php not found — is the store installed yet?\n");
    exit(0);
}

// Minimal admin-area CLI bootstrap.
if (!defined('AREA')) {
    define('AREA', 'A');
}
if (!defined('ACCOUNT_TYPE')) {
    define('ACCOUNT_TYPE', 'admin');
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

chdir($docroot);

try {
    require $docroot . '/init.php';
} catch (\Throwable $e) {
    fwrite(STDERR, '[install-addons] CS-Cart bootstrap failed: ' . $e->getMessage() . "\n");
    exit(0);
}

if (!function_exists('fn_install_addon') || !function_exists('fn_get_addon_status')) {
    fwrite(STDERR, "[install-addons] addon functions unavailable after bootstrap; skipping.\n");
    exit(0);
}

// Dependency order: travel_core first, providers next, fgo (independent) last.
$order = ['travel_core', 'novoton_holidays', 'sphinx_holidays', 'fgo_invoicing'];

foreach ($order as $addon) {
    if (!is_file($docroot . '/app/addons/' . $addon . '/addon.xml')) {
        echo "[install-addons] {$addon}: no addon.xml (not linked?) — skipping\n";
        continue;
    }

    $status = fn_get_addon_status($addon);
    if ($status === 'A') {
        echo "[install-addons] {$addon}: already active\n";
        continue;
    }

    echo "[install-addons] {$addon}: installing...\n";
    try {
        // Second arg false = no demo data.
        fn_install_addon($addon, false);
        $after = fn_get_addon_status($addon);
        echo "[install-addons] {$addon}: status now '{$after}'\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "[install-addons] {$addon}: install failed — " . $e->getMessage() . "\n");
    }
}

echo "[install-addons] done\n";
