<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Cron\Commands\BackfillHotelLocationsCommand;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * End-to-end (through DbStub) coverage of the Region/City backfill: the
 * command must repair only the empty columns from the destination tree,
 * classify unrepairable rows, and refuse to run against an empty tree.
 *
 * Fixture tree: 400 (country TR "Turkey") ← 500 (region "Antalya")
 * ← 501 (destination "Side").
 */
#[CoversClass(BackfillHotelLocationsCommand::class)]
final class BackfillHotelLocationsCommandTest extends TestCase
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $updates = [];

    protected function setUp(): void
    {
        DbStub::reset();
        Container::reset();
        $this->updates = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Container::reset();
    }

    /**
     * @param list<array<string, mixed>> $hotelRows
     */
    private function stubDb(array $hotelRows, bool $withDestinations = true): void
    {
        $destRows = $withDestinations ? [
            ['destination_id' => 400, 'name' => 'Turkey', 'type' => 'country', 'parent_id' => 0, 'country_code' => 'TR'],
            ['destination_id' => 500, 'name' => 'Antalya', 'type' => 'region', 'parent_id' => 400, 'country_code' => ''],
            ['destination_id' => 501, 'name' => 'Side', 'type' => 'destination', 'parent_id' => 500, 'country_code' => ''],
        ] : [];

        $hotelsServed = false;
        DbStub::$getArray = static function (string $query) use ($destRows, $hotelRows, &$hotelsServed): array {
            if (str_contains($query, '?:sphinx_destinations')) {
                // loadChunked stops when a chunk is smaller than its chunk size.
                return $destRows;
            }
            if (str_contains($query, '?:sphinx_hotels')) {
                if ($hotelsServed) {
                    return []; // keyset pagination: second page is empty
                }
                $hotelsServed = true;
                return $hotelRows;
            }
            return [];
        };

        DbStub::$query = function (string $query, ...$params): int {
            $this->updates[] = ['sql' => $query, 'params' => $params];
            return 1;
        };
    }

    public function testRefusesToRunAgainstEmptyDestinationTree(): void
    {
        $this->stubDb([], false);

        $result = (new BackfillHotelLocationsCommand())->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->updates, 'no writes may happen without a tree');
    }

    public function testBackfillsOnlyEmptyColumnsAndClassifiesSkips(): void
    {
        $this->stubDb([
            // both empty → both filled from the tree (+ region_id)
            ['hotel_id' => 'H1', 'name' => 'Fixable', 'destination_id' => 501,
                'destination_name' => '', 'region_id' => 0, 'region_name' => ''],
            // stale city → the ladder is authoritative and corrects it
            ['hotel_id' => 'H2', 'name' => 'Partial', 'destination_id' => 501,
                'destination_name' => 'Kept City', 'region_id' => 0, 'region_name' => ''],
            // no destination_id → unrepairable here
            ['hotel_id' => 'H3', 'name' => 'NoDest', 'destination_id' => 0,
                'destination_name' => '', 'region_id' => 0, 'region_name' => ''],
            // destination unknown to the tree → unresolved
            ['hotel_id' => 'H4', 'name' => 'Unknown', 'destination_id' => 999,
                'destination_name' => '', 'region_id' => 0, 'region_name' => ''],
        ]);

        $result = (new BackfillHotelLocationsCommand())->execute();

        self::assertTrue($result['success']);
        self::assertSame(
            ['scanned' => 4, 'updated' => 2, 'flagged_for_features' => 0,
                'no_destination_id' => 1, 'unresolved' => 1],
            $result['stats'],
        );

        self::assertCount(2, $this->updates);
        self::assertSame(
            ['destination_name' => 'Side', 'region_name' => 'Antalya', 'region_id' => 500,
                'location_source' => 'ancestor'],
            $this->updates[0]['params'][0],
            'H1 gets city from its own node, region from the parent, and the provenance',
        );
        self::assertSame('H1', $this->updates[0]['params'][1]);
        self::assertSame(
            ['destination_name' => 'Side', 'region_name' => 'Antalya', 'region_id' => 500,
                'location_source' => 'ancestor'],
            $this->updates[1]['params'][0],
            'H2 had a stale city — the type-gated ladder overrides it with the tree name',
        );
        self::assertSame('H2', $this->updates[1]['params'][1]);
    }

    public function testNothingToDoWhenNoRowsMatch(): void
    {
        $this->stubDb([]);

        $result = (new BackfillHotelLocationsCommand())->execute();

        self::assertTrue($result['success']);
        self::assertSame(['scanned' => 0, 'updated' => 0], $result['stats']);
        self::assertSame([], $this->updates);
    }
}
