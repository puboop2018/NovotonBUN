<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\SearchMetrics;

#[CoversClass(SearchMetrics::class)]
class SearchMetricsTest extends TestCase
{
    public function testBuildPayloadPrefixesMessageAndStampsMetric(): void
    {
        $payload = SearchMetrics::buildPayload(SearchMetrics::EVENT_FIRST_OFFER, [
            'search_id' => 'abc',
            'hotel_id' => '3371',
            'elapsed_ms' => 3669,
            'poll' => 1,
            'offers' => 3,
        ]);

        $this->assertSame('sphinx.metric first_offer', $payload['message']);
        $this->assertSame('first_offer', $payload['metric']);
        $this->assertSame('3371', $payload['hotel_id']);
        $this->assertSame(3669, $payload['elapsed_ms']);
        $this->assertSame(3, $payload['offers']);
    }

    public function testBuildPayloadWorksWithNoExtraData(): void
    {
        $payload = SearchMetrics::buildPayload(SearchMetrics::EVENT_CACHE_HIT);

        $this->assertSame('sphinx.metric cache_hit', $payload['message']);
        $this->assertSame('cache_hit', $payload['metric']);
        $this->assertCount(2, $payload);
    }

    public function testReservedKeysAreNotOverriddenByData(): void
    {
        // A stray 'message'/'metric' in the data must not shadow the identifiers.
        $payload = SearchMetrics::buildPayload(SearchMetrics::EVENT_COMPLETE, [
            'message' => 'spoofed',
            'metric' => 'spoofed',
            'offers' => 7,
        ]);

        $this->assertSame('sphinx.metric complete', $payload['message']);
        $this->assertSame('complete', $payload['metric']);
        $this->assertSame(7, $payload['offers']);
    }
}
