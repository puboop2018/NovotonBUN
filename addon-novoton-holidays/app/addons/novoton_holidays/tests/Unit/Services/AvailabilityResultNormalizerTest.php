<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\AvailabilityResultNormalizer;

/**
 * Characterization coverage for AvailabilityResultNormalizer — the pure result
 * shaping extracted from HotelAvailabilitySearcher. Pins the max-capacity
 * derivation from the `N+M` room-id pattern (incl. the 2/2 fallback), the
 * children-ages cleanup (drop empty/placeholder, coerce to int), and the empty
 * result envelope.
 */
#[CoversClass(AvailabilityResultNormalizer::class)]
class AvailabilityResultNormalizerTest extends TestCase
{
    private AvailabilityResultNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AvailabilityResultNormalizer();
    }

    public function testCalculateMaxCapacityTakesTheMaxAcrossRoomIds(): void
    {
        $capacity = $this->normalizer->calculateMaxCapacity([
            ['room_id' => 'DBL 2+1'],
            ['room_id' => 'SUITE 4+2'],
            ['room_id' => 'SGL 1+0'],
        ]);

        $this->assertSame(['adults' => 4, 'children' => 2, 'total' => 6], $capacity);
    }

    public function testCalculateMaxCapacityFallsBackWhenNoRoomIdParses(): void
    {
        $capacity = $this->normalizer->calculateMaxCapacity([
            ['room_id' => 'Double Room'],
            ['room_id' => 'no-numbers-here'],
        ]);

        $this->assertSame(['adults' => 2, 'children' => 2, 'total' => 4], $capacity);
    }

    public function testCalculateMaxCapacityFallsBackOnEmptyResults(): void
    {
        $this->assertSame(
            ['adults' => 2, 'children' => 2, 'total' => 4],
            $this->normalizer->calculateMaxCapacity([]),
        );
    }

    public function testCleanChildrenAgesDropsEmptyAndPlaceholderAndCoercesToInt(): void
    {
        $clean = $this->normalizer->cleanChildrenAges([5, '', null, 'age_needed', '7', 'age_needed', 10]);

        $this->assertSame([5, 7, 10], $clean);
    }

    public function testCleanChildrenAgesKeepsZero(): void
    {
        // 0 is a valid infant age — only null/''/'age_needed' are dropped.
        $this->assertSame([0, 3], $this->normalizer->cleanChildrenAges(['0', 3]));
    }

    public function testEmptyResultEnvelope(): void
    {
        $empty = $this->normalizer->emptyResult();

        $this->assertTrue($empty['no_availability']);
        $this->assertFalse($empty['is_multi_room']);
        $this->assertSame(0, $empty['multi_room_total_options']);
        $this->assertSame([], $empty['results']);
        $this->assertSame(['adults' => 2, 'children' => 2, 'total' => 4], $empty['max_room_capacity']);
    }
}
