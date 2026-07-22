<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Cache repository — wraps sphinx_cache table.
 *
 * @since 1.2.0
 */
class SphinxCacheRepository
{
    use RowNarrowingTrait;

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT cache_data, expires_at FROM ?:sphinx_cache WHERE cache_key = ?s',
            $key,
        ));
        return $row === [] ? null : $row;
    }

    public function upsert(string $key, string $data, int $expiresAt): void
    {
        db_query(
            'INSERT INTO ?:sphinx_cache (cache_key, cache_data, expires_at)
             VALUES (?s, ?s, ?i)
             ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)',
            $key,
            $data,
            $expiresAt,
        );
    }

    /** @return int Rows removed (db_query returns affected rows for DML). */
    public function deleteByKey(string $key): int
    {
        return TypeCoerce::toInt(db_query('DELETE FROM ?:sphinx_cache WHERE cache_key = ?s', $key));
    }

    /** @return int Expired rows removed. */
    public function deleteExpired(): int
    {
        return TypeCoerce::toInt(db_query('DELETE FROM ?:sphinx_cache WHERE expires_at < ?i', time()));
    }
}
