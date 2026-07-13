<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\GeocodeAddressesCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\NominatimClient;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Registry;

/**
 * Behavioural coverage for the geocode_addresses cron: the opt-in guards
 * (disabled setting / missing contact email do NOT touch the DB or the
 * network), the eligible-rows selection (real coordinates, no stamp), the
 * stamp-even-without-street rule that makes re-runs naturally resumable,
 * the 1 req/s pacing between (not before) requests, force=1 clearing the
 * stamps, the read-only status view, and the abort-on-transport-failure
 * behaviour that leaves remaining hotels unstamped for the next run.
 */
#[CoversClass(GeocodeAddressesCommand::class)]
final class GeocodeAddressesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    private int $sleeps = 0;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->out = [];
        $this->queries = [];
        $this->sleeps = 0;

        Registry::set('addons.novoton_holidays', [
            'geocoding_enabled' => 'Y',
            'geocoding_contact_email' => 'ops@example.com',
        ]);
        ConfigProvider::reset();

        DbStub::$query = function (string $query, ...$params): int {
            $this->queries[] = [$query, $params];

            return 1;
        };
        DbStub::$getField = static fn (string $query, ...$params): int => 0;
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::reset();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function command(array $params = []): GeocodeAddressesCommand
    {
        $cmd = (new \ReflectionClass(GeocodeAddressesCommand::class))->newInstanceWithoutConstructor();

        $paramsProp = new \ReflectionProperty(\Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand::class, 'params');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($cmd, $params);

        $startProp = new \ReflectionProperty(\Tygh\Addons\TravelCore\Cron\AbstractCronCommand::class, 'startTime');
        $startProp->setAccessible(true);
        $startProp->setValue($cmd, microtime(true));

        $cmd->setOutputCallback(function (string $msg, bool $addNewline = true): void {
            $this->out[] = $msg;
        });
        $cmd->setSleeper(function (int $seconds): void {
            $this->sleeps += $seconds;
        });

        return $cmd;
    }

    /**
     * Real client over a queued fake transport (the class is final).
     *
     * @param list<array{int, string}> $responses
     */
    private function clientWithResponses(array $responses): NominatimClient
    {
        $i = 0;

        return new NominatimClient(
            static function (string $url, string $userAgent) use (&$i, $responses): array {
                $response = $responses[$i] ?? [200, '{}'];
                $i++;

                return $response;
            },
            'https://nominatim.example.org',
            'ops@example.com',
        );
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function hotelUpdates(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_starts_with($q[0], 'UPDATE ?:novoton_hotels'),
        ));
    }

    public function testModeRegistrationPins(): void
    {
        self::assertSame(['geocode_addresses'], GeocodeAddressesCommand::getModes());
        self::assertStringContainsString('Nominatim', GeocodeAddressesCommand::getDescription());
    }

    public function testDisabledSettingExitsWithoutTouchingAnything(): void
    {
        Registry::set('addons.novoton_holidays', ['geocoding_enabled' => 'N']);
        ConfigProvider::reset();

        $result = $this->command()->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->queries);
        self::assertStringContainsString('disabled', implode("\n", $this->out));
    }

    public function testMissingContactEmailRefusesToRun(): void
    {
        Registry::set('addons.novoton_holidays', ['geocoding_enabled' => 'Y']);
        ConfigProvider::reset();

        $result = $this->command()->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->queries);
        self::assertStringContainsString('contact email', implode("\n", $this->out));
    }

    public function testRunStampsBothOutcomesAndPacesBetweenRequests(): void
    {
        $selects = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$selects): array {
            $selects[] = $query;

            return [
                ['hotel_id' => '4535', 'hotel_name' => 'Monaco Hotel', 'latitude' => '41.2818', 'longitude' => '19.4416'],
                ['hotel_id' => '4536', 'hotel_name' => 'Mare Hotel', 'latitude' => '41.3', 'longitude' => '19.45'],
            ];
        };

        $cmd = $this->command(['limit' => 5]);
        $cmd->setClient($this->clientWithResponses([
            [200, (string) json_encode(['address' => ['road' => 'Rruga e Plazhit', 'house_number' => '3']])],
            [200, (string) json_encode(['address' => ['city' => 'Golem']])],
        ]));

        $result = $cmd->execute();

        // Selection pins: only real, never-stamped coordinates are eligible.
        self::assertCount(1, $selects);
        self::assertStringContainsString('latitude IS NOT NULL', $selects[0]);
        self::assertStringContainsString('latitude <> 0', $selects[0]);
        self::assertStringContainsString('geocoded_at IS NULL', $selects[0]);
        self::assertStringContainsString('LIMIT ?i', $selects[0]);

        $updates = $this->hotelUpdates();
        self::assertCount(2, $updates);

        // Street found → stored and stamped.
        self::assertStringContainsString('SET street_address = ?s, geocoded_at = NOW()', $updates[0][0]);
        self::assertSame(['Rruga e Plazhit 3', '4535'], $updates[0][1]);

        // No street → still stamped (resumability), street stays NULL.
        self::assertStringContainsString('SET street_address = NULL, geocoded_at = NOW()', $updates[1][0]);
        self::assertSame(['4536'], $updates[1][1]);

        // Paced BETWEEN requests only: 2 requests → 1 second slept.
        self::assertSame(1, $this->sleeps);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['stats']['geocoded'] ?? null);
        self::assertSame(1, $result['stats']['no_street'] ?? null);
    }

    public function testTransportFailureAbortsLeavingRowsUnstamped(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [
            ['hotel_id' => '4535', 'hotel_name' => 'Monaco Hotel', 'latitude' => '41.2818', 'longitude' => '19.4416'],
            ['hotel_id' => '4536', 'hotel_name' => 'Mare Hotel', 'latitude' => '41.3', 'longitude' => '19.45'],
        ];

        $cmd = $this->command();
        $cmd->setClient($this->clientWithResponses([[429, '']]));

        $result = $cmd->execute();

        self::assertFalse($result['success']);
        self::assertSame([], $this->hotelUpdates());
        self::assertStringContainsString('ABORT', implode("\n", $this->out));
    }

    public function testForceClearsAllStampsFirst(): void
    {
        DbStub::$getArray = static fn (string $query, ...$params): array => [];

        $result = $this->command(['force' => '1'])->execute();

        self::assertTrue($result['success']);
        self::assertCount(1, $this->hotelUpdates());
        self::assertSame('UPDATE ?:novoton_hotels SET geocoded_at = NULL', $this->hotelUpdates()[0][0]);
    }

    public function testStatusIsReadOnly(): void
    {
        $result = $this->command(['status' => '1'])->execute();

        self::assertTrue($result['success']);
        self::assertSame([], $this->hotelUpdates());
        self::assertArrayHasKey('pending', $result['stats']);
        self::assertArrayHasKey('attempted', $result['stats']);
        self::assertStringContainsString('Geocoding status:', implode("\n", $this->out));
    }
}
