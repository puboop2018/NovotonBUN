<?php
declare(strict_types=1);
/**
 * PHPUnit bootstrap for Novoton Holidays INTEGRATION tests.
 *
 * Executed instead of tests/bootstrap.php when running the `integration`
 * testsuite. Defines real db_* functions backed by a PDO connection BEFORE
 * delegating to the unit-test bootstrap — that bootstrap's
 * `!function_exists(...)` guards then skip redefining the stubs, leaving
 * our real implementations in place.
 *
 * Connection is configured via env vars:
 *   DB_DSN   e.g. "mysql:host=127.0.0.1;port=3307;dbname=cscart"
 *   DB_USER  e.g. "cscart"
 *   DB_PASS  e.g. "cscart"
 *
 * Placeholder support covers the subset used by addon queries:
 *   ?:  table prefix (cscart_)
 *   ?i  integer
 *   ?d  float
 *   ?s  string (quoted)
 *   ?e  array => "(`col`, ...) VALUES (v, ...)" (CS-Cart INSERT data map);
 *       scalar kept as quoted string for backward compatibility
 *   ?u  array => "`col` = v, ..." (CS-Cart UPDATE SET map)
 *   ?a  array => quoted string list for IN (...)
 *   ?n  array => integer list for IN (...); scalar => integer
 *
 * Unknown placeholders throw — we would rather fail loudly than silently
 * mis-substitute. Add types here as real queries require them.
 *
 * @package NovotonHolidays\Tests\Integration
 */

// ── PDO helpers ─────────────────────────────────────────────────────────────

if (!function_exists('_novoton_integration_pdo')) {
    function _novoton_integration_pdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $dsn  = getenv('DB_DSN') ?: '';
            $user = getenv('DB_USER') ?: '';
            $pass = getenv('DB_PASS');
            $pass = $pass === false ? '' : $pass;
            if ($dsn === '') {
                throw new \RuntimeException(
                    'DB_DSN env var is not set; integration tests require a live database. '
                    . 'See docker-compose.test.yml.'
                );
            }
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES   => true,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return $pdo;
    }
}

if (!function_exists('_novoton_integration_quote_value')) {
    /** Quote a single value for SQL: NULL, numerics bare, everything else quoted. */
    function _novoton_integration_quote_value(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return _novoton_integration_pdo()->quote((string) $v);
    }
}

if (!function_exists('_novoton_integration_quote_column')) {
    /** Backtick a column name (backticks stripped from input to prevent breakout). */
    function _novoton_integration_quote_column(string $col): string
    {
        return '`' . str_replace('`', '', $col) . '`';
    }
}

if (!function_exists('_novoton_integration_bind')) {
    /**
     * Substitute CS-Cart placeholders (?:, ?i, ?d, ?s, ?e, ?u, ?a, ?n) into a SQL string.
     *
     * @param array<int, mixed> $params
     */
    function _novoton_integration_bind(string $sql, array $params): string
    {
        $pdo    = _novoton_integration_pdo();
        $prefix = 'cscart_';
        $out    = '';
        $p      = 0;
        $i      = 0;
        $len    = strlen($sql);
        while ($p < $len) {
            $ch = $sql[$p];
            if ($ch === '?' && $p + 1 < $len) {
                $type = $sql[$p + 1];
                if ($type === ':') {
                    $out .= $prefix;
                    $p += 2;
                    continue;
                }
                if (in_array($type, ['i', 'd', 's', 'e', 'u', 'a', 'n'], true)) {
                    if (!array_key_exists($i, $params)) {
                        throw new \RuntimeException(
                            "Missing bind parameter #{$i} for placeholder ?{$type} in: {$sql}"
                        );
                    }
                    $v = $params[$i++];
                    $out .= match ($type) {
                        'i' => (string) (int) $v,
                        'd' => (string) (float) $v,
                        's' => $pdo->quote((string) $v),
                        // CS-Cart ?e: INSERT data map. Array => (`cols`) VALUES (vals);
                        // scalar kept as quoted string for backward compatibility.
                        'e' => is_array($v)
                            ? '(' . implode(', ', array_map('_novoton_integration_quote_column', array_keys($v))) . ')'
                              . ' VALUES (' . implode(', ', array_map('_novoton_integration_quote_value', array_values($v))) . ')'
                            : $pdo->quote((string) $v),
                        // CS-Cart ?u: UPDATE SET map.
                        'u' => is_array($v)
                            ? implode(', ', array_map(
                                static fn (string $col): string => _novoton_integration_quote_column($col)
                                    . ' = ' . _novoton_integration_quote_value($v[$col]),
                                array_keys($v),
                            ))
                            : throw new \RuntimeException('?u expects an array (UPDATE SET map)'),
                        // CS-Cart ?a: quoted string list for IN (...).
                        'a' => is_array($v) && $v !== []
                            ? implode(', ', array_map(
                                static fn ($item): string => $pdo->quote((string) $item),
                                array_values($v),
                            ))
                            : throw new \RuntimeException('?a expects a non-empty array'),
                        // CS-Cart ?n: integer list for IN (...); scalar coerced to int.
                        'n' => is_array($v)
                            ? ($v !== []
                                ? implode(', ', array_map(static fn ($item): string => (string) (int) $item, array_values($v)))
                                : throw new \RuntimeException('?n expects a non-empty array or scalar'))
                            : (string) (int) $v,
                    };
                    $p += 2;
                    continue;
                }
                throw new \RuntimeException(
                    "Unsupported CS-Cart placeholder ?{$type} in: {$sql}. "
                    . 'Add support in tests/Integration/bootstrap_integration.php.'
                );
            }
            $out .= $ch;
            $p++;
        }
        return $out;
    }
}

if (!function_exists('_novoton_integration_exec')) {
    /**
     * @param array<int, mixed> $params
     */
    function _novoton_integration_exec(string $sql, array $params): \PDOStatement
    {
        return _novoton_integration_pdo()->query(_novoton_integration_bind($sql, $params));
    }
}

// ── Real db_* implementations (defined BEFORE unit bootstrap) ───────────────

if (!function_exists('db_query')) {
    function db_query(string $query, ...$params)
    {
        $stmt = _novoton_integration_exec($query, $params);
        // CS-Cart's db_query returns the insert id for INSERTs, the affected
        // row count for UPDATE/DELETE/REPLACE, and a resource for SELECTs.
        // The write-path repositories rely on the insert-id branch
        // (BookingRepository::create()); tests don't use the SELECT-resource
        // branch — they use db_get_row / db_get_array.
        if (preg_match('/^\s*INSERT\b/i', $query) === 1) {
            return (int) _novoton_integration_pdo()->lastInsertId();
        }
        return $stmt->rowCount();
    }
}

if (!function_exists('db_get_row')) {
    function db_get_row(string $query, ...$params): array
    {
        $row = _novoton_integration_exec($query, $params)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }
}

if (!function_exists('db_get_field')) {
    function db_get_field(string $query, ...$params)
    {
        $val = _novoton_integration_exec($query, $params)->fetchColumn();
        return $val === false ? null : $val;
    }
}

if (!function_exists('db_get_array')) {
    function db_get_array(string $query, ...$params): array
    {
        return _novoton_integration_exec($query, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }
}

if (!function_exists('db_get_fields')) {
    function db_get_fields(string $query, ...$params): array
    {
        return _novoton_integration_exec($query, $params)->fetchAll(\PDO::FETCH_COLUMN);
    }
}

// ── Finally delegate to the unit-test bootstrap for autoloader + stubs ──────
//
// Its function_exists() guards will skip the no-op db_* stubs because we
// already defined real ones above. Everything else (autoloader, Registry
// eval stubs, __/fn_log_event/etc.) loads normally.
require_once dirname(__DIR__) . '/bootstrap.php';
