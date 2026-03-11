<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\SearchParameterNormalizer;

// Stub __() translation function used by the normalizer
if (!function_exists('__')) {
    function __(string $key): string
    {
        return $key;
    }
}

/**
 * @covers \Tygh\Addons\NovotonHolidays\Services\SearchParameterNormalizer
 */
class SearchParameterNormalizerTest extends TestCase
{
    private SearchParameterNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SearchParameterNormalizer();
    }

    public function testBasicNormalization(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'nights' => '7',
            'adults' => '2',
        ]);

        $this->assertSame('2026-06-01', $result['check_in']);
        $this->assertSame('2026-06-08', $result['check_out']);
        $this->assertSame(7, $result['nights']);
        $this->assertSame(2, $result['adults']);
        $this->assertSame(1, $result['num_rooms']);
        $this->assertSame(0, $result['children_count']);
    }

    public function testDefaultNightsIs7(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
        ]);

        $this->assertSame(7, $result['nights']);
        $this->assertSame('2026-06-08', $result['check_out']);
    }

    public function testDefaultAdultsIs2(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
        ]);

        $this->assertSame(2, $result['adults']);
    }

    public function testNightsFromCheckOutDate(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'check_out' => '2026-06-04',
        ]);

        $this->assertSame(3, $result['nights']);
        $this->assertSame('2026-06-04', $result['check_out']);
    }

    public function testRoomsDataFromJson(): void
    {
        $roomsJson = json_encode([
            ['adults' => 2, 'children' => 1, 'childrenAges' => [5]],
            ['adults' => 1, 'children' => 0, 'childrenAges' => []],
        ]);

        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'nights' => '3',
            'rooms_data' => $roomsJson,
        ]);

        $this->assertSame(2, $result['num_rooms']);
        $this->assertSame(3, $result['adults']); // 2 + 1
        $this->assertSame(1, $result['children_count']);
        $this->assertSame([5], $result['children']);
    }

    public function testChildrenAgesFromCommaString(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'nights' => '3',
            'children' => '2',
            'children_ages' => '3,7',
        ]);

        $this->assertSame([3, 7], $result['children']);
        $this->assertSame('3,7', $result['children_ages_str']);
    }

    public function testLegacyChildAgeParams(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'nights' => '3',
            'children' => '2',
            'child_age_1' => '4',
            'child_age_2' => '8',
        ]);

        $this->assertSame([4, 8], $result['children']);
    }

    public function testFlexDays(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'flex_days' => '3',
        ]);

        $this->assertSame(3, $result['flex_days']);
    }

    public function testMealPlan(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'meal_plan' => 'AI',
        ]);

        $this->assertSame('AI', $result['meal_plan']);
    }

    public function testHotelIdPassthrough(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'hotel_id' => 'H12345',
        ]);

        $this->assertSame('H12345', $result['hotel_id']);
    }

    public function testEmptyCheckInReturnsEmptyCheckOut(): void
    {
        $result = $this->normalizer->normalize([]);

        $this->assertSame('', $result['check_in']);
        $this->assertSame('', $result['check_out']);
    }

    public function testNovotonParamsSubarray(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'nights' => '5',
        ]);

        $this->assertArrayHasKey('novoton_params', $result);
        $np = $result['novoton_params'];
        $this->assertSame('2026-06-01', $np['check_in']);
        $this->assertSame(5, $np['nights']);
        $this->assertArrayHasKey('rooms_data_json', $np);
    }

    public function testRoomsDataAsArray(): void
    {
        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'rooms_data' => [
                ['adults' => 3, 'children' => 0, 'childrenAges' => []],
            ],
        ]);

        $this->assertSame(3, $result['adults']);
        $this->assertSame(1, $result['num_rooms']);
    }

    public function testInvalidChildrenAgesFiltered(): void
    {
        $roomsJson = json_encode([
            ['adults' => 2, 'children' => 3, 'childrenAges' => [5, 'null', null, '', 8]],
        ]);

        $result = $this->normalizer->normalize([
            'check_in' => '2026-06-01',
            'rooms_data' => $roomsJson,
        ]);

        // Only numeric ages should survive: 5, 8
        $this->assertSame([5, 8], $result['children']);
    }
}
