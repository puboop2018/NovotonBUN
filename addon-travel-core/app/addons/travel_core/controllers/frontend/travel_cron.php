<?php
declare(strict_types=1);
/**
 * Travel Core - Cron Controller (frontend dispatch)
 *
 * Provides URL: index.php?dispatch=travel_cron.run&access_key=KEY&mode=exchange_rates
 *
 * This is the CS-Cart dispatch controller. For CLI usage, see cron.php.
 *
 * @package TravelCore
 * @since   1.1.0
 */

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// --- Authenticate ---
$storedKey = Registry::get('addons.travel_core.cron_access_key');
$providedKey = RequestCoerce::string($_REQUEST, 'access_key');

if (empty($storedKey)) {
    die("ERROR: Cron access key not set in Travel Core addon settings.\n");
}
if (empty($providedKey) || !hash_equals(TypeCoerce::toString($storedKey), $providedKey)) {
    die("ERROR: Invalid or missing access key.\n");
}

if ($mode === 'run') {
    $cron_mode = isset($_REQUEST['mode']) ? preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'mode'))) : '';

    // Fix: 'mode' param was consumed by CS-Cart dispatch, use 'cron_mode' as fallback
    if (empty($cron_mode) || $cron_mode === 'run') {
        $cron_mode = isset($_REQUEST['cron_mode']) ? preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'cron_mode'))) : '';
    }

    $supported_modes = ['exchange_rates'];

    if (empty($cron_mode) || !in_array($cron_mode, $supported_modes, true)) {
        header('Content-Type: text/plain');
        echo "Travel Core Cron - Available modes:\n";
        echo "  exchange_rates - Update BNR exchange rates\n\n";
        echo "Usage: dispatch=travel_cron.run&access_key=KEY&cron_mode=exchange_rates\n";
        exit;
    }

    header('Content-Type: text/plain');
    echo "[" . date('Y-m-d H:i:s') . "] Travel Core Cron - Mode: {$cron_mode}\n\n";

    // Currently only 'exchange_rates' is in $supported_modes, so we always reach here.
    $commission = TypeCoerce::toFloat(Registry::get('addons.travel_core.currency_risk_commission'));

    $result = fn_travel_core_update_exchange_rates($commission, true);

    if (!is_array($result)) {
        $result = ['success' => false, 'message' => 'No response from exchange rate service'];
    }

    echo fn_travel_core_format_exchange_rate_output($result) . "\n";

    echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";

    exit;
}
