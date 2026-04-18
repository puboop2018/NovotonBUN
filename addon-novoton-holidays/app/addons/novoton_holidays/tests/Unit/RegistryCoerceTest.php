<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;
use Tygh\Registry;

#[CoversClass(RegistryCoerce::class)]
class RegistryCoerceTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the shared registry stub state between tests.
        // The bootstrap stub exposes a static array; clear every key we might use.
        foreach (['test.str', 'test.int', 'test.float', 'test.bool', 'test.map', 'test.list'] as $k) {
            Registry::set($k, null);
        }
    }

    public function testStringReadsPresentValue(): void
    {
        Registry::set('test.str', 'hello');
        $this->assertSame('hello', RegistryCoerce::string('test.str'));
    }

    public function testStringReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('', RegistryCoerce::string('not.set'));
        $this->assertSame('fallback', RegistryCoerce::string('not.set', 'fallback'));
    }

    public function testStringReturnsDefaultForNullValue(): void
    {
        Registry::set('test.str', null);
        $this->assertSame('fallback', RegistryCoerce::string('test.str', 'fallback'));
    }

    public function testIntCoercesNumericString(): void
    {
        Registry::set('test.int', '42');
        $this->assertSame(42, RegistryCoerce::int('test.int'));
    }

    public function testIntDefaultOnMissing(): void
    {
        $this->assertSame(100, RegistryCoerce::int('not.set', 100));
    }

    public function testFloat(): void
    {
        Registry::set('test.float', 3.14);
        $this->assertSame(3.14, RegistryCoerce::float('test.float'));
        $this->assertSame(1.0, RegistryCoerce::float('not.set', 1.0));
    }

    public function testBool(): void
    {
        Registry::set('test.bool', 'Y');
        $this->assertTrue(RegistryCoerce::bool('test.bool'));
        Registry::set('test.bool', 'N');
        $this->assertFalse(RegistryCoerce::bool('test.bool'));
        $this->assertFalse(RegistryCoerce::bool('not.set'));
        $this->assertTrue(RegistryCoerce::bool('not.set', true));
    }

    public function testStringMap(): void
    {
        Registry::set('test.map', ['name' => 'Alice', 'age' => 30]);
        $this->assertSame(['name' => 'Alice', 'age' => 30], RegistryCoerce::stringMap('test.map'));
    }

    public function testStringMapReturnsEmptyForMissingOrScalar(): void
    {
        $this->assertSame([], RegistryCoerce::stringMap('not.set'));
        Registry::set('test.map', 'scalar');
        $this->assertSame([], RegistryCoerce::stringMap('test.map'));
    }

    public function testList(): void
    {
        Registry::set('test.list', ['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], RegistryCoerce::list('test.list'));
        $this->assertSame([], RegistryCoerce::list('not.set'));
    }
}
