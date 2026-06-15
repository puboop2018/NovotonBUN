<?php

declare(strict_types=1);

namespace {
    // Load the procedural functions-under-test in the GLOBAL namespace, as
    // CS-Cart loads them.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!function_exists('fn_log_event')) {
        function fn_log_event(string $type, string $action, array $data = []): void
        {
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/exchange_rates.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;

    /**
     * Characterization coverage for the exchange-rate helpers, added with the
     * boundary-typing paydown (BNR/DB-sourced mixed values now coerced through
     * TypeCoerce). Pins the BNR XML parse (incl. multiplier + currency filter),
     * the EUR-based coefficient maths (and that string rates coerce identically),
     * and the plain-text output formatting.
     */
    final class ExchangeRatesTest extends TestCase
    {
        private const string XML = '<?xml version="1.0"?>'
            . '<DataSet><Body><Cube date="2026-06-15">'
            . '<Rate currency="EUR">4.9750</Rate>'
            . '<Rate currency="USD">4.5800</Rate>'
            . '<Rate currency="HUF" multiplier="100">1.2500</Rate>'
            . '</Cube></Body></DataSet>';

        public function testParseExtractsRequestedCurrencies(): void
        {
            $rates = fn_travel_core_parse_bnr_xml(self::XML, ['EUR', 'USD']);

            $this->assertSame(4.975, $rates['EUR']);
            $this->assertSame(4.58, $rates['USD']);
            $this->assertArrayNotHasKey('HUF', $rates); // not requested
        }

        public function testParseAppliesMultiplier(): void
        {
            $rates = fn_travel_core_parse_bnr_xml(self::XML, ['HUF']);

            $this->assertSame(0.0125, $rates['HUF']); // 1.25 / 100
        }

        public function testParseWithPublishingDate(): void
        {
            $parsed = fn_travel_core_parse_bnr_xml(self::XML, ['EUR'], true);

            $this->assertSame('2026-06-15', $parsed['publishing_date']);
            $this->assertSame(4.975, $parsed['rates']['EUR']);
        }

        public function testCalculateCoefficients(): void
        {
            $coeff = fn_travel_core_calculate_currency_coefficients(
                ['EUR' => 4.975, 'USD' => 4.58, 'GBP' => 5.8],
                0,
            );

            $this->assertSame(4.975, $coeff['RON']);
            $this->assertSame(1.0862, $coeff['USD']); // round(4.975/4.58, 4)
            $this->assertSame(0.8578, $coeff['GBP']); // round(4.975/5.8, 4)
        }

        public function testCalculateCoercesStringRatesAndAppliesCommission(): void
        {
            // String rates (as they arrive from some sources) coerce identically;
            // 2% commission scales the RON coefficient.
            $coeff = fn_travel_core_calculate_currency_coefficients(['EUR' => '4.975'], 2);

            $this->assertSame(5.0745, $coeff['RON']); // round(4.975 * 1.02, 4)
        }

        public function testCalculateEmptyWithoutEur(): void
        {
            $this->assertSame([], fn_travel_core_calculate_currency_coefficients([], 0));
        }

        public function testFormatOutput(): void
        {
            $out = fn_travel_core_format_exchange_rate_output([
                'success' => true,
                'message' => 'Exchange rates updated successfully',
                'coefficients' => ['RON' => 4.975],
            ]);

            $this->assertStringContainsString('Status: SUCCESS', $out);
            $this->assertStringContainsString('Message: Exchange rates updated successfully', $out);
            $this->assertStringContainsString('RON: 4.975', $out);
        }

        public function testFormatOutputFailure(): void
        {
            $out = fn_travel_core_format_exchange_rate_output(['success' => false, 'message' => 'Failed to fetch']);

            $this->assertStringContainsString('Status: FAILED', $out);
            $this->assertStringContainsString('Message: Failed to fetch', $out);
        }
    }
}
