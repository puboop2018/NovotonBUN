<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Dto\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\SearchParameterNormalizer;
use Tygh\Addons\TravelCore\Dto\Search\Destination;
use Tygh\Addons\TravelCore\Dto\Search\RoomSpec;
use Tygh\Addons\TravelCore\Dto\Search\SearchQuery;

#[CoversClass(SearchQuery::class)]
#[CoversClass(RoomSpec::class)]
#[CoversClass(Destination::class)]
#[CoversClass(SearchParameterNormalizer::class)]
final class SearchQueryTest extends TestCase
{
    private SearchParameterNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SearchParameterNormalizer();
    }

    public function testSingleRoomTwoAdults(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-07-01',
            'nights' => '7',
            'adults' => '2',
            'children' => '0',
            'hotel_id' => 'NVT1',
        ]);

        $this->assertSame('2026-07-01', $q->checkIn);
        $this->assertSame('2026-07-08', $q->checkOut);
        $this->assertSame(7, $q->nights);
        $this->assertSame(2, $q->totalAdults);
        $this->assertSame(0, $q->totalChildren);
        $this->assertSame([], $q->childrenAges);
        $this->assertSame(1, $q->numRooms());
        $this->assertTrue($q->isHotelScoped());
        $this->assertSame('NVT1', $q->hotelId);
    }

    public function testSingleRoomWithChildrenAges(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-08-15',
            'nights' => '10',
            'adults' => '2',
            'children' => '2',
            'children_ages' => '6,9',
            'hotel_id' => 'NVT2',
        ]);

        $this->assertSame(2, $q->totalAdults);
        $this->assertSame(2, $q->totalChildren);
        $this->assertSame([6, 9], $q->childrenAges);
        $this->assertCount(1, $q->roomsData);
        $this->assertSame([6, 9], $q->roomsData[0]->childrenAges);
    }

    public function testMultiRoomViaJsonRoomsData(): void
    {
        $roomsData = [
            ['adults' => 2, 'children' => 1, 'childrenAges' => [5]],
            ['adults' => 2, 'children' => 0, 'childrenAges' => []],
            ['adults' => 1, 'children' => 2, 'childrenAges' => [8, 11]],
        ];

        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-09-01',
            'nights' => '14',
            'rooms_data' => json_encode($roomsData),
            'hotel_id' => 'NVT3',
        ]);

        $this->assertSame(3, $q->numRooms());
        $this->assertSame(5, $q->totalAdults);
        $this->assertSame(3, $q->totalChildren);
        $this->assertSame([5, 8, 11], $q->childrenAges);
    }

    public function testFlexDatesSearch(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-10-01',
            'nights' => '7',
            'adults' => '2',
            'flex_days' => '3',
            'hotel_id' => 'NVT4',
        ]);

        $this->assertSame(3, $q->flexDays);
    }

    public function testHomepageSearchWithoutHotelScope(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-11-01',
            'nights' => '5',
            'adults' => '2',
            'destination' => 'Mallorca',
            'country' => 'Spain',
        ]);

        $this->assertFalse($q->isHotelScoped());
        $this->assertSame('', $q->hotelId);
        $this->assertSame('Mallorca', $q->destination->freeText);
        $this->assertSame('Spain', $q->destination->country);
    }

    public function testMealPlanSelection(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-07-01',
            'nights' => '7',
            'adults' => '2',
            'hotel_id' => 'NVT5',
            'meal_plan' => 'AI',
        ]);

        $this->assertSame('AI', $q->mealPlan);
    }

    public function testDateRangeViaCheckOut(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-12-20',
            'check_out' => '2026-12-27',
            'adults' => '2',
            'hotel_id' => 'NVT6',
        ]);

        $this->assertSame(7, $q->nights);
        $this->assertSame('2026-12-27', $q->checkOut);
    }

    public function testIndividualChildAgeParams(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-06-01',
            'nights' => '7',
            'adults' => '2',
            'children' => '3',
            'child_age_1' => '5',
            'child_age_2' => '8',
            'child_age_3' => '12',
            'hotel_id' => 'NVT7',
        ]);

        $this->assertSame(3, $q->totalChildren);
        $this->assertSame([5, 8, 12], $q->childrenAges);
    }

    public function testEmptyDestinationIsIdentifiable(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-07-01',
            'nights' => '7',
            'adults' => '2',
            'hotel_id' => 'NVT8',
        ]);

        $this->assertTrue($q->destination->isEmpty());
    }

    public function testRoomSpecFromArrayTrimsInvalidAges(): void
    {
        $r = RoomSpec::fromArray([
            'adults' => 2,
            'children' => 3,
            'childrenAges' => [5, 'null', '', 'age_needed', 8, null],
        ]);

        // 'null', '', 'age_needed', null all dropped → 2 valid ages.
        // Children count re-derived from ages (2) since ages are present.
        $this->assertSame([5, 8], $r->childrenAges);
        $this->assertSame(2, $r->children);
    }

    public function testRoomSpecDefaultsToTwoAdults(): void
    {
        $r = RoomSpec::fromArray([]);
        $this->assertSame(2, $r->adults);
        $this->assertSame(0, $r->children);
        $this->assertSame([], $r->childrenAges);
    }

    public function testToArrayRoundTripViaNormalizer(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-07-01',
            'nights' => '7',
            'adults' => '2',
            'children' => '1',
            'child_age_1' => '7',
            'hotel_id' => 'NVT9',
            'meal_plan' => 'HB',
        ]);

        $arr = $q->toArray();

        $this->assertSame('2026-07-01', $arr['check_in']);
        $this->assertSame('2026-07-08', $arr['check_out']);
        $this->assertSame(2, $arr['adults']);
        $this->assertSame('7', $arr['children_ages_str']);
        $this->assertSame('NVT9', $arr['hotel_id']);
        $this->assertSame('HB', $arr['meal_plan']);
        $this->assertIsArray($arr['rooms_data']);
    }

    public function testToNovotonParamsArrayMatchesLegacyShape(): void
    {
        $q = $this->normalizer->normalizeAsDto([
            'check_in' => '2026-07-01',
            'nights' => '7',
            'adults' => '2',
            'hotel_id' => 'NVT10',
        ]);

        $np = $q->toNovotonParamsArray();

        // Keys historically expected by SearchResultFormatter / React mount
        $this->assertArrayHasKey('check_in', $np);
        $this->assertArrayHasKey('check_out', $np);
        $this->assertArrayHasKey('rooms_data_json', $np);
        $this->assertArrayHasKey('children_ages_array', $np);
        $this->assertIsString($np['rooms_data_json']);
    }
}
