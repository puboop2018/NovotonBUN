<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

/**
 * Cache repository — wraps sphinx_cache table.
 *
 * @since 1.2.0
 */
class SphinxCacheRepository
{
    public function findByKey(string $key): ?array
    {
        $row = db_get_row(
            "SELECT cache_data, expires_at FROM ?:sphinx_cache WHERE cache_key = ?s",
            $key
        );
        return $row ?: null;
    }

    public function upsert(string $key, string $data, int $expiresAt): void
    {
        db_query(
            "INSERT INTO ?:sphinx_cache (cache_key, cache_data, expires_at)
             VALUES (?s, ?s, ?i)
             ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)",
            $key,
            $data,
            $expiresAt
        );
    }

    public function deleteByKey(string $key): void
    {
        db_query("DELETE FROM ?:sphinx_cache WHERE cache_key = ?s", $key);
    }

    public function deleteExpired(): void
    {
        db_query("DELETE FROM ?:sphinx_cache WHERE expires_at < ?i", time());
    }
}
