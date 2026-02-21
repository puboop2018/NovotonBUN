<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;

class DataSyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['resort_list', 'list_facilities', 'exchange_rates'];
    }

    public static function getDescription(): string
    {
        return 'Sync reference data (resorts, facilities, exchange rates)';
    }

    public function execute(): array
    {
        $mode = $this->params['_mode'] ?? 'resort_list';

        switch ($mode) {
            case 'resort_list':
                return $this->syncResortList();
            case 'list_facilities':
                return $this->syncFacilities();
            case 'exchange_rates':
                return $this->updateExchangeRates();
        }

        return ['success' => false, 'error' => 'Unknown sub-mode'];
    }

    private function syncResortList(): array
    {
        $country = $this->getParam('country', 'BULGARIA');
        $this->output("Syncing resort list from Novoton API (country: {$country})...");
        $this->output("");

        $result = fn_novoton_holidays_sync_resorts_list($country);

        $added = 0;
        $updated = 0;
        $errors = 0;

        if (is_array($result) && !empty($result['success'])) {
            $added = $result['added'] ?? 0;
            $updated = $result['updated'] ?? 0;
            $this->output("Synced " . ($result['total'] ?? ($added + $updated)) . " resorts ({$added} added, {$updated} updated).");
        } else {
            $errors = 1;
            $this->output("Error: " . ($result['error'] ?? 'Unknown error'));
        }

        $this->logToSyncTable('resort_list', $added + $updated, $errors);
        $this->sendReport('resort_list', [
            'added' => $added, 'updated' => $updated, 'total' => $result['total'] ?? 0,
            'duration' => $this->getDuration() . 's'
        ]);

        return ['success' => $errors === 0, 'stats' => ['added' => $added, 'updated' => $updated]];
    }

    private function syncFacilities(): array
    {
        $this->output("Syncing facilities list from Novoton API...");
        $this->output("");

        $result = fn_novoton_holidays_sync_facilities_list();

        $added = 0;
        $updated = 0;
        $errors = 0;

        if (is_array($result) && !empty($result['success'])) {
            $added = $result['added'] ?? 0;
            $updated = $result['updated'] ?? 0;
            $this->output("Synced " . ($result['total'] ?? ($added + $updated)) . " facilities ({$added} added, {$updated} updated).");
        } elseif (is_numeric($result)) {
            $updated = (int)$result;
            $this->output("Synced {$updated} facilities.");
        } else {
            $errors = 1;
            $this->output("Error: " . (is_array($result) ? ($result['error'] ?? 'Unknown error') : 'Sync returned unexpected result'));
        }

        $this->logToSyncTable('facilities', $added + $updated, $errors);
        $this->sendReport('facilities', [
            'added' => $added, 'updated' => $updated, 'errors' => $errors,
            'duration' => $this->getDuration() . 's'
        ]);

        return ['success' => $errors === 0, 'stats' => ['added' => $added, 'updated' => $updated]];
    }

    private function updateExchangeRates(): array
    {
        $this->output("Exchange Rates Update (BNR)");
        $this->output("===========================");
        $this->output("");

        $result = fn_novoton_holidays_update_exchange_rates(true);

        $this->output("Status: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
        $this->output("Message: " . $result['message']);

        if (!empty($result['publishing_date'])) {
            $this->output("Publishing Date: " . $result['publishing_date']);
        }
        $this->output("");

        if (!empty($result['bnr_rates'])) {
            $this->output("BNR Rates (RON-based):");
            foreach ($result['bnr_rates'] as $currency => $rate) {
                $this->output("  {$currency}: {$rate}");
            }
            $this->output("");
        }

        if (!empty($result['coefficients'])) {
            $this->output("Calculated Coefficients (EUR-based, commission: " . ($result['commission'] ?? 0) . "%):");
            foreach ($result['coefficients'] as $currency => $coefficient) {
                $this->output("  {$currency}: {$coefficient}");
            }
            $this->output("");
        }

        if (!empty($result['updates'])) {
            $this->output("Update Results:");
            foreach ($result['updates'] as $currency => $update) {
                if ($update['success']) {
                    $this->output("  {$currency}: " . ($update['old_rate'] ?? '-') . " -> " . ($update['new_rate'] ?? '-'));
                } else {
                    $this->output("  {$currency}: FAILED - " . ($update['error'] ?? 'Unknown'));
                }
            }
        }

        $this->logToSyncTable('exchange_rates', count($result['updates'] ?? []));

        return ['success' => $result['success'] ?? false, 'stats' => $result];
    }
}
