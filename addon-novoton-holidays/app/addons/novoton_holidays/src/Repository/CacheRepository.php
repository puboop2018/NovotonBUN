<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Cache repository — wraps novoton_cache table.
 *
 * @since 3.7.0
 */
class CacheRepository implements CacheRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * @return array{cache_data: string, expires_at: int}|null
     */
    public function findByKey(string $key): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT cache_data, expires_at FROM ?:novoton_cache WHERE cache_key = ?s',
            $key,
        ));
        if ($row === []) {
            return null;
        }
        return [
            'cache_data' => TypeCoerce::toString($row['cache_data'] ?? ''),
            'expires_at' => TypeCoerce::toInt($row['expires_at'] ?? 0),
        ];
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
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:novoton_cache'));
    }

    public function countExpired(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_cache WHERE expires_at < ?i',
            time(),
        ));
    }
}
