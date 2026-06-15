<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tygh\Addons\SphinxHolidays\Cron\Commands\DiscoverBoardsCommand;

/**
 * Characterization coverage for the pure surface of DiscoverBoardsCommand,
 * pinned with the boundary-typing paydown that coerced the command's `mixed`
 * reads (the country-param explode and the polled-results aggregation /
 * cursor handling). Both methods are exercised via reflection so no Container
 * service graph is needed; pollResults() takes a hand-rolled API double whose
 * first response is terminal so the 3s inter-poll sleep is never hit.
 */
#[CoversClass(DiscoverBoardsCommand::class)]
final class DiscoverBoardsCommandTest extends TestCase
{
    private DiscoverBoardsCommand $command;

    protected function setUp(): void
    {
        $this->command = (new ReflectionClass(DiscoverBoardsCommand::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function resolveCountryCodes(array $params): array
    {
        $m = new ReflectionMethod($this->command, 'resolveCountryCodes');
        $m->setAccessible(true);
        /** @var list<string> $result */
        $result = $m->invoke($this->command, $params);

        return $result;
    }

    public function testResolveCountryCodesNormalizesCommaList(): void
    {
        // Lower-case, padded, trailing comma → uppercased, trimmed, empties dropped.
        $this->assertSame(['GR', 'TR', 'ES'], $this->resolveCountryCodes(['country' => 'gr, tr ,es,']));
    }

    public function testResolveCountryCodesSingleValue(): void
    {
        $this->assertSame(['HU'], $this->resolveCountryCodes(['country' => ' hu ']));
    }

    private function pollResults(object $api, string $cursor): mixed
    {
        $m = new ReflectionMethod($this->command, 'pollResults');
        $m->setAccessible(true);

        return $m->invoke($this->command, $api, $cursor);
    }

    /**
     * Minimal SphinxApi double: serves a queue of getHotelResults() responses.
     *
     * @param list<array<string, mixed>|null> $responses
     */
    private function apiDouble(array $responses): object
    {
        return new class($responses) {
            /** @param list<array<string, mixed>|null> $responses */
            public function __construct(private array $responses, public int $calls = 0)
            {
            }

            /** @return array<string, mixed>|null */
            public function getHotelResults(string $hotelId, string $cursor): ?array
            {
                $response = $this->responses[$this->calls] ?? null;
                $this->calls++;

                return $response;
            }
        };
    }

    public function testPollResultsAggregatesFromDataKeyAndStopsWhenCompleted(): void
    {
        $api = $this->apiDouble([
            ['data' => [['hotel_id' => 'H1'], ['hotel_id' => 'H2']], 'status' => 'completed'],
        ]);

        $offers = $this->pollResults($api, 'cursor-abc');

        $this->assertSame([['hotel_id' => 'H1'], ['hotel_id' => 'H2']], $offers);
        $this->assertSame(1, $api->calls); // terminal on first poll — no sleep
    }

    public function testPollResultsReadsResultsKeyAndDoneStatus(): void
    {
        $api = $this->apiDouble([
            ['results' => [['hotel_id' => 'X']], 'status' => 'done'],
        ]);

        $this->assertSame([['hotel_id' => 'X']], $this->pollResults($api, 'c'));
    }

    public function testPollResultsReturnsEmptyOnNullResponse(): void
    {
        $api = $this->apiDouble([null]);

        $this->assertSame([], $this->pollResults($api, 'c'));
        $this->assertSame(1, $api->calls);
    }

    public function testPollResultsStopsWhenNoCursorAndNoResults(): void
    {
        // No status, no next cursor, empty results → loop breaks after first poll.
        $api = $this->apiDouble([
            ['data' => [], 'status' => 'pending'],
        ]);

        $this->assertSame([], $this->pollResults($api, 'c'));
        $this->assertSame(1, $api->calls);
    }
}
