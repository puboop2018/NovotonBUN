<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\Commands\StatefulCommandTrait;

/**
 * Concrete consumer of StatefulCommandTrait used purely for testing. Mirrors
 * the const contract real cron commands must satisfy and re-exposes the trait's
 * private surface so the behaviour can be characterized.
 */
final class StatefulTraitTestDouble
{
    use StatefulCommandTrait;

    private const string STATE_FILE_NAME = 'sphinx_test_stateful_trait_state.json';
    private const float STALE_HOURS = 0.5;
    private const array DEFAULT_STATE = [
        'status' => 'idle',
        'next_page' => 1,
        'synced' => 0,
        'total' => 0,
    ];

    public function pubGetStatePath(): string
    {
        return $this->getStatePath();
    }

    /** @return array<string, mixed> */
    public function pubLoadState(): array
    {
        return $this->loadState();
    }

    /** @param array<string, mixed> $state */
    public function pubSaveState(array $state): void
    {
        $this->saveState($state);
    }

    public function pubClearState(): void
    {
        $this->clearState();
    }

    /** @param array<string, mixed> $state */
    public function pubIsStale(array $state): bool
    {
        return $this->isStale($state);
    }

    public function pubFormatDuration(int $seconds): string
    {
        return $this->formatDuration($seconds);
    }
}

/**
 * Characterization coverage for StatefulCommandTrait, pinned with the
 * boundary-typing paydown that coerced the trait's `mixed` reads (DIR_CACHE
 * concatenation, the json_decode'd state merge, and the strtotime staleness
 * check). DIR_CACHE is undefined under the test harness, so getStatePath()
 * exercises the sys_get_temp_dir() fallback branch.
 */
final class StatefulCommandTraitTest extends TestCase
{
    private StatefulTraitTestDouble $sut;

    protected function setUp(): void
    {
        $this->sut = new StatefulTraitTestDouble();
        $this->sut->pubClearState(); // ensure no leftover state file from a prior run
    }

    protected function tearDown(): void
    {
        $this->sut->pubClearState();
    }

    public function testGetStatePathEndsWithStateFileName(): void
    {
        $this->assertStringEndsWith('sphinx_test_stateful_trait_state.json', $this->sut->pubGetStatePath());
    }

    public function testLoadStateReturnsDefaultsWhenNoFileExists(): void
    {
        $state = $this->sut->pubLoadState();

        $this->assertSame('idle', $state['status']);
        $this->assertSame(1, $state['next_page']);
    }

    public function testSaveThenLoadRoundTripsAndMergesOverDefaults(): void
    {
        $this->sut->pubSaveState([
            'status' => 'in_progress',
            'next_page' => 5,
            'synced' => 120,
        ]);

        $state = $this->sut->pubLoadState();

        // Persisted keys win…
        $this->assertSame('in_progress', $state['status']);
        $this->assertSame(5, $state['next_page']);
        $this->assertSame(120, $state['synced']);
        // …and untouched defaults survive the merge.
        $this->assertSame(0, $state['total']);
    }

    public function testClearStateResetsToDefaults(): void
    {
        $this->sut->pubSaveState(['status' => 'in_progress', 'next_page' => 9]);
        $this->sut->pubClearState();

        $state = $this->sut->pubLoadState();
        $this->assertSame('idle', $state['status']);
        $this->assertSame(1, $state['next_page']);
    }

    public function testIsStaleFalseWhenNotInProgress(): void
    {
        $this->assertFalse($this->sut->pubIsStale(['status' => 'idle', 'last_run_at' => '2000-01-01 00:00:00']));
    }

    public function testIsStaleTrueForOldActivity(): void
    {
        $this->assertTrue($this->sut->pubIsStale([
            'status' => 'in_progress',
            'last_run_at' => date('Y-m-d H:i:s', time() - 7200), // 2h ago > 0.5h threshold
        ]));
    }

    public function testIsStaleFalseForRecentActivity(): void
    {
        $this->assertFalse($this->sut->pubIsStale([
            'status' => 'in_progress',
            'last_run_at' => date('Y-m-d H:i:s', time() - 60), // 1m ago < 0.5h threshold
        ]));
    }

    public function testIsStaleTrueWhenNoTimestamp(): void
    {
        $this->assertTrue($this->sut->pubIsStale(['status' => 'in_progress']));
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('45s', $this->sut->pubFormatDuration(45));
        $this->assertSame('1m 30s', $this->sut->pubFormatDuration(90));
        $this->assertSame('1h 1m', $this->sut->pubFormatDuration(3661));
    }
}
