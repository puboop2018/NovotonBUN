<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the hotel_list coordinate capture to accept BOTH element spellings.
 * Regression risk: the code read only $hotel->Lat / $hotel->Lng while the
 * Eurosite spec documents the elements as Latitude / Longitude — if the live
 * feed uses the spec names, every hotel row was silently persisted with
 * blank coordinates (breaking the PDP/search "show map" links).
 */
final class HotelListCoordinateCaptureTest extends TestCase
{
    public function testBothCoordinateSpellingsAreAccepted(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Cron/Commands/HotelListSyncCommand.php',
        );

        self::assertStringContainsString('$hotel->Lat ?? $hotel->Latitude', $src);
        self::assertStringContainsString('$hotel->Lng ?? $hotel->Longitude', $src);
    }
}
