<?php

declare(strict_types=1);

namespace {
    // Load the procedural functions-under-test in the GLOBAL namespace, as
    // CS-Cart loads them.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/functions/exchange_rates.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\TravelCore\Tests\Support\DbStub;

    /**
     * Guards the atomicity of fn_travel_core_update_cscart_currencies (audit M2):
     * every coefficient UPDATE runs inside ONE transaction, and a verify-readback
     * mismatch rolls the whole batch back — customer-facing prices must never mix
     * old and new exchange rates.
     */
    final class UpdateCscartCurrenciesTest extends TestCase
    {
        /** @var list<string> */
        private array $queries = [];

        /** @var list<array<int, mixed>> params passed alongside each query, index-aligned with $queries */
        private array $queryParams = [];

        /** @var array<string, array<string, mixed>> currency_code => currencies row */
        private array $rows = [];

        /** @var array<string, float> currency_code => value the verify-readback returns */
        private array $stored = [];

        protected function setUp(): void
        {
            DbStub::reset();
            $this->queries = [];
            $this->queryParams = [];

            $this->rows = [
                'EUR' => ['currency_code' => 'EUR', 'is_primary' => 'Y', 'coefficient' => 1.0],
                'RON' => ['currency_code' => 'RON', 'is_primary' => 'N', 'coefficient' => 4.90],
                'USD' => ['currency_code' => 'USD', 'is_primary' => 'N', 'coefficient' => 1.05],
            ];

            DbStub::$getRow = fn (string $query, ...$params): array
                => $this->rows[(string) ($params[0] ?? '')] ?? [];

            DbStub::$query = function (string $query, ...$params): int {
                $this->queries[] = $query;
                $this->queryParams[] = $params;
                return 1;
            };

            DbStub::$getField = fn (string $query, ...$params): float
                => $this->stored[(string) ($params[0] ?? '')] ?? 0.0;
        }

        protected function tearDown(): void
        {
            DbStub::reset();
        }

        private function countQueries(string $needle): int
        {
            return count(array_filter(
                $this->queries,
                static fn (string $q): bool => str_contains($q, $needle),
            ));
        }

        public function testAllUpdatesCommitInsideOneTransaction(): void
        {
            $this->stored = ['RON' => 4.9750, 'USD' => 1.0862];

            $results = fn_travel_core_update_cscart_currencies(['RON' => 4.9750, 'USD' => 1.0862]);

            self::assertSame(1, $this->countQueries('START TRANSACTION'));
            self::assertSame(1, $this->countQueries('COMMIT'));
            self::assertSame(0, $this->countQueries('ROLLBACK'));
            self::assertSame(2, $this->countQueries('UPDATE ?:currencies'));

            self::assertSame(true, $results['RON']['success']);
            self::assertSame(true, $results['USD']['success']);
            self::assertSame(4.9750, $results['RON']['new_rate']);
        }

        public function testReadbackMismatchRollsBackTheWholeBatch(): void
        {
            // RON stores correctly; USD's readback disagrees → the WHOLE batch
            // must roll back so the store never runs on mixed rates.
            $this->stored = ['RON' => 4.9750, 'USD' => 9.99];

            $results = fn_travel_core_update_cscart_currencies(['RON' => 4.9750, 'USD' => 1.0862]);

            self::assertSame(1, $this->countQueries('START TRANSACTION'));
            self::assertSame(1, $this->countQueries('ROLLBACK'));
            self::assertSame(0, $this->countQueries('COMMIT'));

            // Both currencies report failure — not just the mismatched one.
            self::assertSame(false, $results['RON']['success']);
            self::assertSame(false, $results['USD']['success']);
            self::assertSame(true, $results['RON']['rolled_back']);
            self::assertSame(true, $results['USD']['rolled_back']);
        }

        /**
         * Regression (devx: "Currency coefficient update was rolled back — rates
         * unchanged"): the coefficient MUST be bound as an explicit locale-safe
         * decimal string, never via the Tygh '?d' placeholder. '?d' reformats the
         * value so the verify-readback disagreed and rolled every batch back. This
         * class of bug is invisible to the DbStub (it can't emulate the real DB
         * placeholder), so guard it structurally: assert the UPDATE binds
         * `coefficient = ?s` with a full-precision decimal string.
         */
        public function testCoefficientIsBoundAsExplicitDecimalStringNotViaDdPlaceholder(): void
        {
            $this->stored = ['RON' => 4.9765];

            fn_travel_core_update_cscart_currencies(['RON' => 4.9765]);

            $updateIndex = null;
            foreach ($this->queries as $i => $q) {
                if (str_contains($q, 'UPDATE ?:currencies')) {
                    $updateIndex = $i;
                    break;
                }
            }
            self::assertNotNull($updateIndex, 'expected an UPDATE ?:currencies query');

            $updateQuery = $this->queries[$updateIndex];
            self::assertStringContainsString(
                'coefficient = ?s',
                $updateQuery,
                'coefficient must be bound as a string (?s), not the lossy ?d placeholder',
            );
            self::assertStringNotContainsString(
                'coefficient = ?d',
                $updateQuery,
                'the ?d placeholder reformats the decimal and rolls the batch back — do not use it here',
            );

            // The first bound param is the coefficient: a decimal string that
            // preserves full precision (float value equals the input exactly).
            $coefParam = $this->queryParams[$updateIndex][0] ?? null;
            self::assertIsString($coefParam, 'coefficient param must be a formatted decimal string');
            self::assertSame(4.9765, (float) $coefParam, 'the written decimal must preserve full precision');
        }

        public function testPrimaryAndUnknownCurrenciesSkipWithoutTransaction(): void
        {
            $results = fn_travel_core_update_cscart_currencies(['EUR' => 1.0, 'GBP' => 0.85]);

            // Nothing to write → no transaction at all.
            self::assertSame(0, $this->countQueries('START TRANSACTION'));
            self::assertSame(0, $this->countQueries('UPDATE ?:currencies'));

            // Primary currency → unchanged; not-installed currency → soft skip,
            // NOT a rolled-back failure.
            self::assertSame(true, $results['EUR']['success']);
            self::assertSame(false, $results['GBP']['success']);
            self::assertArrayNotHasKey('rolled_back', $results['GBP']);
        }
    }
}
