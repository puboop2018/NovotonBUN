<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Exchange Rates Functions
 *
 * Handles automatic exchange rate updates from BNR (National Bank of Romania).
 * Updates CS-Cart currency coefficients with rates + commission.
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Fetch exchange rates XML from BNR (National Bank of Romania)
 *
 * @return string|false XML content or false on failure
 */
function fn_novoton_holidays_fetch_bnr_rates(): string|false
{
    $url = \Tygh\Addons\NovotonHolidays\Constants::BNR_RATES_URL;

    // Use cURL with TLS 1.2 as required by BNR
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
            'User-Agent: CS-Cart/NovotonHolidays'
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
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
 * @param array $currencies Currency codes to extract (default: EUR, USD, GBP)
 * @param bool $include_date If true, returns array with 'rates' and 'publishing_date' keys
 * @return array Associative array of currency => rate (relative to RON), or ['rates' => [...], 'publishing_date' => '...']
 */
function fn_novoton_holidays_parse_bnr_xml($xml_content, $currencies = ['EUR', 'USD', 'GBP'], $include_date = false): array
{
    $rates = [];
    $publishing_date = '';

    if (empty($xml_content)) {
        return $include_date ? ['rates' => $rates, 'publishing_date' => $publishing_date] : $rates;
    }

    // Suppress XML errors and handle them manually
    libxml_use_internal_errors(true);

    try {
        $xml = new SimpleXMLElement($xml_content);

        // Register namespace for XPath queries
        $xml->registerXPathNamespace('bnr', 'http://www.bnr.ro/xsd');

        // Get the latest Cube (contains rates)
        $cubes = $xml->xpath('//bnr:Cube');
        if (empty($cubes)) {
            // Try without namespace
            $cubes = $xml->Body->Cube;
        }

        if (!empty($cubes)) {
            // Get the first (latest) cube
            $cube = is_array($cubes) ? $cubes[0] : $cubes;

            // Extract publishing date from Cube's date attribute
            if (isset($cube['date'])) {
                $publishing_date = (string) $cube['date'];
            }

            foreach ($cube->Rate as $rate) {
                $currency = (string) $rate['currency'];

                if (in_array($currency, $currencies)) {
                    $value = (float) $rate;

                    // Handle multiplier (e.g., HUF has multiplier=100)
                    // Note: SimpleXML returns empty object for missing attributes, not null
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
 * @param array $bnr_rates Rates from BNR (currency => RON rate)
 * @param float $commission Commission percentage to add (e.g., 2 for 2%)
 * @return array Currency coefficients for CS-Cart
 */
function fn_novoton_holidays_calculate_currency_coefficients($bnr_rates, $commission = 0): array
{
    $coefficients = [];

    if (empty($bnr_rates['EUR'])) {
        return $coefficients;
    }

    $eur_rate = $bnr_rates['EUR'];
    $commission_multiplier = 1 + ($commission / 100);

    // RON coefficient = EUR rate from BNR (1 EUR = X RON)
    $coefficients['RON'] = round($eur_rate * $commission_multiplier, 4);

    // USD coefficient = EUR/USD cross rate
    if (!empty($bnr_rates['USD'])) {
        $coefficients['USD'] = round(($eur_rate / $bnr_rates['USD']) * $commission_multiplier, 4);
    }

    // GBP coefficient = EUR/GBP cross rate
    if (!empty($bnr_rates['GBP'])) {
        $coefficients['GBP'] = round(($eur_rate / $bnr_rates['GBP']) * $commission_multiplier, 4);
    }

    return $coefficients;
}

/**
 * Update CS-Cart currency rates using native functions
 *
 * @param array $coefficients Currency coefficients to update
 * @return array Results with success/error info per currency
 */
function fn_novoton_holidays_update_cscart_currencies($coefficients): array
{
    $results = [];

    foreach ($coefficients as $currency_code => $coefficient) {
        // Get existing currency data
        $currency = db_get_row(
            "SELECT * FROM ?:currencies WHERE currency_code = ?s",
            $currency_code
        );

        if (empty($currency)) {
            $results[$currency_code] = [
                'success' => false,
                'error' => 'Currency not found in CS-Cart'
            ];
            continue;
        }

        // Skip primary currency (EUR should have coefficient = 1)
        if ($currency['is_primary'] == 'Y') {
            $results[$currency_code] = [
                'success' => true,
                'message' => 'Primary currency - coefficient unchanged',
                'old_rate' => $currency['coefficient'],
                'new_rate' => 1.0
            ];
            continue;
        }

        $old_coefficient = $currency['coefficient'];

        // Use direct SQL update — fn_update_currency() may have side effects
        // (hooks, recalculations) that silently modify the coefficient
        db_query(
            "UPDATE ?:currencies SET coefficient = ?s WHERE currency_code = ?s",
            (string) round($coefficient, 5),
            $currency_code
        );

        // Verify the update was persisted correctly
        $stored = (float) db_get_field(
            "SELECT coefficient FROM ?:currencies WHERE currency_code = ?s",
            $currency_code
        );

        if (abs($stored - $coefficient) > 0.001) {
            fn_log_event('general', 'runtime', [
                'message' => sprintf(
                    'WARNING: Currency coefficient mismatch after update for %s: expected %s, got %s',
                    $currency_code,
                    $coefficient,
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

    // Clear currency cache
    if (function_exists('fn_clear_cache')) {
        fn_clear_cache('currencies');
    }

    // Also clear registry cache for currencies
    Registry::del('currencies');

    return $results;
}

/**
 * Main function to update exchange rates from BNR
 *
 * Fetches rates from BNR, applies commission, and updates CS-Cart currencies.
 *
 * @param bool $return_details If true, returns detailed results instead of just success/fail
 * @return array|bool Results array or bool success
 */
function fn_novoton_holidays_update_exchange_rates($return_details = false): array|bool
{
    $result = [
        'success' => false,
        'message' => '',
        'bnr_rates' => [],
        'publishing_date' => '',
        'coefficients' => [],
        'updates' => [],
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    // Step 1: Fetch BNR XML
    $xml = fn_novoton_holidays_fetch_bnr_rates();
    if ($xml === false) {
        $result['message'] = 'Failed to fetch exchange rates from BNR';
        return $return_details ? $result : false;
    }

    // Step 2: Parse XML for EUR, USD, GBP (with publishing date)
    $parsed = fn_novoton_holidays_parse_bnr_xml($xml, ['EUR', 'USD', 'GBP'], true);
    $bnr_rates = $parsed['rates'];
    $result['publishing_date'] = $parsed['publishing_date'];

    if (empty($bnr_rates)) {
        $result['message'] = 'Failed to parse BNR exchange rates';
        return $return_details ? $result : false;
    }

    // EUR is required for coefficient calculations (primary currency)
    if (empty($bnr_rates['EUR'])) {
        $result['message'] = 'EUR rate not found in BNR response';
        return $return_details ? $result : false;
    }
    $result['bnr_rates'] = $bnr_rates;

    // Step 3: Get commission setting (0-5% range)
    $commission = ConfigProvider::getCurrencyRiskCommission();
    $commission = max(0.0, min(5.0, $commission)); // Clamp to 0-5% range
    $result['commission'] = $commission;

    // Step 4: Calculate coefficients (EUR is primary)
    $coefficients = fn_novoton_holidays_calculate_currency_coefficients($bnr_rates, $commission);
    if (empty($coefficients)) {
        $result['message'] = 'Failed to calculate currency coefficients';
        return $return_details ? $result : false;
    }
    $result['coefficients'] = $coefficients;

    // Step 5: Update CS-Cart currencies
    $updates = fn_novoton_holidays_update_cscart_currencies($coefficients);
    $result['updates'] = $updates;

    // Step 6: Update last sync timestamp in addon settings
    $timestamp = date('Y-m-d H:i:s');

    // Use CS-Cart's Settings class if available, otherwise direct query
    if (class_exists('\\Tygh\\Settings')) {
        \Tygh\Settings::instance()->updateValue('last_exchange_rate_update', $timestamp, 'novoton_holidays');
    } else {
        // Fallback: direct query with JOIN for reliability
        db_query(
            "UPDATE ?:settings_objects o "
            . "INNER JOIN ?:settings_sections s ON o.section_id = s.section_id "
            . "SET o.value = ?s "
            . "WHERE o.name = 'last_exchange_rate_update' AND s.name = 'novoton_holidays'",
            $timestamp
        );
    }

    // Also update in Registry for immediate display
    Registry::set('addons.novoton_holidays.last_exchange_rate_update', $timestamp);

    $result['success'] = true;
    $result['message'] = 'Exchange rates updated successfully';
    $result['timestamp'] = $timestamp;

    // Log success
    fn_log_event('general', 'runtime', [
        'message' => sprintf(
            'Exchange rates updated: RON=%s, USD=%s, GBP=%s (commission: %s%%)',
            $coefficients['RON'] ?? 'N/A',
            $coefficients['USD'] ?? 'N/A',
            $coefficients['GBP'] ?? 'N/A',
            $commission
        )
    ]);

    return $return_details ? $result : true;
}

/**
 * Get current exchange rate info for display
 *
 * @return array Exchange rate information
 */
function fn_novoton_holidays_get_exchange_rate_info(): array
{
    $info = [
        'last_update' => ConfigProvider::getLastExchangeRateUpdate() ?: 'Never',
        'commission' => ConfigProvider::getCurrencyRiskCommission(),
        'currencies' => [],
    ];

    // Get current currency rates from CS-Cart
    $currencies = db_get_array(
        "SELECT currency_code, coefficient, is_primary FROM ?:currencies WHERE currency_code IN ('EUR', 'RON', 'USD', 'GBP') ORDER BY is_primary DESC, currency_code ASC"
    );

    foreach ($currencies as $currency) {
        $info['currencies'][$currency['currency_code']] = [
            'coefficient' => $currency['coefficient'],
            'is_primary' => $currency['is_primary'] == 'Y',
        ];
    }

    return $info;
}

/**
 * Cron handler for daily exchange rate updates
 * Called via:
 *   - Frontend: index.php?dispatch=novoton_exchange_rates.cron&cron_password=XXX
 *   - Admin: admin.php?dispatch=novoton_exchange_rates.cron (requires admin login)
 *
 * @return bool Success status
 */
function fn_novoton_holidays_cron_update_exchange_rates(): array|bool
{
    return fn_novoton_holidays_update_exchange_rates(false);
}
