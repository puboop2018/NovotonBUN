<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * Pins the Romanian room-type display names (proper diacritics — "Cameră
 * Dublă", not "Camera Dubla") and formatRoomLabel's idempotency guard, which
 * must accept BOTH spellings: labels persisted before the diacritics change
 * ("Camera Dubla (DBL 2+1)") re-enter the formatter from stored order/booking
 * data and must not be double-wrapped.
 */
final class RoomTypeTest extends TestCase
{
    public function testDisplayNamesCarryRomanianDiacritics(): void
    {
        self::assertSame('Cameră Dublă', RoomType::toDisplayName('DBL'));
        self::assertSame('Cameră Triplă', RoomType::toDisplayName('TRP'));
        self::assertSame('Cameră Cvadruplă', RoomType::toDisplayName('QUA'));
        self::assertSame('Suită', RoomType::toDisplayName('SUITE'));
        self::assertSame('Suită Junior', RoomType::toDisplayName('JST'));
        self::assertSame('Vilă', RoomType::toDisplayName('VILLA'));
        // Deliberately NOT Romanianized — the word has no Romanian form.
        self::assertSame('Maisonette', RoomType::toDisplayName('MAISONETTE'));
        self::assertSame('Cameră Familială', RoomType::toDisplayName('FAM'));
        self::assertSame('Cameră Superioară', RoomType::toDisplayName('SUP'));
    }

    public function testAliasCodesResolveToTheSameDiacriticNames(): void
    {
        self::assertSame('Cameră Triplă', RoomType::toDisplayName('TRPL'));
        self::assertSame('Suită', RoomType::toDisplayName('STE'));
        self::assertSame('Vilă', RoomType::toDisplayName('VLA'));
    }

    public function testUnknownCodePassesThroughAndBedroomsFormat(): void
    {
        self::assertSame('XYZ', RoomType::toDisplayName('XYZ'));
        self::assertSame('Apartament 2 Dormitoare', RoomType::toDisplayName('2-BR'));
        self::assertSame('Apartament 1 Dormitor', RoomType::toDisplayName('1-BR'));
    }

    public function testFormatRoomLabelBuildsDiacriticLabel(): void
    {
        self::assertSame('Cameră Dublă (DBL 2+1)', RoomType::formatRoomLabel('DBL 2+1', 'DBL'));
    }

    public function testFormatRoomLabelIsIdempotentForBothSpellingEras(): void
    {
        // New-era label (with diacritics) passes through unchanged.
        self::assertSame(
            'Cameră Dublă (DBL 2+1)',
            RoomType::formatRoomLabel('DBL 2+1', 'Cameră Dublă (DBL 2+1)'),
        );
        // Legacy label persisted BEFORE the diacritics change must not double-wrap.
        self::assertSame(
            'Camera Dubla (DBL 2+1)',
            RoomType::formatRoomLabel('DBL 2+1', 'Camera Dubla (DBL 2+1)'),
        );
        self::assertSame(
            'Suită Deluxe (STE A)',
            RoomType::formatRoomLabel('STE A', 'Suită Deluxe (STE A)'),
        );
        // New word order and the international maisonette both short-circuit.
        self::assertSame(
            'Suită Junior (JST A)',
            RoomType::formatRoomLabel('JST A', 'Suită Junior (JST A)'),
        );
        self::assertSame(
            'Maisonette (MAI 1)',
            RoomType::formatRoomLabel('MAI 1', 'Maisonette (MAI 1)'),
        );
    }

    public function testNormalizeRoomCodeUnaffected(): void
    {
        self::assertSame('QUAT 2+2 BALCONY', RoomType::normalizeRoomCode('QUAT 2 2 BALCONY'));
        self::assertSame('DBL 2+1', RoomType::normalizeRoomCode(' DBL 2+1 '));
    }
}
