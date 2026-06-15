<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DestinationSyncCommand;

/**
 * Characterization coverage for the pure transformation surface of
 * DestinationSyncCommand, pinned alongside the boundary-typing paydown that
 * routed the raw-API `mixed` reads through ValidationHelpers/TypeCoerce.
 *
 * normalizeDestination/extractItems/hasMorePages touch no Container services,
 * so they are exercised directly via reflection (the command is instantiated
 * without its constructor) — no API or DB seam required.
 */
#[CoversClass(DestinationSyncCommand::class)]
final class DestinationSyncCommandTest extends TestCase
{
    private DestinationSyncCommand $command;

    protected function setUp(): void
    {
        $this->command = (new ReflectionClass(DestinationSyncCommand::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalize(array $raw): ?array
    {
        $m = new ReflectionMethod($this->command, 'normalizeDestination');
        $m->setAccessible(true);
        /** @var array<string, mixed>|null $result */
        $result = $m->invoke($this->command, $raw);

        return $result;
    }

    public function testNormalizeCoercesStringScalarsToTypedColumns(): void
    {
        // API delivers everything as strings; the column shape must be typed.
        $out = $this->normalize([
            'id' => '42',
            'name' => '  Crete  ',
            'type' => 'region',
            'parent_id' => '10',
            'country_code' => 'GR',
            'geoname_id' => '123',
            'latitude' => '35.2',
            'longitude' => '24.8',
            'hotel_count' => '7',
        ]);

        $this->assertSame([
            'destination_id' => 42,
            'name' => 'Crete',
            'type' => 'region',
            'parent_id' => 10,
            'country_code' => 'GR',
            'geoname_id' => 123,
            'latitude' => 35.2,
            'longitude' => 24.8,
            'hotel_count' => 7,
        ], $out);
    }

    public function testNormalizeAppliesDefaultsForMissingOptionalFields(): void
    {
        $out = $this->normalize(['id' => 5, 'name' => 'Minimal']);

        $this->assertNotNull($out);
        $this->assertSame(0, $out['parent_id']);
        $this->assertSame('', $out['country_code']);
        $this->assertSame(0, $out['geoname_id']);
        $this->assertSame(0.0, $out['latitude']);
        $this->assertSame(0.0, $out['longitude']);
        $this->assertSame(0, $out['hotel_count']);
        $this->assertSame('destination', $out['type']); // default type
    }

    public function testNormalizeUsesDestinationIdFallbackAndRejectsNonPositiveId(): void
    {
        $this->assertSame(7, $this->normalize(['destination_id' => '7', 'name' => 'X'])['destination_id'] ?? null);
        $this->assertNull($this->normalize(['destination_id' => 0, 'name' => 'X'])); // id <= 0
        $this->assertNull($this->normalize(['name' => 'X']));                        // no id at all
    }

    public function testNormalizeNameFallbackChain(): void
    {
        $this->assertSame('Title', $this->normalize(['id' => 1, 'title' => 'Title'])['name']);
        $this->assertSame('Label', $this->normalize(['id' => 1, 'label' => 'Label'])['name']);
        $this->assertSame('EN', $this->normalize(['id' => 1, 'translations' => ['en' => 'EN']])['name']);
        $this->assertSame('EnUs', $this->normalize(['id' => 1, 'names' => ['en_US' => 'EnUs']])['name']);
        // No en/en_US key → falls through to reset() (first translation).
        $this->assertSame('First', $this->normalize(['id' => 1, 'translations' => ['fr' => 'First']])['name']);
        $this->assertSame('NameEn', $this->normalize(['id' => 1, 'name_en' => 'NameEn'])['name']);
    }

    public function testNormalizeRejectsBlankName(): void
    {
        $this->assertNull($this->normalize(['id' => 1, 'name' => '   ']));
        $this->assertNull($this->normalize(['id' => 1])); // no name anywhere
    }

    public function testNormalizeMapsTypeAliasesAndLowercases(): void
    {
        $this->assertSame('destination', $this->normalize(['id' => 1, 'name' => 'A', 'type' => 'resort'])['type']);
        $this->assertSame('region', $this->normalize(['id' => 1, 'name' => 'A', 'type' => 'area'])['type']);
        $this->assertSame('region', $this->normalize(['id' => 1, 'name' => 'A', 'type' => 'zone'])['type']);
        $this->assertSame('city', $this->normalize(['id' => 1, 'name' => 'A', 'type' => 'CITY'])['type']); // lowercased
        $this->assertSame('country', $this->normalize(['id' => 1, 'name' => 'A', 'type' => 'country'])['type']);
        $this->assertSame('custom', $this->normalize(['id' => 1, 'name' => 'A', 'type' => 'custom'])['type']); // passthrough
    }

    /**
     * @param array<int|string, mixed> $response
     * @return list<mixed>
     */
    private function extract(array $response): array
    {
        $m = new ReflectionMethod($this->command, 'extractItems');
        $m->setAccessible(true);
        /** @var list<mixed> $result */
        $result = $m->invoke($this->command, $response);

        return $result;
    }

    public function testExtractItemsPrefersDataThenItemsThenBareList(): void
    {
        $this->assertSame([['a' => 1]], $this->extract(['data' => [['a' => 1]]]));
        $this->assertSame([['b' => 2]], $this->extract(['items' => [['b' => 2]]]));
        $this->assertSame([['c' => 3]], $this->extract([['c' => 3]]));   // bare indexed list
        $this->assertSame([], $this->extract([]));                        // empty response → no items
    }

    /**
     * @param array<string, mixed> $response
     */
    private function hasMore(array $response, int $page, int $perPage, int $fetched): bool
    {
        $m = new ReflectionMethod($this->command, 'hasMorePages');
        $m->setAccessible(true);

        return (bool) $m->invoke($this->command, $response, $page, $perPage, $fetched);
    }

    public function testHasMorePagesStopsAtLastPage(): void
    {
        // last_page reached
        $this->assertFalse($this->hasMore(['last_page' => 3, 'data' => array_fill(0, 1000, ['x' => 1])], 3, 1000, 3000));
        // last_page nested under meta
        $this->assertFalse($this->hasMore(['meta' => ['last_page' => 2], 'data' => array_fill(0, 1000, ['x' => 1])], 2, 1000, 2000));
    }

    public function testHasMorePagesStopsWhenTotalReachedOrShortPage(): void
    {
        $this->assertFalse($this->hasMore(['total' => 50, 'data' => array_fill(0, 1000, ['x' => 1])], 1, 1000, 50));
        // Short page (fewer items than perPage) ends pagination.
        $this->assertFalse($this->hasMore(['data' => array_fill(0, 10, ['x' => 1])], 1, 1000, 10));
    }

    public function testHasMorePagesContinuesOnFullPage(): void
    {
        $this->assertTrue($this->hasMore(['data' => array_fill(0, 1000, ['x' => 1])], 1, 1000, 1000));
    }
}
