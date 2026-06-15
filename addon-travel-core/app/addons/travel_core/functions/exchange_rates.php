<?php
declare(strict_types=1);
/**
 * Travel Core - Exchange Rates Functions
 *
 * Handles automatic exchange rate updates from BNR (National Bank of Romania).
 * Updates CS-Cart currency coefficients with rates + optional commission.
 * Shared across all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

use Tygh\Registry;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Fetch exchange rates XML from BNR (National Bank of Romania)
 *
 * @return string|false XML content or false on failure
 */
function fn_travel_core_fetch_bnr_rates(): string|false
{
    $url = TravelConstants::BNR_RATES_URL;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml',
            'User-Agent: CS-Cart/TravelCore'
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR exchange rate fetch failed: ' . $error
        ]);
        return false;
    }

    if ($http_code !== 200) {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR exchange rate fetch failed with HTTP ' . $http_code
        ]);
        return false;
    }

    if (empty($response) || !is_string($response)) {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR exchange rate fetch returned empty response'
        ]);
        return false;
    }

    return $response;
}

/**
 * Parse BNR XML and extract rates for specified currencies
 *
 * @param string $xml_content Raw XML from BNR
 * @param array<int, string> $currencies Currency codes to extract (default: EUR, USD, GBP)
 * @param bool $include_date If true, returns array with 'rates' and 'publishing_date' keys
 * @return array<string, mixed> Associative array of currency => rate (relative to RON)
 */
function fn_travel_core_parse_bnr_xml($xml_content, $currencies = ['EUR', 'USD', 'GBP'], $include_date = false): array
{
    $rates = [];
    $publishing_date = '';

    if (empty($xml_content)) {
        return $include_date ? ['rates' => $rates, 'publishing_date' => $publishing_date] : $rates;
    }

    libxml_use_internal_errors(true);

    try {
        $xml = new SimpleXMLElement($xml_content);
        $xml->registerXPathNamespace('bnr', 'http://www.bnr.ro/xsd');

        $cubes = $xml->xpath('//bnr:Cube');
        if (empty($cubes)) {
            $cubes = $xml->Body->Cube;
        }

        if (!empty($cubes)) {
            $cube = is_array($cubes) ? $cubes[0] : $cubes;

            if (isset($cube['date'])) {
                $publishing_date = (string) $cube['date'];
            }

            foreach ($cube->Rate as $rate) {
                $currency = (string) $rate['currency'];

                if (in_array($currency, $currencies)) {
                    $value = (float) $rate;

                    $multiplier = 1;
                    if (isset($rate['multiplier']) && (string)$rate['multiplier'] !== '') {
                        $multiplier = (int) $rate['multiplier'];
                    }
                    if ($multiplier > 1) {
                        $value = $value / $multiplier;
                    }

                    $rates[$currency] = $value;
                }
            }
        }
    } catch (Exception $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'BNR XML parsing error: ' . $e->getMessage()
        ]);
    }

    libxml_clear_errors();

    return $include_date ? ['rates' => $rates, 'publishing_date' => $publishing_date] : $rates;
}

/**
 * Calculate CS-Cart currency coefficients from BNR rates
 *
 * Assumes EUR is the primary currency in CS-Cart.
 * Converts BNR rates (RON-based) to EUR-based coefficients.
 *
 * @param array<int|string, mixed> $bnr_rates Rates from BNR (currency => RON rate)
 * @param float $commission Commission percentage to add (e.g., 2 for 2%)
 * @return array<string, float> Currency coefficients for CS-Cart
 */
function fn_travel_core_calculate_currency_coefficients($bnr_rates, $commission = 0): array
{
    $coefficients = [];

    if (empty($bnr_rates['EUR'])) {
        return $coefficients;
    }

    $eur_rate = TypeCoerce::toFloat($bnr_rates['EUR']);
    $commission_multiplier = 1 + ($commission / 100);

    // RON coefficient = EUR rate from BNR (1 EUR = X RON)
    $coefficients['RON'] = round($eur_rate * $commission_multiplier, 4);

    // USD coefficient = EUR/USD cross rate
    if (!empty($bnr_rates['USD'])) {
        $coefficients['USD'] = round(($eur_rate / TypeCoerce::toFloat($bnr_rates['USD'])) * $commission_multiplier, 4);
    }

    // GBP coefficient = EUR/GBP cross rate
    if (!empty($bnr_rates['GBP'])) {
        $coefficients['GBP'] = round(($eur_rate / TypeCoerce::toFloat($bnr_rates['GBP'])) * $commission_multiplier, 4);
    }

    return $coefficients;
}

/**
 * Update CS-Cart currency rates using direct SQL
 *
 * @param array<string, mixed> $coefficients Currency coefficients to update
 * @return array<string, mixed> Results with success/error info per currency
 */
function fn_travel_core_update_cscart_currencies($coefficients): array
{
    $results = [];

    foreach ($coefficients as $currency_code => $coefficient) {
        $currency = db_get_row(
            "SELECT * FROM ?:currencies WHERE currency_code = ?s",
            $currency_code
        );

        if (!is_array($currency) || $currency === []) {
            $results[$currency_code] = [
                'success' => false,
                'error' => 'Currency not found in CS-Cart'
            ];
            continue;
        }

        // Skip primary currency (EUR should have coefficient = 1)
        if (($currency['is_primary'] ?? '') === 'Y') {
            $results[$currency_code] = [
                'success' => true,
                'message' => 'Primary currency - coefficient unchanged',
                'old_rate' => $currency['coefficient'] ?? null,
                'new_rate' => 1.0
            ];
            continue;
        }

        $old_coefficient = $currency['coefficient'] ?? null;
        $coefficientFloat = TypeCoerce::toFloat($coefficient);

        db_query(
            "UPDATE ?:currencies SET coefficient = ?d WHERE currency_code = ?s",
            round($coefficientFloat, 5),
            $currency_code
        );

        $stored = TypeCoerce::toFloat(db_get_field(
            "SELECT coefficient FROM ?:currencies WHERE currency_code = ?s",
            $currency_code
        ));

        if (abs($stored - $coefficientFloat) > 0.001) {
            fn_log_event('general', 'runtime', [
                'message' => sprintf(
                    'WARNING: Currency coefficient mismatch after update for %s: expected %s, got %s',
                    $currency_code,
                    $coefficientFloat,
                    $stored
                )
            ]);
        }

        $results[$currency_code] = [
            'success' => true,
            'old_rate' => $old_coefficient,
            'new_rate' => $stored
        ];
    }

    if (function_exists('fn_clear_cache')) {
        fn_clear_cache('currencies');
    }

    Registry::del('currencies');

    return $results;
}

/**
 * Main function to update exchange rates from BNR
 *
 * Fetches rates from BNR, applies commission, and updates CS-Cart currencies.
 * Provider addons should call this from their cron infrastructure.
 *
 * @param float $commission Commission percentage to apply (0-5%)
 * @param bool $return_details If true, returns detailed results instead of just success/fail
 * @return array<string, mixed>|bool Results array or bool success
 */
function fn_travel_core_update_exchange_rates(float $commission = 0.0, bool $return_details = false): array|bool
{
    $commission = max(0.0, min(5.0, $commission));

    $result = [
        'success' => false,
        'message' => '',
        'bnr_rates' => [],
        'publishing_date' => '',
        'commission' => $commission,
        'coefficients' => [],
        'updates' => [],
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    // Step 1: Fetch BNR XML
    $xml = fn_travel_core_fetch_bnr_rates();
    if ($xml === false) {
        $result['message'] = 'Failed to fetch exchange rates from BNR';
        return $return_details ? $result : false;
    }

    // Step 2: Parse XML for EUR, USD, GBP (with publishing date)
    $parsed = fn_travel_core_parse_bnr_xml($xml, ['EUR', 'USD', 'GBP'], true);
    $bnr_rates = is_array($parsed['rates'] ?? null) ? $parsed['rates'] : [];
    $result['publishing_date'] = $parsed['publishing_date'] ?? '';

    if (empty($bnr_rates)) {
        $result['message'] = 'Failed to parse BNR exchange rates';
        return $return_details ? $result : false;
    }

    if (empty($bnr_rates['EUR'])) {
        $result['message'] = 'EUR rate not found in BNR response';
        return $return_details ? $result : false;
    }
    $result['bnr_rates'] = $bnr_rates;

    // Step 3: Calculate coefficients (EUR is primary)
    $coefficients = fn_travel_core_calculate_currency_coefficients($bnr_rates, $commission);
    if (empty($coefficients)) {
        $result['message'] = 'Failed to calculate currency coefficients';
        return $return_details ? $result : false;
    }
    $result['coefficients'] = $coefficients;

    // Step 4: Update CS-Cart currencies
    $updates = fn_travel_core_update_cscart_currencies($coefficients);
    $result['updates'] = $updates;

    $result['success'] = true;
    $result['message'] = 'Exchange rates updated successfully';
    $result['timestamp'] = date('Y-m-d H:i:s');

    fn_log_event('general', 'runtime', [
        'message' => sprintf(
            'Exchange rates updated: RON=%s, USD=%s, GBP=%s (commission: %s%%)',
            $coefficients['RON'] ?? 'N/A',
            $coefficients['USD'] ?? 'N/A',
            $coefficients['GBP'] ?? 'N/A',
            $commission
        )
    ]);

    // Notify provider addons so they can log to their own sync tables
    fn_set_hook('travel_core_exchange_rates_updated', $result);

    return $return_details ? $result : true;
}

/**
 * Format exchange rate update result as plain-text output.
 *
 * Shared by cron.php and travel_cron controller to avoid duplicating
 * the same formatting logic in multiple entry points.
 *
 * @param array<string, mixed> $result Result array from fn_travel_core_update_exchange_rates()
 * @return string Formatted plain-text output
 */
function fn_travel_core_format_exchange_rate_output(array $result): string
{
    $lines = [];

    $lines[] = 'Status: ' . (!empty($result['success']) ? 'SUCCESS' : 'FAILED');
    $lines[] = 'Message: ' . TypeCoerce::toString($result['message'] ?? 'Unknown');

    if (!empty($result['publishing_date'])) {
        $lines[] = 'Publishing Date: ' . TypeCoerce::toString($result['publishing_date']);
    }

    $bnrRates = is_array($result['bnr_rates'] ?? null) ? $result['bnr_rates'] : [];
    if (!empty($bnrRates)) {
        $lines[] = '';
        $lines[] = 'BNR Rates (RON-based):';
        foreach ($bnrRates as $currency => $rate) {
            $lines[] = '  ' . TypeCoerce::toString($currency) . ': ' . TypeCoerce::toString($rate);
        }
    }

    $coefficients = is_array($result['coefficients'] ?? null) ? $result['coefficients'] : [];
    if (!empty($coefficients)) {
        $lines[] = '';
        $lines[] = 'Calculated Coefficients (EUR-based, commission: ' . TypeCoerce::toString($result['commission'] ?? 0) . '%):';
        foreach ($coefficients as $currency => $coefficient) {
            $lines[] = '  ' . TypeCoerce::toString($currency) . ': ' . TypeCoerce::toString($coefficient);
        }
    }

    $updates = is_array($result['updates'] ?? null) ? $result['updates'] : [];
    if (!empty($updates)) {
        $lines[] = '';
        $lines[] = 'Update Results:';
        foreach ($updates as $currency => $update) {
            $update = is_array($update) ? $update : [];
            if (!empty($update['success'])) {
                $lines[] = '  ' . TypeCoerce::toString($currency) . ': ' . TypeCoerce::toString($update['old_rate'] ?? '-') . ' -> ' . TypeCoerce::toString($update['new_rate'] ?? '-');
            } else {
                $lines[] = '  ' . TypeCoerce::toString($currency) . ': FAILED - ' . TypeCoerce::toString($update['error'] ?? 'Unknown');
            }
        }
    }

    return implode("\n", $lines);
}
