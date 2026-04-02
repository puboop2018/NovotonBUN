<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Contracts;

/**
 * Contract for the Sphinx hotel sync service.
 */
interface HotelSyncServiceInterface
{
    /**
     * Run hotel sync filtered by country codes and/or specific destination IDs.
     *
     * @param string[] $countryCodes        Country codes to filter by
     * @param int[]    $extraDestinationIds  Additional destination IDs to include
     * @param bool     $fullSync            Force full sync instead of incremental
     * @return array{success: bool, synced?: int, errors?: int, message?: string}
     */
    public function sync(array $countryCodes, array $extraDestinationIds, bool $fullSync): array;
}
