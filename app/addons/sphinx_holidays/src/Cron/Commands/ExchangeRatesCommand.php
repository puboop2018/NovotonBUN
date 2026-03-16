<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Cron command: update exchange rates via BNR (travel_core function).
 *
 * Usage: php cron.php access_key=KEY mode=exchange_rates
 */
class ExchangeRatesCommand
{
    /** @var callable|null */
    private $outputCallback = null;

    public static function getDescription(): string
    {
        return 'Update exchange rates from BNR (Romanian National Bank)';
    }

    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    public function execute(array $params = []): array
    {
        $this->output('Exchange Rates Update (BNR)');
        $this->output('===========================');
        $this->output('');

        if (!function_exists('fn_travel_core_update_exchange_rates')) {
            $this->output('ERROR: travel_core addon not active or exchange_rates function not available.');
            return ['success' => false, 'error' => 'fn_travel_core_update_exchange_rates not available'];
        }

        $commission = ConfigProvider::getCommission();
        $result = fn_travel_core_update_exchange_rates($commission, true);

        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'No response from exchange rate service'];
        }

        $this->output('Status: ' . (($result['success'] ?? false) ? 'SUCCESS' : 'FAILED'));
        $this->output('Message: ' . ($result['message'] ?? 'Unknown'));

        if (!empty($result['publishing_date'])) {
            $this->output('Publishing Date: ' . $result['publishing_date']);
        }
        $this->output('');

        if (!empty($result['bnr_rates'])) {
            $this->output('BNR Rates (RON-based):');
            foreach ($result['bnr_rates'] as $currency => $rate) {
                $this->output("  {$currency}: {$rate}");
            }
            $this->output('');
        }

        if (!empty($result['coefficients'])) {
            $this->output('Calculated Coefficients (EUR-based, commission: ' . ($result['commission'] ?? 0) . '%):');
            foreach ($result['coefficients'] as $currency => $coefficient) {
                $this->output("  {$currency}: {$coefficient}");
            }
            $this->output('');
        }

        if (!empty($result['updates'])) {
            $this->output('Update Results:');
            foreach ($result['updates'] as $currency => $update) {
                if ($update['success']) {
                    $this->output("  {$currency}: " . ($update['old_rate'] ?? '-') . ' -> ' . ($update['new_rate'] ?? '-'));
                } else {
                    $this->output("  {$currency}: FAILED - " . ($update['error'] ?? 'Unknown'));
                }
            }
        }

        // Log to sync table
        $this->logExchangeRateSync($result);

        return [
            'success' => $result['success'] ?? false,
            'stats'   => [
                'total' => count($result['updates'] ?? []),
                'synced' => count(array_filter($result['updates'] ?? [], fn($u) => $u['success'] ?? false)),
                'failed' => count(array_filter($result['updates'] ?? [], fn($u) => !($u['success'] ?? false))),
                'duration_ms' => 0,
            ],
        ];
    }

    private function logExchangeRateSync(array $result): void
    {
        $success = $result['success'] ?? false;
        $updates = $result['updates'] ?? [];
        $total = count($updates);
        $synced = count(array_filter($updates, fn($u) => $u['success'] ?? false));

        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, error_message, started_at, completed_at) VALUES (?s, ?s, ?i, ?i, ?i, ?s, NOW(), NOW())",
            'exchange_rates',
            $success ? 'completed' : 'failed',
            $total,
            $synced,
            $total - $synced,
            $success ? '' : ($result['message'] ?? 'Exchange rate update failed')
        );
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message);
        }
    }
}
