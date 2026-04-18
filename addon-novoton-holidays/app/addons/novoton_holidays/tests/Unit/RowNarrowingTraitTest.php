<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Test harness exposing the protected trait methods as public static.
 */
class RowNarrowingHarness
{
    use RowNarrowingTrait {
        asRowList as public callAsRowList;
        asRow as public callAsRow;
        asRowMap as public callAsRowMap;
        asScalarMap as public callAsScalarMap;
    }
}

#[CoversClass(RowNarrowingTrait::class)]
class RowNarrowingTraitTest extends TestCase
{
    public function testAsRowListWithValidRows(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];
        $this->assertSame($rows, RowNarrowingHarness::callAsRowList($rows));
    }

    public function testAsRowListReindexesAndDropsNonArrayRows(): void
    {
        $input = [
            'a' => ['id' => 1],
            'b' => 'skip',
            'c' => ['id' => 2],
        ];
        $this->assertSame([['id' => 1], ['id' => 2]], RowNarrowingHarness::callAsRowList($input));
    }

    public function testAsRowListFromNonArray(): void
    {
        $this->assertSame([], RowNarrowingHarness::callAsRowList(null));
        $this->assertSame([], RowNarrowingHarness::callAsRowList(false));
        $this->assertSame([], RowNarrowingHarness::callAsRowList('x'));
    }

    public function testAsRowListDropsIntKeysOnInnerRows(): void
    {
        $input = [
            [0 => 'keep-as-list', 'name' => 'Alice'],
        ];
        $this->assertSame(
            [['name' => 'Alice']],
            RowNarrowingHarness::callAsRowList($input),
        );
    }

    public function testAsRowFromValidArray(): void
    {
        $row = ['id' => 1, 'name' => 'a'];
        $this->assertSame($row, RowNarrowingHarness::callAsRow($row));
    }

    public function testAsRowFromNonArrayReturnsEmpty(): void
    {
        $this->assertSame([], RowNarrowingHarness::callAsRow(null));
        $this->assertSame([], RowNarrowingHarness::callAsRow(false));
    }

    public function testAsRowMapWithValidInput(): void
    {
        $input = [
            'r1' => ['id' => 1],
            'r2' => ['id' => 2],
        ];
        $this->assertSame($input, RowNarrowingHarness::callAsRowMap($input));
    }

    public function testAsRowMapDropsNonStringKeysAndNonArrayValues(): void
    {
        $input = [
            0      => ['bad-int-key'],
            'good' => ['id' => 1],
            'bad2' => 'scalar',
        ];
        $this->assertSame(['good' => ['id' => 1]], RowNarrowingHarness::callAsRowMap($input));
    }

    public function testAsRowMapFromNonArray(): void
    {
        $this->assertSame([], RowNarrowingHarness::callAsRowMap(null));
        $this->assertSame([], RowNarrowingHarness::callAsRowMap('x'));
    }

    public function testAsScalarMap(): void
    {
        $input = ['hotel1' => 'Alice', 'hotel2' => 'Bob'];
        $this->assertSame($input, RowNarrowingHarness::callAsScalarMap($input));
    }

    public function testAsScalarMapFromNonArray(): void
    {
        $this->assertSame([], RowNarrowingHarness::callAsScalarMap(null));
    }
}
