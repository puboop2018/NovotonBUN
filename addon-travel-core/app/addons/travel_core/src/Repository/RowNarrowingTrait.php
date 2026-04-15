<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Row-narrowing helpers for repository classes.
 *
 * CS-Cart's `db_get_array()` / `db_get_row()` / `db_get_hash_array()` all
 * return `mixed` (they may fail and return a non-array). Rather than
 * repeat `is_array()` guards at every callsite, repository classes
 * include this trait and call {@see self::asRowList()} / {@see self::asRow()}
 * once as the last step before returning:
 *
 * ```php
 * public function findAll(): array
 * {
 *     return self::asRowList(db_get_array('SELECT ...'));
 * }
 *
 * public function findById(int $id): ?array
 * {
 *     $row = self::asRow(db_get_row('SELECT ... WHERE id = ?i', $id));
 *     return $row === [] ? null : $row;
 * }
 * ```
 *
 * The returned shapes (`list<array<string, mixed>>` / `array<string, mixed>`)
 * satisfy PHPStan strict-array checks and unlock narrower `@return`
 * array-shape annotations on public methods.
 */
trait RowNarrowingTrait
{
    /**
     * Normalise `db_get_array()` output to a list of string-keyed rows.
     *
     * Non-array input and rows that are not themselves arrays are dropped.
     * The outer array is reindexed via `array_values` so the result is a
     * true `list<>`.
     *
     * @return list<array<string, mixed>>
     */
    protected static function asRowList(mixed $rows): array
    {
        return TypeCoerce::toRowList($rows);
    }

    /**
     * Normalise `db_get_row()` output to a string-keyed row.
     *
     * Non-array input returns `[]`. Non-string keys are dropped so callers
     * can rely on `is_string($key)`.
     *
     * @return array<string, mixed>
     */
    protected static function asRow(mixed $row): array
    {
        return TypeCoerce::toStringMap($row);
    }

    /**
     * Normalise `db_get_hash_array()` output to a string-keyed map of rows.
     *
     * Non-array input returns `[]`. Non-string outer keys and non-array
     * values are dropped.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function asRowMap(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $k => $row) {
            if (is_string($k) && is_array($row)) {
                $out[$k] = TypeCoerce::toStringMap($row);
            }
        }
        return $out;
    }

    /**
     * Normalise `db_get_hash_single_array()` output to a string-keyed map
     * of scalars (e.g. `SELECT id, name FROM ... GROUP BY id`).
     *
     * Non-array input returns `[]`. Non-string keys are dropped.
     *
     * @return array<string, mixed>
     */
    protected static function asScalarMap(mixed $rows): array
    {
        return TypeCoerce::toStringMap($rows);
    }
}
