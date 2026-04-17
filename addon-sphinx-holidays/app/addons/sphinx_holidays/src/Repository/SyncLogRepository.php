<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_sync_log table.
 *
 * @since 1.4.0
 */
class SyncLogRepository
{
    use RowNarrowingTrait;

    /**
     * Get recent sync log entries, ordered by most recent first.
     *
     * @param int $limit Maximum number of entries to return
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 10): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:sphinx_sync_log ORDER BY started_at DESC LIMIT ?i',
            $limit,
        ));
    }
}
