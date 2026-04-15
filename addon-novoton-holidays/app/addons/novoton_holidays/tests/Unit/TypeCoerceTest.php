<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * @covers \Tygh\Addons\TravelCore\Helpers\TypeCoerce
 */
class TypeCoerceTest extends TestCase
{
    // ── toString ────────────────────────────────────────────────────────────

    public function testToStringFromString(): void
    {
        $this->assertSame('hello', TypeCoerce::toString('hello'));
        $this->assertSame('hello', TypeCoerce::toString('  hello  '));
        $this->assertSame('', TypeCoerce::toString(''));
    }

    public function testToStringFromInt(): void
    {
        $this->assertSame('42', TypeCoerce::toString(42));
        $this->assertSame('0', TypeCoerce::toString(0));
        $this->assertSame('-5', TypeCoerce::toString(-5));
    }

    public function testToStringFromFloat(): void
    {
        $this->assertSame('3.14', TypeCoerce::toString(3.14));
    }

    public function testToStringFromBool(): void
    {
        $this->assertSame('1', TypeCoerce::toString(true));
        $this->assertSame('', TypeCoerce::toString(false));
    }

    public function testToStringFromNull(): void
    {
        $this->assertSame('', TypeCoerce::toString(null));
    }

    public function testToStringFromArrayReturnsEmpty(): void
    {
        $this->assertSame('', TypeCoerce::toString([]));
        $this->assertSame('', TypeCoerce::toString(['a', 'b']));
        $this->assertSame('', TypeCoerce::toString(['key' => 'val']));
    }

    public function testToStringFromObjectReturnsEmpty(): void
    {
        $this->assertSame('', TypeCoerce::toString(new \stdClass()));
    }

    // ── toInt ────────────────────────────────────────────────────────────────

    public function testToIntFromInt(): void
    {
        $this->assertSame(42, TypeCoerce::toInt(42));
        $this->assertSame(0, TypeCoerce::toInt(0));
        $this->assertSame(-5, TypeCoerce::toInt(-5));
    }

    public function testToIntFromFloatTruncates(): void
    {
        $this->assertSame(3, TypeCoerce::toInt(3.14));
        $this->assertSame(3, TypeCoerce::toInt(3.99));
        $this->assertSame(-3, TypeCoerce::toInt(-3.99));
    }

    public function testToIntFromNumericString(): void
    {
        $this->assertSame(42, TypeCoerce::toInt('42'));
        $this->assertSame(42, TypeCoerce::toInt('42.9'));
        $this->assertSame(-5, TypeCoerce::toInt('-5'));
    }

    public function testToIntFromNonNumericReturnsZero(): void
    {
        $this->assertSame(0, TypeCoerce::toInt('hello'));
        $this->assertSame(0, TypeCoerce::toInt(''));
        $this->assertSame(0, TypeCoerce::toInt(null));
        $this->assertSame(0, TypeCoerce::toInt(true));
        $this->assertSame(0, TypeCoerce::toInt(false));
        $this->assertSame(0, TypeCoerce::toInt([]));
        $this->assertSame(0, TypeCoerce::toInt(new \stdClass()));
    }

    // ── toFloat ──────────────────────────────────────────────────────────────

    public function testToFloatFromInt(): void
    {
        $this->assertSame(42.0, TypeCoerce::toFloat(42));
    }

    public function testToFloatFromFloat(): void
    {
        $this->assertSame(3.14, TypeCoerce::toFloat(3.14));
    }

    public function testToFloatFromNumericString(): void
    {
        $this->assertSame(3.14, TypeCoerce::toFloat('3.14'));
        $this->assertSame(42.0, TypeCoerce::toFloat('42'));
    }

    public function testToFloatFromNonNumericReturnsZero(): void
    {
        $this->assertSame(0.0, TypeCoerce::toFloat('hello'));
        $this->assertSame(0.0, TypeCoerce::toFloat(''));
        $this->assertSame(0.0, TypeCoerce::toFloat(null));
        $this->assertSame(0.0, TypeCoerce::toFloat(true));
        $this->assertSame(0.0, TypeCoerce::toFloat([]));
    }

    // ── toBool ───────────────────────────────────────────────────────────────

    public function testToBoolFromBool(): void
    {
        $this->assertTrue(TypeCoerce::toBool(true));
        $this->assertFalse(TypeCoerce::toBool(false));
    }

    public function testToBoolFromCsCartYesNo(): void
    {
        $this->assertTrue(TypeCoerce::toBool('Y'));
        $this->assertTrue(TypeCoerce::toBool('y'));
        $this->assertFalse(TypeCoerce::toBool('N'));
        $this->assertFalse(TypeCoerce::toBool('n'));
    }

    public function testToBoolFromNumericString(): void
    {
        $this->assertTrue(TypeCoerce::toBool('1'));
        $this->assertFalse(TypeCoerce::toBool('0'));
    }

    public function testToBoolFromInt(): void
    {
        $this->assertTrue(TypeCoerce::toBool(1));
        $this->assertTrue(TypeCoerce::toBool(42));
        $this->assertFalse(TypeCoerce::toBool(0));
    }

    public function testToBoolFromNullOrMisc(): void
    {
        $this->assertFalse(TypeCoerce::toBool(null));
        $this->assertFalse(TypeCoerce::toBool(''));
        $this->assertFalse(TypeCoerce::toBool('true'));   // strict: only Y/y/1
        $this->assertFalse(TypeCoerce::toBool([]));
        $this->assertFalse(TypeCoerce::toBool(new \stdClass()));
    }

    // ── toList ───────────────────────────────────────────────────────────────

    public function testToListFromList(): void
    {
        $this->assertSame(['a', 'b', 'c'], TypeCoerce::toList(['a', 'b', 'c']));
    }

    public function testToListReindexesAssociative(): void
    {
        $this->assertSame([1, 2, 3], TypeCoerce::toList(['a' => 1, 'b' => 2, 'c' => 3]));
    }

    public function testToListFromNonArrayReturnsEmpty(): void
    {
        $this->assertSame([], TypeCoerce::toList('x'));
        $this->assertSame([], TypeCoerce::toList(42));
        $this->assertSame([], TypeCoerce::toList(null));
    }

    // ── toStringMap ──────────────────────────────────────────────────────────

    public function testToStringMapKeepsStringKeys(): void
    {
        $this->assertSame(
            ['name' => 'Alice', 'age' => 30],
            TypeCoerce::toStringMap(['name' => 'Alice', 'age' => 30]),
        );
    }

    public function testToStringMapDropsIntegerKeys(): void
    {
        $this->assertSame(
            ['name' => 'Alice'],
            TypeCoerce::toStringMap([0 => 'x', 'name' => 'Alice', 1 => 'y']),
        );
    }

    public function testToStringMapFromNonArrayReturnsEmpty(): void
    {
        $this->assertSame([], TypeCoerce::toStringMap('x'));
        $this->assertSame([], TypeCoerce::toStringMap(null));
    }

    // ── toRowList ────────────────────────────────────────────────────────────

    public function testToRowListFromValidRows(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];
        $this->assertSame($rows, TypeCoerce::toRowList($rows));
    }

    public function testToRowListReindexesAndDropsNonArrayRows(): void
    {
        $input = [
            'x' => ['id' => 1],
            'y' => 'skip me',
            'z' => ['id' => 2],
        ];
        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            TypeCoerce::toRowList($input),
        );
    }

    public function testToRowListFromNonArray(): void
    {
        $this->assertSame([], TypeCoerce::toRowList(null));
        $this->assertSame([], TypeCoerce::toRowList('x'));
        $this->assertSame([], TypeCoerce::toRowList(false));
    }
}
