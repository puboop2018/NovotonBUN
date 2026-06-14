<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\SearchParamsNormalizer;

/**
 * Characterization coverage for SearchParamsNormalizer — the request → search
 * params transformation extracted from SearchService. Pins the scalar coercion,
 * the single-room synthesis with indexed child_age_N fields (skipping blanks and
 * "age_needed"), the multi-room room_data JSON parse, and the occupancy totals.
 */
#[CoversClass(SearchParamsNormalizer::class)]
class SearchParamsNormalizerTest extends TestCase
{
    private SearchParamsNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SearchParamsNormalizer();
    }

    public function testCoercesScalarParams(): void
    {
        $params = $this->normalizer->parseSearchParams([
            'check_in' => '2026-07-01',
            'nights' => '10',
            'adults' => '3',
            'children' => '1',
            'hotel_id' => 'H1',
            'product_id' => '42',
        ]);

        $this->assertSame('2026-07-01', $params['check_in']);
        $this->assertSame(10, $params['nights']);
        $this->assertSame(3, $params['adults']);
        $this->assertSame(1, $params['children']);
        $this->assertSame('H1', $params['hotel_id']);
        $this->assertSame(42, $params['product_id']);
    }

    public function testSynthesizesSingleRoomWithChildAges(): void
    {
        $params = $this->normalizer->parseSearchParams([
            'adults' => '2',
            'children' => '2',
            'child_age_1' => '5',
            'child_age_2' => '8',
        ]);

        $this->assertCount(1, $params['rooms_data']);
        $this->assertSame(2, $params['rooms_data'][0]['adults']);
        $this->assertSame([5, 8], $params['rooms_data'][0]['childrenAges']);
        $this->assertSame(2, $params['total_adults']);
        $this->assertSame(2, $params['total_children']);
        $this->assertSame([5, 8], $params['children_ages']);
    }

    public function testChildAgeFieldsSkipBlankAndPlaceholder(): void
    {
        $params = $this->normalizer->parseSearchParams([
            'children' => '3',
            'child_age_1' => '5',
            'child_age_2' => '',           // skipped
            'child_age_3' => 'age_needed', // skipped
        ]);

        $this->assertSame([5], $params['rooms_data'][0]['childrenAges']);
    }

    public function testParsesMultiRoomData(): void
    {
        $params = $this->normalizer->parseSearchParams([
            'room_data' => json_encode([
                ['adults' => 2, 'children' => 1, 'childrenAges' => [5]],
                ['adults' => 3, 'children' => 0, 'childrenAges' => []],
            ]),
        ]);

        $this->assertCount(2, $params['rooms_data']);
        $this->assertSame(2, $params['num_rooms']);
        $this->assertSame(5, $params['total_adults']);
        $this->assertSame(1, $params['total_children']);
        $this->assertSame([5], $params['children_ages']);
    }

    public function testCalculateRoomTotalsFiltersPlaceholderAges(): void
    {
        $totals = $this->normalizer->calculateRoomTotals([
            ['adults' => 2, 'children' => 1, 'childrenAges' => [5]],
            ['adults' => 3, 'children' => 2, 'childrenAges' => [8, 'age_needed', 10]],
        ]);

        $this->assertSame(5, $totals['adults']);
        $this->assertSame(3, $totals['children']);
        $this->assertSame([5, 8, 10], $totals['ages']);
    }
}
