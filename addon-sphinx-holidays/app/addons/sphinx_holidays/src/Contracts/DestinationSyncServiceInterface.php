<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx destination sync service.
 */
interface DestinationSyncServiceInterface
{
    /**
     * Run a destination sync (countries, regions, cities).
     *
     * @param bool $fullSync Force full sync instead of incremental
     * @return array{success: bool, synced?: int, errors?: int, message?: string}
     */
    public function sync(bool $fullSync): array;
}
