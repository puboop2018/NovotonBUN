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

        /** @var array<string, array<string, mixed>> currency_code => currencies row */
        private array $rows = [];

        /** @var array<string, float> currency_code => value the verify-readback returns */
        private array $stored = [];

        protected function setUp(): void
        {
            DbStub::reset();
            $this->queries = [];

            $this->rows = [
                'EUR' => ['currency_code' => 'EUR', 'is_primary' => 'Y', 'coefficient' => 1.0],
                'RON' => ['currency_code' => 'RON', 'is_primary' => 'N', 'coefficient' => 4.90],
                'USD' => ['currency_code' => 'USD', 'is_primary' => 'N', 'coefficient' => 1.05],
            ];

            DbStub::$getRow = fn (string $query, ...$params): array
                => $this->rows[(string) ($params[0] ?? '')] ?? [];

            DbStub::$query = function (string $query, ...$params): int {
                $this->queries[] = $query;
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
