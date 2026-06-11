<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;

#[CoversClass(OfferAvailability::class)]
class OfferAvailabilityTest extends TestCase
{
    public function testIsImmediateTrueForImmediateConfirmation(): void
    {
        $this->assertTrue(OfferAvailability::isImmediate(['confirmation' => 'immediate']));
    }

    public function testIsImmediateIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertTrue(OfferAvailability::isImmediate(['confirmation' => '  IMMEDIATE ']));
        $this->assertTrue(OfferAvailability::isImmediate(['confirmation' => 'Immediate']));
    }

    public function testIsImmediateFalseForOnRequestOrMissing(): void
    {
        $this->assertFalse(OfferAvailability::isImmediate(['confirmation' => 'on_request']));
        $this->assertFalse(OfferAvailability::isImmediate(['confirmation' => '']));
        $this->assertFalse(OfferAvailability::isImmediate([]));
    }

    public function testFilterImmediateKeepsOnlyImmediateAndReindexes(): void
    {
        $offers = [
            ['offer_id' => 'a', 'confirmation' => 'immediate'],
            ['offer_id' => 'b', 'confirmation' => 'on_request'],
            ['offer_id' => 'c'], // missing field → dropped
            ['offer_id' => 'd', 'confirmation' => 'immediate'],
        ];

        $filtered = OfferAvailability::filterImmediate($offers);

        $this->assertCount(2, $filtered);
        $this->assertSame('a', $filtered[0]['offer_id']);
        $this->assertSame('d', $filtered[1]['offer_id']);
        // Preserves all original keys of the kept offers.
        $this->assertSame('immediate', $filtered[0]['confirmation']);
    }

    public function testCollectImmediateHotelIdsDedupesAndUsesIdFallback(): void
    {
        $offers = [
            ['hotel_id' => '100', 'confirmation' => 'immediate'],
            ['hotel_id' => '100', 'confirmation' => 'immediate'], // duplicate hotel
            ['hotel_id' => '200', 'confirmation' => 'on_request'], // not immediate → excluded
            ['id' => 300, 'confirmation' => 'immediate'], // id fallback, numeric
        ];

        $ids = OfferAvailability::collectImmediateHotelIds($offers);

        $this->assertSame(['100' => true, '300' => true], $ids);
        $this->assertArrayNotHasKey('200', $ids);
    }

    public function testCollectImmediateHotelIdsSkipsNonArrayAndEmptyIds(): void
    {
        $offers = [
            'not-an-array',
            ['confirmation' => 'immediate'], // no hotel id → skipped
            ['hotel_id' => '', 'confirmation' => 'immediate'], // empty id → skipped
            ['hotel_id' => '500', 'confirmation' => 'immediate'],
        ];

        $this->assertSame(['500' => true], OfferAvailability::collectImmediateHotelIds($offers));
    }
}
