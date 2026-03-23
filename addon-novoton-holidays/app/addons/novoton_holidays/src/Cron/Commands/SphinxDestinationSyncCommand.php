<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;

/**
 * Cron command: trigger Sphinx Holidays destination sync.
 *
 * Delegates to the Sphinx addon's CronDispatcher so the Sphinx lock
 * and sync logic are reused. Pass full=1 to force a full re-sync
 * (ignores incremental updated_since).
 *
 * Usage:
 *   index.php?dispatch=novoton_cron.run&access_key=KEY&mode=sphinx_destinations
 *   index.php?dispatch=novoton_cron.run&access_key=KEY&mode=sphinx_destinations&full=1
 *   index.php?dispatch=novoton_cron.run&access_key=KEY&mode=sphinx_destinations_full
 */
class SphinxDestinationSyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['sphinx_destinations', 'sphinx_destinations_full'];
    }

    public static function getDescription(): string
    {
        return 'Sync Sphinx destinations (sphinx_destinations_full = force full re-sync)';
    }

    public function execute(): array
    {
        // Check that the sphinx_holidays addon is active
        $sphinxStatus = \Tygh\Registry::get('addons.sphinx_holidays.status');
        if ($sphinxStatus !== 'A') {
            $this->output('ERROR: sphinx_holidays addon is not installed or active (status: ' . ($sphinxStatus ?: 'not found') . ').');
            return ['success' => false, 'error' => 'sphinx_holidays addon not active'];
        }

        $mode = $this->getParam('_mode', 'sphinx_destinations');
        $forceFullSync = $mode === 'sphinx_destinations_full' || !empty($this->params['full']);

        $this->output('=== Sphinx Destination Sync ===');
        $this->output('Mode: ' . ($forceFullSync ? 'FULL (forced)' : 'incremental'));
        $this->output('');

        // Delegate to Sphinx CronDispatcher
        $sphinxDispatcher = new \Tygh\Addons\SphinxHolidays\Cron\CronDispatcher();

        if (!$sphinxDispatcher->hasMode('destinations')) {
            $this->output('ERROR: Sphinx CronDispatcher does not have "destinations" mode.');
            return ['success' => false, 'error' => 'Sphinx destinations mode not found'];
        }

        // Build params for Sphinx dispatcher
        $sphinxParams = [];
        if ($forceFullSync) {
            $sphinxParams['full'] = '1';
        }
        // Pass force through to Sphinx lock handling
        if (!empty($this->params['force'])) {
            $sphinxParams['force'] = '1';
        }

        // Set output callback on Sphinx dispatcher so we see progress
        // The Sphinx dispatcher sets this on the command internally,
        // so we capture output by wrapping the dispatch call
        $result = $sphinxDispatcher->dispatch('destinations', $sphinxParams);

        $success = $result['success'] ?? false;
        $stats = $result['stats'] ?? [];
        $error = $result['error'] ?? '';

        $this->output('');
        if ($success) {
            $synced = $stats['synced'] ?? 0;
            $total = $stats['total'] ?? 0;
            $this->output("Sphinx destinations synced: {$synced}/{$total}");
        } else {
            $this->output('Sphinx destination sync failed: ' . $error);
        }

        $this->logComplete('sphinx_destinations', $stats);

        return [
            'success' => $success,
            'stats' => $stats,
            'error' => $error,
        ];
    }
}
