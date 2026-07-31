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

    /** Rows per multi-row INSERT — keeps statements under max_allowed_packet. */
    private const int UPSERT_CHUNK_SIZE = 200;

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

    /**
     * Upsert many entries in batched multi-row INSERTs.
     *
     * A single Antalya search yields ~1,500 offers, so one INSERT per offer is
     * not viable — this writes them in chunks, matching HotelRepository's
     * upsertBatch pattern.
     *
     * @param array<string, string> $entries cache_key => cache_data
     * @return int Number of entries submitted
     */
    public function upsertMany(array $entries, int $expiresAt): int
    {
        if ($entries === []) {
            return 0;
        }

        $submitted = 0;
        foreach (array_chunk($entries, self::UPSERT_CHUNK_SIZE, true) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $key => $data) {
                if ($key === '') {
                    continue;
                }
                $tuples[] = '(?s, ?s, ?i)';
                array_push($params, $key, $data, $expiresAt);
            }
            if ($tuples === []) {
                continue;
            }

            db_query(
                'INSERT INTO ?:sphinx_cache (cache_key, cache_data, expires_at) VALUES '
                . implode(', ', $tuples)
                . ' ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)',
                ...$params,
            );
            $submitted += count($tuples);
        }

        return $submitted;
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
