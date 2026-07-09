<?php

declare(strict_types=1);

namespace {
    // Load travel_core's procedural exchange-rate functions in the GLOBAL
    // namespace, exactly as CS-Cart loads them. Cross-addon require is the
    // point: novoton's integration harness is the only DB-backed harness in
    // this monorepo, and the money-critical write path lives in travel_core.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 6) . '/addon-travel-core/app/addons/travel_core/functions/exchange_rates.php';
}

namespace Tygh\Addons\NovotonHolidays\Tests\Integration {

    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * Proves fn_travel_core_update_cscart_currencies round-trips an EXACT
     * decimal coefficient through a real MySQL currencies column — the write
     * path that silently corrupted in production when the coefficient was
     * bound via the lossy Tygh '?d' placeholder (commit 61de1a5: every
     * exchange-rate batch failed its verify-readback and rolled back,
     * "rates unchanged").
     *
     * The unit guard (UpdateCscartCurrenciesTest) asserts the query SHAPE
     * (`coefficient = ?s` with a full-precision string); only a real database
     * can prove the value SURVIVES storage. This test's harness caveat: the
     * integration binder's ?d arm is lossless, unlike real Tygh's (see
     * bootstrap_integration.php), so a green run here proves the ?s string
     * path — it could NOT have caught the original ?d bug.
     *
     * Deliberately extends plain TestCase, NOT IntegrationTestCase: the
     * function under test issues raw START TRANSACTION / COMMIT, and in MySQL
     * a START TRANSACTION implicitly commits any transaction already open —
     * it would silently break IntegrationTestCase's rollback isolation. The
     * write therefore really commits; isolation comes from snapshotting the
     * touched coefficient and restoring it in a finally block.
     */
    #[Group('integration')]
    class CurrencyWritePathIntegrationTest extends TestCase
    {
        public function testCoefficientRoundTripsExactDecimalThroughRealColumn(): void
        {
            $pdo = _novoton_integration_pdo();

            // Seed-agnostic target: any installed non-primary currency (the
            // function skips primaries without writing). Stock CS-Cart seeds
            // ship several; skip rather than fail on an exotic seed without one.
            $row = $pdo->query(
                "SELECT currency_code, coefficient FROM cscart_currencies
                 WHERE is_primary <> 'Y' ORDER BY currency_code LIMIT 1"
            )->fetch();
            if ($row === false) {
                self::markTestSkipped('Seed DB has no non-primary currency to exercise the write path.');
            }

            $code     = (string) $row['currency_code'];
            $original = (string) $row['coefficient'];

            // 4-dp coefficient exactly as fn_travel_core_calculate_currency_
            // coefficients produces (round(..., 4)). Chosen so that ANY
            // precision-dropping reformat on the way to the column (e.g. a
            // 2-dp cast: 4.9765 -> 4.98, off by 0.0035) exceeds the
            // function's 0.001 verify-readback tolerance — the function
            // itself would roll back and this test would fail on 'success'.
            $target = 4.9765;

            try {
                $results = fn_travel_core_update_cscart_currencies([$code => $target]);

                self::assertTrue(
                    (bool) ($results[$code]['success'] ?? false),
                    'Verify-readback must match on real MySQL — a mismatch means the write lost precision '
                    . 'and the batch rolled back: ' . json_encode($results[$code] ?? null),
                );
                self::assertArrayNotHasKey('rolled_back', (array) $results[$code]);
                self::assertEqualsWithDelta($target, (float) ($results[$code]['new_rate'] ?? 0.0), 1e-9);

                // Independent read outside the function: the COMMITTED column
                // value equals the input exactly.
                $stmt = $pdo->prepare('SELECT coefficient FROM cscart_currencies WHERE currency_code = ?');
                $stmt->execute([$code]);
                $stored = $stmt->fetchColumn();
                self::assertNotFalse($stored, 'currency row disappeared mid-test');
                self::assertEqualsWithDelta(
                    $target,
                    (float) $stored,
                    1e-9,
                    "coefficient must round-trip exactly through the real column (stored: {$stored})",
                );
            } finally {
                // The function committed its own transaction — undo for the
                // rest of the suite by restoring the exact snapshot string.
                $stmt = $pdo->prepare('UPDATE cscart_currencies SET coefficient = ? WHERE currency_code = ?');
                $stmt->execute([$original, $code]);
            }
        }
    }
}
