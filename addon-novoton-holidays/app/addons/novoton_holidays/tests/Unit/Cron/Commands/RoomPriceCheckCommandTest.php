<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Cron\Commands\RoomPriceCheckCommand;

/**
 * Characterization coverage for RoomPriceCheckCommand::formatCountryGroups() —
 * the pure "group priced hotels by country" formatter used by the room_price
 * cron's end-of-run summary. Pins alphabetical country ordering, the
 * "COUNTRY (N):" count header, hotel indentation, and the empty-input case.
 */
#[CoversClass(RoomPriceCheckCommand::class)]
class RoomPriceCheckCommandTest extends TestCase
{
    public function testEmptyInputReturnsNoLines(): void
    {
        $this->assertSame([], RoomPriceCheckCommand::formatCountryGroups([]));
    }

    public function testGroupsCountriesAlphabeticallyWithCountsAndIndentation(): void
    {
        // Intentionally unsorted input (GREECE before BULGARIA) to prove the sort.
        $lines = RoomPriceCheckCommand::formatCountryGroups([
            'GREECE' => ['NVT-4179 | ADMIRAL PLAZA'],
            'BULGARIA' => ['NVT-476 | ADMIRAL', 'NVT-895 | AKTINIA'],
        ]);

        $this->assertSame([
            '',
            '=== Hotels WITH prices — grouped by country ===',
            'BULGARIA (2):',
            '  NVT-476 | ADMIRAL',
            '  NVT-895 | AKTINIA',
            'GREECE (1):',
            '  NVT-4179 | ADMIRAL PLAZA',
        ], $lines);
    }

    public function testSingleCountrySingleHotel(): void
    {
        $lines = RoomPriceCheckCommand::formatCountryGroups([
            'TURKEY' => ['NVT-9 | RIVIERA'],
        ]);

        $this->assertSame([
            '',
            '=== Hotels WITH prices — grouped by country ===',
            'TURKEY (1):',
            '  NVT-9 | RIVIERA',
        ], $lines);
    }

    public function testUnknownBucketSortsAfterNamedCountries(): void
    {
        $lines = RoomPriceCheckCommand::formatCountryGroups([
            'UNKNOWN' => ['NVT-1 | NO COUNTRY'],
            'BULGARIA' => ['NVT-476 | ADMIRAL'],
        ]);

        // 'BULGARIA' (B) sorts before 'UNKNOWN' (U).
        $this->assertSame('BULGARIA (1):', $lines[2]);
        $this->assertSame('UNKNOWN (1):', $lines[4]);
    }
}
