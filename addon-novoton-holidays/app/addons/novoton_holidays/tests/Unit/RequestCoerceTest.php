<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

/**
 * @covers \Tygh\Addons\TravelCore\Helpers\RequestCoerce
 */
class RequestCoerceTest extends TestCase
{
    public function testStringReadsPresentValue(): void
    {
        $this->assertSame('nvt123', RequestCoerce::string(['hotel_id' => 'nvt123'], 'hotel_id'));
    }

    public function testStringReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('', RequestCoerce::string(['other' => 'x'], 'hotel_id'));
        $this->assertSame('fallback', RequestCoerce::string([], 'hotel_id', 'fallback'));
    }

    public function testStringCoercesNonStringValues(): void
    {
        $this->assertSame('42', RequestCoerce::string(['n' => 42], 'n'));
        $this->assertSame('', RequestCoerce::string(['n' => null], 'n'));
        $this->assertSame('', RequestCoerce::string(['n' => []], 'n'));
    }

    public function testIntReadsPresentValue(): void
    {
        $this->assertSame(2, RequestCoerce::int(['adults' => 2], 'adults'));
        $this->assertSame(2, RequestCoerce::int(['adults' => '2'], 'adults'));
    }

    public function testIntReturnsDefaultForMissingKey(): void
    {
        $this->assertSame(2, RequestCoerce::int([], 'adults', 2));
        $this->assertSame(0, RequestCoerce::int([], 'adults'));
    }

    public function testIntCoercesNonNumericToZero(): void
    {
        $this->assertSame(0, RequestCoerce::int(['x' => 'hello'], 'x'));
        $this->assertSame(0, RequestCoerce::int(['x' => null], 'x'));
    }

    public function testFloat(): void
    {
        $this->assertSame(3.14, RequestCoerce::float(['p' => 3.14], 'p'));
        $this->assertSame(3.14, RequestCoerce::float(['p' => '3.14'], 'p'));
        $this->assertSame(1.0, RequestCoerce::float([], 'p', 1.0));
    }

    public function testBool(): void
    {
        $this->assertTrue(RequestCoerce::bool(['debug' => 'Y'], 'debug'));
        $this->assertFalse(RequestCoerce::bool(['debug' => 'N'], 'debug'));
        $this->assertFalse(RequestCoerce::bool([], 'debug'));
        $this->assertTrue(RequestCoerce::bool([], 'debug', true));
    }

    public function testStringMapReturnsNestedArray(): void
    {
        $source = ['guests' => ['name' => 'Alice', 'age' => 30]];
        $this->assertSame(['name' => 'Alice', 'age' => 30], RequestCoerce::stringMap($source, 'guests'));
    }

    public function testStringMapReturnsEmptyForMissingOrScalar(): void
    {
        $this->assertSame([], RequestCoerce::stringMap([], 'guests'));
        $this->assertSame([], RequestCoerce::stringMap(['guests' => 'string-not-array'], 'guests'));
    }

    public function testStringMapDropsIntegerKeys(): void
    {
        $source = ['x' => [0 => 'a', 'name' => 'Alice']];
        $this->assertSame(['name' => 'Alice'], RequestCoerce::stringMap($source, 'x'));
    }

    public function testListReindexes(): void
    {
        $source = ['ids' => ['a' => 1, 'b' => 2]];
        $this->assertSame([1, 2], RequestCoerce::list($source, 'ids'));
    }

    public function testListReturnsEmptyForMissingOrScalar(): void
    {
        $this->assertSame([], RequestCoerce::list([], 'ids'));
        $this->assertSame([], RequestCoerce::list(['ids' => 'not-an-array'], 'ids'));
    }

    public function testStringListCoercesAllEntries(): void
    {
        $source = ['ids' => [1, 'two', 3.0, null]];
        $this->assertSame(['1', 'two', '3', ''], RequestCoerce::stringList($source, 'ids'));
    }

    public function testIntListCoercesAllEntries(): void
    {
        $source = ['ids' => ['1', 2, 'three', '4']];
        $this->assertSame([1, 2, 0, 4], RequestCoerce::intList($source, 'ids'));
    }
}
