<?php
declare(strict_types=1);
/**
 * Sphinx Holidays - Public Cron Controller
 *
 * Accessible via URL for external cron services (cron-job.org, server crontab, etc.)
 * Authentication via access_key parameter (no admin login required).
 *
 * Usage:
 *   index.php?dispatch=sphinx_cron.run&access_key=YOUR_KEY&cron_mode=hotels
 *   index.php?dispatch=sphinx_cron.run&access_key=YOUR_KEY&cron_mode=destinations
 *   index.php?dispatch=sphinx_cron.run&access_key=YOUR_KEY&cron_mode=hotels&country=GR
 *   index.php?dispatch=sphinx_cron.run&access_key=YOUR_KEY&cron_mode=hotels&destination_ids=1234,5678
 *   index.php?dispatch=sphinx_cron.run&access_key=YOUR_KEY&cron_mode=hotels&status=1
 *
 * Note: uses 'cron_mode' parameter (not 'mode') because CS-Cart reserves 'mode'
 * for the dispatch system.
 *
 * All modes are handled by Command classes via CronDispatcher.
 * See CronDispatcher::getAvailableModes() for the full list.
 */

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// ── Authentication ──

$providedKey = $_REQUEST['access_key'] ?? '';
$storedKey = ConfigProvider::getCronAccessKey();

header('Content-Type: text/plain; charset=utf-8');

if (empty($storedKey)) {
    http_response_code(403);
    echo "ERROR: Cron Access Key not configured in Sphinx Holidays addon settings.\n";
    exit;
}

if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    http_response_code(403);
    echo "ERROR: Invalid or missing access key.\n";
    exit;
}

// ── Parse mode ──

$mode = preg_replace('/[^a-z0-9_]/', '', strtolower($_REQUEST['cron_mode'] ?? 'destinations'));

// ── Status check (non-destructive) ──

if (!empty($_REQUEST['status'])) {
    $syncType = ($mode === 'hotels') ? 'hotels' : 'destinations';
    $lastLog = db_get_row(
        "SELECT * FROM ?:sphinx_sync_log WHERE sync_type = ?s ORDER BY started_at DESC LIMIT 1",
        $syncType
    );

    echo "[" . date('Y-m-d H:i:s') . "] Sphinx Cron Status - Mode: {$mode}\n\n";

    if ($lastLog) {
        echo "Last sync: {$lastLog['status']} at {$lastLog['started_at']}\n";
        echo "Items: {$lastLog['items_synced']}/{$lastLog['items_total']}";
        if ((int) $lastLog['items_failed'] > 0) {
            echo " ({$lastLog['items_failed']} failed)";
        }
        echo "\n";
        if ($lastLog['duration_ms']) {
            echo "Duration: " . round((int) $lastLog['duration_ms'] / 1000, 1) . "s\n";
        }
        if ($lastLog['completed_at']) {
            echo "Completed: {$lastLog['completed_at']}\n";
        }
        if (!empty($lastLog['error_message'])) {
            echo "Error: {$lastLog['error_message']}\n";
        }
    } else {
        echo "No sync history found for mode: {$mode}\n";
    }

    exit;
}

// ── Dispatch ──

echo "[" . date('Y-m-d H:i:s') . "] Sphinx Cron Started - Mode: {$mode}\n";

fn_log_event('general', 'runtime', [
    'message' => "Sphinx frontend cron started (mode: {$mode})",
]);

try {
    $dispatcher = new CronDispatcher();

    if (!$dispatcher->hasMode($mode)) {
        echo "Unknown mode: {$mode}\n\n";
        echo "Available modes:\n";
        foreach (CronDispatcher::getAvailableModes() as $m => $desc) {
            echo "  {$m} - {$desc}\n";
        }
    } else {
        $result = $dispatcher->dispatch($mode, $_REQUEST);

        $success = $result['success'] ?? false;
        echo "\n[" . date('Y-m-d H:i:s') . "] Cron job " . ($success ? 'completed successfully' : 'finished with errors') . ".\n";

        if (!empty($result['stats'])) {
            $s = $result['stats'];
            echo "Stats: " . ($s['synced'] ?? $s['added'] ?? 0) . "/" . ($s['total'] ?? 0) . " synced";
            if (($s['skipped'] ?? 0) > 0) {
                echo ", {$s['skipped']} skipped";
            }
            if (($s['failed'] ?? 0) > 0) {
                echo ", {$s['failed']} failed";
            }
            if (($s['duration_ms'] ?? 0) > 0) {
                echo " (" . round($s['duration_ms'] / 1000, 1) . "s)";
            }
            echo "\n";
        }
    }

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";

    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx frontend cron error: ' . $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);
}

exit;
