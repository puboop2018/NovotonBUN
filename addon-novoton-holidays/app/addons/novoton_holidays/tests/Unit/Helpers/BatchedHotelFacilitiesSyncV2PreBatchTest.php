<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelFacilitiesSyncV2;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Regression: preBatch() builds the hotel_id → hotel_name cache the cron uses
 * to print "[id] name ...". Novoton hotel IDs are numeric, so
 * db_get_hash_single_array returns them as INTEGER array keys. The cache was
 * built with toStringMap(), which drops non-string keys — so it came back
 * empty and every hotel printed "?". This test feeds an int-keyed map (the
 * production shape) and asserts the name survives.
 *
 * The fixtures deliberately use NUMERIC ids — the exact case the alphanumeric
 * fixtures elsewhere ('NVT12345', 'H_A') never exercised.
 */
final class BatchedHotelFacilitiesSyncV2PreBatchTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testPreBatchResolvesNamesForNumericHotelIds(): void
    {
        // Production shape: numeric hotel_id → integer array key.
        DbStub::$getHashSingleArray = static fn (): array => [
            4684 => 'EDART',
            4504 => 'ALBA',
        ];

        $sync = (new \ReflectionClass(BatchedHotelFacilitiesSyncV2::class))->newInstanceWithoutConstructor();

        $preBatch = new \ReflectionMethod($sync, 'preBatch');
        $preBatch->invoke($sync, ['4684', '4504']);

        $cacheProp = new \ReflectionProperty($sync, 'hotelNameCache');
        /** @var array<string, string> $cache */
        $cache = $cacheProp->getValue($sync);

        self::assertNotSame([], $cache, 'name cache must not be empty for numeric hotel IDs');
        self::assertSame('EDART', $cache['4684'] ?? null);
        self::assertSame('ALBA', $cache['4504'] ?? null);
    }

    public function testPreBatchWithEmptyBatchClearsCache(): void
    {
        $sync = (new \ReflectionClass(BatchedHotelFacilitiesSyncV2::class))->newInstanceWithoutConstructor();

        $preBatch = new \ReflectionMethod($sync, 'preBatch');
        $preBatch->invoke($sync, []);

        $cacheProp = new \ReflectionProperty($sync, 'hotelNameCache');
        self::assertSame([], $cacheProp->getValue($sync));
    }
}
