<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx circuit sync service.
 */
interface CircuitSyncServiceInterface
{
    /**
     * Run circuit sync from the static API.
     *
     * @return array{success: bool, total: int, synced: int, failed: int, duration_ms: int, error: string}
     */
    public function sync(): array;

    /**
     * Install an output callback used for progress logging.
     */
    public function setOutputCallback(\Closure $callback): void;
}
