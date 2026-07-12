<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the sphinx search results page to novoton parity:
 * 1. Page order — the booking search form renders ABOVE the hotel header.
 * 2. Availability badge — same format as novoton's
 *    ("✓ Available: N room(s), M offer(s) for X adults[, Y children]"),
 *    rooms = DISTINCT room types, party suffix from the searched guests,
 *    on BOTH render paths (server-rendered results and the poll JS that
 *    streams offers in).
 */
final class SearchLayoutBadgeTest extends TestCase
{
    private static function searchTpl(): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/search.tpl';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testBookingFormRendersAboveTheHotelHeader(): void
    {
        $tpl = self::searchTpl();

        $formPos = strpos($tpl, 'travel-search-form-wrapper');
        $headerPos = strpos($tpl, 'travel-hotel-header ');
        self::assertIsInt($formPos);
        self::assertIsInt($headerPos);
        self::assertLessThan($headerPos, $formPos, 'the search form must come before the hotel header (novoton parity)');
        // The badge sits in the hotel header row, like novoton's.
        self::assertStringContainsString('travel-hotel-header-row', $tpl);
    }

    public function testServerBadgeMatchesNovotonFormat(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('$sx_badge_room_keys', $tpl, 'rooms are counted as distinct room types');
        self::assertStringContainsString('sphinx_holidays.available', $tpl);
        self::assertStringContainsString('sphinx_holidays.for', $tpl);
        self::assertStringContainsString('data-party-suffix', $tpl);
        // The old "N results found" count span is gone.
        self::assertStringNotContainsString('sphinx-results-count', $tpl);
    }

    public function testPollJsRebuildsTheBadgeInTheSameFormat(): void
    {
        $tpl = self::searchTpl();

        self::assertStringContainsString('updateBadgeText', $tpl);
        self::assertStringContainsString('seenRoomKeys', $tpl, 'the poll path deduplicates room types too');
        self::assertStringContainsString("title.getAttribute('data-party-suffix')", $tpl);
        // Badge vocabulary is exported to the poll JS.
        foreach (['available:', 'room:', 'rooms:', 'offer:', 'offers:'] as $label) {
            self::assertStringContainsString($label, $tpl, "__sphinxConfig.labels must carry {$label}");
        }
    }
}
