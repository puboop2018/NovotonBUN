<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * File-based run lock for cron dispatchers with stale-lock takeover.
 *
 * Extracted from sphinx's CronDispatcher (which still carries its original
 * inline copy — converge it here when its cron infra is next touched).
 * Novoton's dispatcher had NO run-level mutual exclusion: a scheduled cron
 * overlapping a manual admin trigger could double-fetch/double-write, with
 * only StateManager's per-save flock (not a run lock) between them.
 *
 * Semantics:
 *  - non-blocking exclusive flock; the holder's PID is written for debugging;
 *  - a held lock whose file hasn't been touched in STALE_THRESHOLD seconds
 *    is presumed dead and taken over (single fopen+flock cycle first, so
 *    normal contention never unlinks a live holder's file);
 *  - release() unlocks, closes and removes the file. Call touch() from
 *    long-running loops so stale detection never kills an active run.
 */
final class CronRunLock
{
    /** Maximum lock age before it's considered stale (seconds). */
    public const int STALE_THRESHOLD = 1800; // 30 minutes

    /** @var resource|null */
    private $fp = null;

    public function __construct(private readonly string $lockFile)
    {
    }

    public function acquire(): bool
    {
        $fp = fopen($this->lockFile, 'c'); // create if missing, don't truncate
        if ($fp === false) {
            return false;
        }

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            ftruncate($fp, 0);
            fwrite($fp, (string) getmypid());
            fflush($fp);
            $this->fp = $fp;
            return true;
        }

        // Held — check staleness via the handle we already have (no TOCTOU).
        $stat = fstat($fp);
        fclose($fp);
        if ($stat === false) {
            return false;
        }
        if (time() - (int) $stat['mtime'] <= self::STALE_THRESHOLD) {
            return false; // fresh lock, another process is active
        }

        // Stale — force-acquire. unlink+reopen is safe here: only stale
        // recovery reaches this path and flock still arbitrates the winner.
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
        $fp = fopen($this->lockFile, 'c');
        if ($fp === false) {
            return false;
        }
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            ftruncate($fp, 0);
            fwrite($fp, (string) getmypid());
            fflush($fp);
            $this->fp = $fp;
            return true;
        }

        fclose($fp);
        return false;
    }

    /** Refresh the lock's mtime so stale detection spares an active run. */
    public function touch(): void
    {
        if ($this->fp !== null && file_exists($this->lockFile)) {
            touch($this->lockFile);
        }
    }

    public function release(): void
    {
        if ($this->fp === null) {
            return;
        }
        flock($this->fp, LOCK_UN);
        fclose($this->fp);
        $this->fp = null;
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }
}
