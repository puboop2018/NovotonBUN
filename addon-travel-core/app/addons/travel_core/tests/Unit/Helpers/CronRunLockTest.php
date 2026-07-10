<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\CronRunLock;

/**
 * Run-level cron mutual exclusion (ported from sphinx's dispatcher; novoton
 * now uses this shared class). flock semantics are per file HANDLE, so
 * contention is exercised with two lock instances over the same path.
 */
class CronRunLockTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = sys_get_temp_dir() . '/travel_cron_lock_test_' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    public function testAcquireWritesPidAndBlocksSecondHolder(): void
    {
        $first = new CronRunLock($this->lockFile);
        $second = new CronRunLock($this->lockFile);

        self::assertTrue($first->acquire());
        self::assertSame((string) getmypid(), file_get_contents($this->lockFile));
        self::assertFalse($second->acquire(), 'a fresh lock must not be acquirable twice');

        $first->release();
        self::assertFileDoesNotExist($this->lockFile, 'release must remove the lock file');
        self::assertTrue($second->acquire(), 'after release the lock is free');
        $second->release();
    }

    public function testStaleLockIsTakenOver(): void
    {
        $holder = new CronRunLock($this->lockFile);
        self::assertTrue($holder->acquire());

        // Age the lock past the stale threshold without releasing it.
        touch($this->lockFile, time() - CronRunLock::STALE_THRESHOLD - 60);
        clearstatcache();

        $taker = new CronRunLock($this->lockFile);
        self::assertTrue($taker->acquire(), 'a lock idle past the threshold must be recoverable');
        $taker->release();
    }

    public function testTouchKeepsAnActiveRunAlive(): void
    {
        $holder = new CronRunLock($this->lockFile);
        self::assertTrue($holder->acquire());

        // Simulate an old-but-active run: aged file, then the run touches it.
        touch($this->lockFile, time() - CronRunLock::STALE_THRESHOLD - 60);
        clearstatcache();
        $holder->touch();
        clearstatcache();

        $taker = new CronRunLock($this->lockFile);
        self::assertFalse($taker->acquire(), 'a touched (active) lock must not be stolen');
        $holder->release();
    }
}
