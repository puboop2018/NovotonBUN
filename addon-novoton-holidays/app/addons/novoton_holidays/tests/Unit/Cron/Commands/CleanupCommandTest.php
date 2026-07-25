<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\CleanupCommand;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Behavioural coverage for the cleanup cron's cache step.
 *
 * The old inline sweep compared the INT unix `expires_at` column against
 * NOW() — MySQL evaluates that numerically against NOW()'s
 * 20260101000000-style DATETIME form, so the predicate was ALWAYS true:
 * every run wiped live cache rows, and the file backend (the default
 * storage) was never pruned at all. The step now delegates to
 * CacheService::cleanup() for the configured backend plus
 * CacheRepository::deleteExpired() for the table, both time()-based.
 */
#[CoversClass(CleanupCommand::class)]
final class CleanupCommandTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    private string $cacheDir = '';

    protected function setUp(): void
    {
        DbStub::reset();
        $this->out = [];
        $this->queries = [];
        $this->cacheDir = DIR_ROOT . '/var/cache/novoton/';

        DbStub::$query = function (string $query, ...$params): int {
            $this->queries[] = [$query, $params];

            return 2;
        };
        DbStub::$getField = static fn (string $query, ...$params): string => '0';
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        foreach (glob($this->cacheDir . '*/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function command(): CleanupCommand
    {
        $cmd = (new \ReflectionClass(CleanupCommand::class))->newInstanceWithoutConstructor();

        $startProp = new \ReflectionProperty(\Tygh\Addons\TravelCore\Cron\AbstractCronCommand::class, 'startTime');
        $startProp->setAccessible(true);
        $startProp->setValue($cmd, microtime(true));

        $cmd->setOutputCallback(function (string $msg, bool $addNewline = true): void {
            $this->out[] = $msg;
        });

        return $cmd;
    }

    public function testCacheSweepComparesUnixStampsNeverNow(): void
    {
        $before = time();
        $result = $this->command()->execute();
        self::assertTrue((bool) ($result['success'] ?? false));

        // ?:novoton_cache.expires_at is an INT unix stamp — no cache query
        // may compare it against NOW(). (The orphan-booking sweep legitimately
        // uses NOW() against its DATETIME created_at column.)
        foreach ($this->queries as [$sql]) {
            if (str_contains($sql, '?:novoton_cache')) {
                self::assertStringNotContainsString('NOW()', $sql, 'INT unix stamps must never be compared against NOW()');
            }
        }

        $cacheDeletes = array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q[0], 'DELETE FROM ?:novoton_cache'),
        ));
        self::assertCount(1, $cacheDeletes, 'exactly one table sweep');
        self::assertSame('DELETE FROM ?:novoton_cache WHERE expires_at < ?i', $cacheDeletes[0][0]);
        $stamp = $cacheDeletes[0][1][0] ?? null;
        self::assertIsInt($stamp);
        self::assertGreaterThanOrEqual($before, $stamp);
        self::assertLessThanOrEqual(time(), $stamp, 'the cutoff is "now", not a datetime coercion');
    }

    public function testFileBackendIsPrunedAndLiveEntriesSurvive(): void
    {
        // Seed one expired and one live entry in the file backend's on-disk
        // format (CacheService::cleanup() reads the 'expires' key).
        if (!is_dir($this->cacheDir . 'aa')) {
            mkdir($this->cacheDir . 'aa', 0o755, true);
        }
        $expired = $this->cacheDir . 'aa/expired.cache';
        $live = $this->cacheDir . 'aa/live.cache';
        file_put_contents($expired, (string) json_encode(['expires' => time() - 60, 'data' => 'x']));
        file_put_contents($live, (string) json_encode(['expires' => time() + 600, 'data' => 'y']));

        $result = $this->command()->execute();

        self::assertFileDoesNotExist($expired, 'expired file-cache entries are removed by the cron');
        self::assertFileExists($live, 'live file-cache entries survive the sweep');
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
        self::assertArrayHasKey('cache_deleted', $stats);
        self::assertStringContainsString('(database table)', implode("\n", $this->out));
    }
}
