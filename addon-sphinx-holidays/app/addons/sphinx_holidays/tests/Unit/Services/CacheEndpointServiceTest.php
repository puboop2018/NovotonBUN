<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tygh\Addons\SphinxHolidays\Services\CacheEndpointService;

/**
 * Characterization coverage for CacheEndpointService::normalizeDeals(), pinned
 * with the boundary-typing paydown that row-listed the raw cache response and
 * coerced the per-deal scalar reads through TypeCoerce. Exercised via reflection
 * with commission = 0 so no CommissionCalculator / ConfigProvider (Registry)
 * path is taken — each item supplies its own currency, keeping the method pure.
 */
#[CoversClass(CacheEndpointService::class)]
final class CacheEndpointServiceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalize(array $items): array
    {
        $service = (new ReflectionClass(CacheEndpointService::class))->newInstanceWithoutConstructor();

        // Initialise the readonly commission to 0 (the $api dependency is unused
        // by normalizeDeals, so it can stay uninitialised).
        (new ReflectionProperty($service, 'commission'))->setValue($service, 0.0);

        $m = new ReflectionMethod($service, 'normalizeDeals');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $result */
        $result = $m->invoke($service, $items);

        return $result;
    }

    public function testCoercesStringScalarsToTypedDealFields(): void
    {
        $deals = $this->normalize([
            [
                'hotel_id' => 'H1',
                'hotel_name' => 'Test Hotel',
                'price' => '199.50',
                'star_rating' => '4',
                'nights' => '7',
                'currency' => 'EUR',
            ],
        ]);

        $this->assertCount(1, $deals);
        $this->assertSame(199.5, $deals[0]['price']);          // commission 0 → price unchanged
        $this->assertSame(199.5, $deals[0]['original_price']);
        $this->assertSame(4, $deals[0]['star_rating']);
        $this->assertSame(7, $deals[0]['nights']);
        $this->assertSame('EUR', $deals[0]['currency']);
        $this->assertSame('H1', $deals[0]['hotel_id']);
    }

    public function testSkipsItemsWithNonPositivePrice(): void
    {
        $deals = $this->normalize([
            ['hotel_id' => 'A', 'price' => '0', 'currency' => 'EUR'],
            ['hotel_id' => 'B', 'price' => '-12.5', 'currency' => 'EUR'],
            ['hotel_id' => 'C', 'price' => '50', 'currency' => 'EUR'],
            ['hotel_id' => 'D', 'currency' => 'EUR'], // no price → 0 → skipped
        ]);

        $this->assertCount(1, $deals);
        $this->assertSame('C', $deals[0]['hotel_id']);
    }

    public function testAppliesAlternateFieldFallbacks(): void
    {
        $deals = $this->normalize([
            [
                'price' => '300',
                'currency' => 'USD',
                'destination_name' => 'Crete',     // no 'destination'
                'stars' => '5',                     // no 'star_rating'
                'hotel_image' => 'img.jpg',         // no 'image'
                'room_type' => 'Suite',             // no 'room_name'
                'board_type' => 'All Inclusive',    // no 'board_name'
            ],
        ]);

        $this->assertSame('Crete', $deals[0]['destination']);
        $this->assertSame(5, $deals[0]['star_rating']);
        $this->assertSame('img.jpg', $deals[0]['image']);
        $this->assertSame('Suite', $deals[0]['room_name']);
        $this->assertSame('All Inclusive', $deals[0]['board_name']);
    }

    public function testEmptyInputYieldsEmptyList(): void
    {
        $this->assertSame([], $this->normalize([]));
    }
}
