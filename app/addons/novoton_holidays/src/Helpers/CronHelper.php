<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Cron Helper
 *
 * Consolidates common cron operations:
 * - Authentication
 * - Header/footer output
 * - API initialization
 * - Product creation from hotel data
 *
 * Injectable: Use CronHelper::getInstance() or inject via constructor.
 * Testable: Use CronHelper::setInstance($mockHelper) in tests.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\PathResolver;

class CronHelper
{
    /**
     * Singleton instance (replaceable for testing)
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the singleton instance (for testing / DI).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Validate cron access key
     *
     * @param string $providedKey Key from request
     * @return bool
     */
    public static function validateAccessKey(string $providedKey): bool
    {
        $storedKey = ConfigProvider::getCronAccessKey();

        if (empty($storedKey)) {
            return false;
        }

        return !empty($providedKey) && hash_equals($storedKey, $providedKey);
    }

    /**
     * Send authentication error response
     *
     * @param string $message Error message
     */
    public static function sendAuthError(string $message): void
    {
        header('Content-Type: text/plain');
        http_response_code(403);
        echo "ERROR: {$message}\n";
        exit;
    }

    /**
     * Initialize cron environment
     *
     * @return array ['api' => NovotonApi, 'logger' => SyncLogger, 'mode' => string]
     */
    public static function initialize(): array
    {
        $mode = preg_replace('/[^a-zA-Z0-9_]/', '', $_REQUEST['mode'] ?? 'resinfo');

        header('Content-Type: text/plain; charset=utf-8');

        // Load API
        $srcDir = PathResolver::getPath('src');
        if (file_exists($srcDir . 'NovotonApi.php')) {
            require_once($srcDir . 'NovotonApi.php');
        }

        $api = new NovotonApi();
        $logger = new SyncLogger($mode);

        return [
            'api' => $api,
            'logger' => $logger,
            'mode' => $mode,
        ];
    }

    /**
     * Parse excluded resorts from request or settings
     *
     * @return array
     */
    public static function getExcludedResorts(): array
    {
        // Check request first
        if (!empty($_REQUEST['exclude_resorts'])) {
            if (is_array($_REQUEST['exclude_resorts'])) {
                return array_filter($_REQUEST['exclude_resorts']);
            }
            return array_filter(array_map('trim', explode(',', $_REQUEST['exclude_resorts'])));
        }

        // Fall back to settings
        return ConfigProvider::getExcludedResorts();
    }

    /**
     * Print available modes help
     *
     * @param SyncLogger $logger
     */
    public static function printAvailableModes(SyncLogger $logger): void
    {
        $logger->output("Available modes:");
        $logger->output("- hotel_info_batched: [RECOMMENDED] Batched hotel info sync with resume");
        $logger->output("    &status=1          - Check progress");
        $logger->output("    &force_full=1      - Force full sync (all hotels)");
        $logger->output("    &reset=1           - Reset/cancel in-progress sync");
        $logger->output("    &batch_size=100    - Hotels per batch (default: 100)");
        $logger->output("    &max_time=300      - Max seconds per run (default: 300)");
        $logger->output("    &unlimited=1       - No time limit (for CLI PHP usage)");
        $logger->output("- sync_priceinfo_batched: [RECOMMENDED] Batched priceinfo sync with resume");
        $logger->output("    &status=1          - Check progress");
        $logger->output("    &force_full=1      - Force full sync (all packages)");
        $logger->output("    &reset=1           - Reset/cancel in-progress sync");
        $logger->output("    &batch_size=50     - Packages per batch (default: 50)");
        $logger->output("    &max_time=300      - Max seconds per run (default: 300)");
        $logger->output("    &stale_hours=24    - Re-sync packages older than N hours");
        $logger->output("    &unlimited=1       - No time limit (for CLI PHP usage)");
        $logger->output("- hotel_list: Hotel list sync from API");
        $logger->output("- resinfo: Check ASK bookings status");
        $logger->output("- offers_update: Check for new/updated offers (&country=BULGARIA)");
        $logger->output("- add_hotels_as_products: Add hotels as products");
        $logger->output("- resort_list: Sync resort names from API (authoritative names for room_price)");
        $logger->output("- list_facilities: Sync facilities list from API");
        $logger->output("- exchange_rates: Update currency rates from BNR (daily)");
        $logger->output("");
        $logger->output("Recommended workflow:");
        $logger->output("  1. hotel_info_batched (every 5 min) - Smart hotel info sync with resume");
        $logger->output("     - First run: syncs all hotels (batched)");
        $logger->output("     - Daily: only new/changed hotels from offers_update");
        $logger->output("     - Every 6 months: automatic full re-sync");
        $logger->output("  2. sync_priceinfo_batched (every 5 min) - Smart priceinfo sync with resume");
        $logger->output("     - First run: syncs all packages (batched)");
        $logger->output("     - Daily: only stale packages (older than 24h)");
        $logger->output("     - Every 7 days: automatic full re-sync");
        $logger->output("  3. resort_list (weekly) - Sync resort names from API");
        $logger->output("  4. list_facilities (weekly) - Sync facilities list");
        $logger->output("  5. exchange_rates (daily) - Update BNR exchange rates");
    }
}
