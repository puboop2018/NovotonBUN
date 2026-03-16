#!/usr/bin/env php
<?php
/**
 * Standalone test for PriceChangeDetector — the "No Surprises" price change system.
 *
 * Works in TWO modes:
 *
 *   CLI:     php scripts/test_price_change_detector.php
 *   Browser: https://yourdomain.com/scripts/test_price_change_detector.php?key=novoton_test_2024
 *
 * SECURITY: Change the key below before deploying. Delete this file when done.
 *
 * This script tests the core analyse() logic without needing CS-Cart or a database.
 * It stubs only the minimal dependencies (ConfigProvider, Tygh session).
 */

declare(strict_types=1);

// ── Security: browser access requires a secret key ──
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $secretKey = getenv('PRICE_DETECTOR_TEST_KEY') ?: '';
    if (empty($secretKey)) {
        http_response_code(500);
        echo 'PRICE_DETECTOR_TEST_KEY environment variable is not set.';
        exit(1);
    }
    if (($_GET['key'] ?? '') !== $secretKey) {
        http_response_code(403);
        echo 'Forbidden. Append ?key=YOUR_SECRET_KEY to the URL.';
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Minimal stubs so PriceChangeDetector can run outside CS-Cart ──

namespace Tygh\Addons\NovotonHolidays\Services {
    // Stub ConfigProvider::get() to return a controllable tolerance value.
    // The real class is loaded from src/ — we define it here FIRST so PHP uses our stub.
    class ConfigProvider
    {
        private static float $tolerance = 1.0;

        public static function setTolerance(float $pct): void
        {
            self::$tolerance = $pct;
        }

        /** @return mixed */
        public static function get(string $key, $default = null)
        {
            if ($key === 'price_change_tolerance_percent') {
                return self::$tolerance;
            }
            return $default;
        }
    }
}

namespace Tygh {
    // Stub Tygh::$app['session'] as a simple array-backed store.
    class Tygh
    {
        /** @var array */
        public static $app = [];
    }
    Tygh::$app = ['session' => []];
}

namespace {
    // Now load the real PriceChangeDetector (it will see our stubs above).
    require_once __DIR__ . '/../app/addons/novoton_holidays/src/Services/PriceChangeDetector.php';

    use Tygh\Addons\NovotonHolidays\Services\PriceChangeDetector;
    use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
    use Tygh\Tygh;

    // ═══════════════════════════════════════════════════════════════
    // Test harness
    // ═══════════════════════════════════════════════════════════════

    $passed = 0;
    $failed = 0;
    $tests  = [];

    function assert_eq($label, $expected, $actual): void
    {
        global $passed, $failed, $tests;
        $ok = $expected === $actual;
        if ($ok) {
            $passed++;
            $tests[] = "  PASS  {$label}";
        } else {
            $failed++;
            $expectedStr = var_export($expected, true);
            $actualStr   = var_export($actual, true);
            $tests[] = "  FAIL  {$label}\n         expected: {$expectedStr}\n         got:      {$actualStr}";
        }
    }

    function assert_true($label, $actual): void  { assert_eq($label, true, $actual); }
    function assert_false($label, $actual): void { assert_eq($label, false, $actual); }

    function section(string $title): void
    {
        global $tests;
        $tests[] = "\n--- {$title} ---";
    }

    // Reset session between tests
    function reset_session(): void
    {
        Tygh::$app['session'] = [];
    }

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 1: Price goes UP by > 1% (significant increase)
    // Customer sees $100, API returns $110 → orange badge, Old vs New
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 1: Significant Price Increase ($100 -> $110, +10%)');
    ConfigProvider::setTolerance(1.0);
    $d = new PriceChangeDetector();
    $result = $d->analyse(100.0, 110.0, 'EUR', 'add_to_cart', ['hotel_name' => 'Test Hotel']);

    assert_true('significant = true',                $result['significant']);
    assert_eq('direction = increase',    'increase', $result['direction']);
    assert_eq('old_price = 100',              100.0, $result['old_price']);
    assert_eq('new_price = 110',              110.0, $result['new_price']);
    assert_eq('difference = 10',               10.0, $result['difference']);
    assert_eq('percent = 10',                  10.0, $result['percent']);
    assert_eq('badge_type = warning',     'warning', $result['badge_type']);
    assert_eq('context = add_to_cart', 'add_to_cart', $result['context']);
    assert_eq('currency = EUR',              'EUR',  $result['currency']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 2: Price goes DOWN by > 1% (significant decrease)
    // Customer sees $500, API returns $450 → green badge, "Price Dropped!"
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 2: Significant Price Decrease ($500 -> $450, -10%)');
    $result = $d->analyse(500.0, 450.0, 'RON', 'checkout');

    assert_true('significant = true',               $result['significant']);
    assert_eq('direction = decrease',   'decrease',  $result['direction']);
    assert_eq('difference = -50',            -50.0,  $result['difference']);
    assert_eq('percent = 10',                 10.0,  $result['percent']);
    assert_eq('badge_type = success',    'success',  $result['badge_type']);
    assert_eq('context = checkout',     'checkout',  $result['context']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 3: Tiny change below tolerance (silent update)
    // Customer sees 450 Lei, API returns 452 Lei (+0.44%) → no alert
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 3: Below Tolerance -- 450 -> 452 Lei (+0.44%, < 1%)');
    $result = $d->analyse(450.0, 452.0, 'RON');

    assert_false('significant = false',              $result['significant']);
    assert_eq('direction = increase',    'increase', $result['direction']);
    assert_eq('badge_type = none',          'none',  $result['badge_type']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 4: Exact same price → no change
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 4: No Change -- 300 -> 300');
    $result = $d->analyse(300.0, 300.0, 'EUR');

    assert_false('significant = false',             $result['significant']);
    assert_eq('direction = none',          'none',  $result['direction']);
    assert_eq('difference = 0',              0.0,   $result['difference']);
    assert_eq('badge_type = none',        'none',   $result['badge_type']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 5: Edge — exactly at 1% threshold
    // 1000 → 1010 = exactly 1.0% → should BE significant
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 5: Exactly at Tolerance -- 1000 -> 1010 (1.0%)');
    $result = $d->analyse(1000.0, 1010.0, 'EUR');

    assert_true('significant = true at boundary',   $result['significant']);
    assert_eq('percent = 1.0',                1.0,  $result['percent']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 6: Just below 1% threshold
    // 1000 → 1009 = 0.9% → NOT significant
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 6: Just Below Tolerance -- 1000 -> 1009 (0.9%)');
    $result = $d->analyse(1000.0, 1009.0, 'EUR');

    assert_false('significant = false below boundary', $result['significant']);
    assert_eq('badge_type = none',           'none',   $result['badge_type']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 7: isSignificant() helper method
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 7: isSignificant() helper');
    assert_true('100->110 is significant',    $d->isSignificant(100.0, 110.0));
    assert_false('100->100.5 is NOT',         $d->isSignificant(100.0, 100.5));
    assert_true('100->90 is significant',     $d->isSignificant(100.0, 90.0));
    assert_false('0->100 (zero old price)',   $d->isSignificant(0.0, 100.0));

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 8: Custom tolerance — set to 5%
    // 100 → 103 (+3%) should be silent at 5% tolerance
    // 100 → 106 (+6%) should be significant at 5% tolerance
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 8: Custom 5% Tolerance');
    ConfigProvider::setTolerance(5.0);
    $d2 = new PriceChangeDetector();

    $result = $d2->analyse(100.0, 103.0, 'EUR');
    assert_false('3% change silent at 5% tol', $result['significant']);

    $result = $d2->analyse(100.0, 106.0, 'EUR');
    assert_true('6% change significant at 5% tol', $result['significant']);

    ConfigProvider::setTolerance(1.0); // restore

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 9: Session alert storage (store → peek → consume)
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 9: Session Alert Storage');
    reset_session();
    $d3 = new PriceChangeDetector();

    $alert1 = $d3->analyse(100.0, 120.0, 'EUR', 'add_to_cart');
    $d3->storeAlert($alert1, 'cart_abc123');

    $alert2 = $d3->analyse(500.0, 480.0, 'RON', 'checkout');
    $d3->storeAlert($alert2, 'cart_def456');

    $peeked = $d3->peekAlerts();
    assert_eq('peek returns 2 alerts', 2, count($peeked));
    assert_true('cart_abc123 exists', isset($peeked['cart_abc123']));
    assert_true('cart_def456 exists', isset($peeked['cart_def456']));

    // peek does NOT clear
    $peeked2 = $d3->peekAlerts();
    assert_eq('peek again still 2', 2, count($peeked2));

    // consume DOES clear
    $consumed = $d3->consumeAlerts();
    assert_eq('consume returns 2', 2, count($consumed));
    $remaining = $d3->peekAlerts();
    assert_eq('after consume, 0 remain', 0, count($remaining));

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 10: Real-world example — Novoton booking
    // Standard Room: 450 Lei → 480 Lei at checkout
    // Should show: ~~450 Lei~~ 480 Lei with orange badge
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 10: Real-world -- Standard Room 450 -> 480 Lei');
    $result = $d->analyse(450.0, 480.0, 'RON', 'checkout', [
        'hotel_name' => 'Hotel Paradise',
        'hotel_id'   => 'HP001',
        'room_id'    => 'DBL 2+1)',
    ]);

    assert_true('significant',                                $result['significant']);
    assert_eq('direction = increase',             'increase', $result['direction']);
    assert_eq('difference = 30',                       30.0,  $result['difference']);
    assert_eq('percent = 6.67',                        6.67,  $result['percent']);
    assert_eq('badge_type = warning',              'warning', $result['badge_type']);
    assert_eq('hotel_name preserved', 'Hotel Paradise',       $result['booking_meta']['hotel_name']);

    // ═══════════════════════════════════════════════════════════════
    // SCENARIO 11: Price Fatigue — three small changes
    // If price bounces 100→100.30→100.60→100.90, all < 1%, all silent
    // ═══════════════════════════════════════════════════════════════

    section('SCENARIO 11: Price Fatigue -- repeated small changes stay silent');
    assert_false('100->100.30 (0.3%)', $d->isSignificant(100.0, 100.30));
    assert_false('100.30->100.60',     $d->isSignificant(100.30, 100.60));
    assert_false('100.60->100.90',     $d->isSignificant(100.60, 100.90));

    // ═══════════════════════════════════════════════════════════════
    // Print results
    // ═══════════════════════════════════════════════════════════════

    echo "\n========================================\n";
    echo " PriceChangeDetector Test Results\n";
    echo " PHP " . PHP_VERSION . " | " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    foreach ($tests as $line) {
        echo $line . "\n";
    }
    echo "\n========================================\n";
    if ($failed === 0) {
        echo " ALL PASSED: {$passed}/{$passed}\n";
    } else {
        echo " FAILURES: {$failed} failed, {$passed} passed\n";
    }
    echo "========================================\n";

    if (!$isCli) {
        echo "\nReminder: DELETE this test file from your server when done.\n";
    }

    exit($failed > 0 ? 1 : 0);
}
