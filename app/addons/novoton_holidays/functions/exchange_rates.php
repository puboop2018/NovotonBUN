<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Exchange Rates Functions
 *
 * Handles automatic exchange rate updates from BNR (National Bank of Romania).
 * Fetches and stores BNR rates in the database for reference.
 * CS-Cart currency conversion is handled by CS-Cart's own addon — this module
 * does NOT update CS-Cart currency coefficients.
 *
 * @package NovotonHolidays
 * @since 3.0.0
 */

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
 * Main function to fetch and store exchange rates from BNR
 *
 * Fetches rates from BNR and saves them to the database for reference.
 * Does NOT update CS-Cart currency coefficients — CS-Cart's own addon
 * handles currency conversion.
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
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    // Step 1: Fetch BNR XML
    $xml = fn_novoton_holidays_fetch_bnr_rates();
    if ($xml === false) {
        $result['message'] = 'Failed to fetch exchange rates from BNR';
        return $return_details ? $result : false;
    }

    // Step 2: Parse XML for EUR, USD, GBP, RON (with publishing date)
    $parsed = fn_novoton_holidays_parse_bnr_xml($xml, ['EUR', 'USD', 'GBP'], true);
    $bnr_rates = $parsed['rates'];
    $result['publishing_date'] = $parsed['publishing_date'];

    if (empty($bnr_rates)) {
        $result['message'] = 'Failed to parse BNR exchange rates';
        return $return_details ? $result : false;
    }

    // EUR is required (primary reference currency)
    if (empty($bnr_rates['EUR'])) {
        $result['message'] = 'EUR rate not found in BNR response';
        return $return_details ? $result : false;
    }
    $result['bnr_rates'] = $bnr_rates;

    // Step 3: Calculate coefficients for reference/logging (EUR-based)
    $commission = ConfigProvider::getCurrencyRiskCommission();
    $commission = max(0.0, min(5.0, $commission));
    $result['commission'] = $commission;

    $coefficients = fn_novoton_holidays_calculate_currency_coefficients($bnr_rates, $commission);
    $result['coefficients'] = $coefficients;

    // Step 4: Save to database (sync log) — for reference only
    // CS-Cart currency coefficients are NOT updated here;
    // CS-Cart's own currency conversion addon handles that.
    $timestamp = date('Y-m-d H:i:s');

    db_query(
        "INSERT INTO ?:novoton_sync_log SET sync_date = ?s, sync_type = 'exchange_rates', status = 'completed', "
        . "notes = ?s",
        $timestamp,
        json_encode([
            'bnr_rates' => $bnr_rates,
            'coefficients' => $coefficients,
            'commission' => $commission,
            'publishing_date' => $result['publishing_date'],
        ])
    );

    $result['success'] = true;
    $result['message'] = 'BNR exchange rates fetched and saved to database';
    $result['timestamp'] = $timestamp;

    // Log success
    fn_log_event('general', 'runtime', [
        'message' => sprintf(
            'BNR exchange rates saved: EUR/RON=%s, EUR/USD=%s, EUR/GBP=%s (publishing date: %s)',
            $bnr_rates['EUR'] ?? 'N/A',
            isset($bnr_rates['EUR'], $bnr_rates['USD']) ? round($bnr_rates['EUR'] / $bnr_rates['USD'], 4) : 'N/A',
            isset($bnr_rates['EUR'], $bnr_rates['GBP']) ? round($bnr_rates['EUR'] / $bnr_rates['GBP'], 4) : 'N/A',
            $result['publishing_date']
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
