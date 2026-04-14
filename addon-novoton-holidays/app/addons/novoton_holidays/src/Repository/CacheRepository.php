<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

/**
 * Cache repository — wraps novoton_cache table.
 *
 * @since 3.7.0
 */
class CacheRepository implements CacheRepositoryInterface
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public function findByKey(string $key): ?array
    {
        $row = db_get_row(
            'SELECT cache_data, expires_at FROM ?:novoton_cache WHERE cache_key = ?s',
            $key,
        );
        return $row ?: null;
    }

    public function upsert(string $key, string $data, int $expiresAt): void
    {
        db_query(
            'REPLACE INTO ?:novoton_cache SET ?u',
            [
                'cache_key' => $key,
                'cache_data' => $data,
                'expires_at' => $expiresAt,
                'created_at' => time(),
            ],
        );
    }

    public function deleteByKey(string $key): void
    {
        db_query('DELETE FROM ?:novoton_cache WHERE cache_key = ?s', $key);
    }

    public function deleteAll(?string $prefix = null): int
    {
        if ($prefix === null) {
            return (int) db_query('DELETE FROM ?:novoton_cache');
        }
        return (int) db_query('DELETE FROM ?:novoton_cache WHERE cache_key LIKE ?l', $prefix . '%');
    }

    public function deleteExpired(): int
    {
        return (int) db_query('DELETE FROM ?:novoton_cache WHERE expires_at < ?i', time());
    }

    public function countAll(): int
    {
        return (int) db_get_field('SELECT COUNT(*) FROM ?:novoton_cache');
    }

    public function countExpired(): int
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_cache WHERE expires_at < ?i',
            time(),
        );
    }
}
