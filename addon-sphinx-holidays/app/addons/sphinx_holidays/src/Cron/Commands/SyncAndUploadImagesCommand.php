<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: queue and upload hotel images in a single run.
 *
 * Thin orchestrator that dispatches the two image primitives back to back:
 *   1. sync_images        — populate the image queue from hotel DB records
 *   2. process_image_queue — download each queued image and attach to the product
 *
 * This is the one-shot operator entry point: a single URL produces complete
 * product galleries. The queue still backs the work, so a crash or timeout is
 * resumable — re-running (or the frequently-scheduled process_image_queue)
 * picks up whatever is still pending.
 *
 * All scope params (country, region_id, whitelist, limit, batch_size) are
 * passed straight through to the underlying commands.
 *
 * Usage: cron_mode=sync_and_upload_images[&country=XX][&limit=N]
 */
class SyncAndUploadImagesCommand extends AbstractSyncCommand
{
    /** Modes to run in order. */
    private const array IMAGE_SEQUENCE = ['sync_images', 'process_image_queue'];

    #[\Override]
    public static function getDescription(): string
    {
        return 'Queue and upload hotel images in one run (sync_images + process_image_queue)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $this->output('=== SYNC & UPLOAD IMAGES ===');
        $this->output('Steps: ' . implode(' → ', self::IMAGE_SEQUENCE));
        $this->output('');

        $dispatcher = new CronDispatcher();
        $results = [];
        $allOk = true;

        foreach (self::IMAGE_SEQUENCE as $mode) {
            $this->output("──── {$mode} ────");
            $result = $dispatcher->dispatch($mode, $params);

            $success = (bool) ($result['success'] ?? false);
            $results[$mode] = $success;

            if (!$success) {
                if ((bool) ($result['busy'] ?? false)) {
                    $this->output("[INFO] {$mode} is already running, skipped.");
                } else {
                    $allOk = false;
                    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
                    $error = TypeCoerce::toString($result['error'] ?? $stats['error'] ?? 'unknown error');
                    $this->output("[WARN] {$mode} finished with errors: {$error}");
                }
            }

            $this->output('');
        }

        $this->output('=== DONE ===');

        return [
            'success' => $allOk,
            'stats' => ['modes' => $results],
        ];
    }
}
