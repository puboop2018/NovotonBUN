<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Support;

/**
 * Closure-backed stand-ins for CS-Cart's db_* procedural helpers.
 *
 * Mirrors the travel_core DbStub at a different namespace. Extracting to
 * a shared location is deferred until a third consumer materialises
 * (see PR 4 risk note in docs/test-floor plan).
 *
 * The bootstrap routes db_get_field / db_get_row / db_query / db_get_array /
 * db_get_fields through this class. Each test configures the closure it needs
 * and calls reset() in setUp() to prevent cross-test pollution.
 */
final class DbStub
{
    /** @var (callable(string, mixed...): mixed)|null */
    public static $getField = null;

    /** @var (callable(string, mixed...): array<string, mixed>)|null */
    public static $getRow = null;

    /** @var (callable(string, mixed...): list<array<string, mixed>>)|null */
    public static $getArray = null;

    /** @var (callable(string, mixed...): list<mixed>)|null */
    public static $getFields = null;

    /** @var (callable(string, mixed...): int)|null */
    public static $query = null;

    public static function reset(): void
    {
        self::$getField = null;
        self::$getRow = null;
        self::$getArray = null;
        self::$getFields = null;
        self::$query = null;
    }
}
