<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Support;

/**
 * Spy backing for the fn_delete_product / fn_get_product_name stubs defined
 * in tests/bootstrap.php (the availability gate deletes a linked hotel's
 * CS-Cart product before its row; DeduplicateCommand deletes dup products).
 *
 * Defaults: deletion succeeds, fn_get_product_name reports the product as
 * gone. Tests needing other behaviour set the closures and MUST reset() in
 * setUp — function stubs are process-global.
 */
final class ProductFnStub
{
    /** @var (callable(int): bool)|null null => delete succeeds */
    public static $delete = null;

    /** @var (callable(int): (string|false))|null null => product absent ('') */
    public static $getName = null;

    /** @var list<int> every product id fn_delete_product was asked to delete */
    public static array $deleted = [];

    public static function reset(): void
    {
        self::$delete = null;
        self::$getName = null;
        self::$deleted = [];
    }
}
