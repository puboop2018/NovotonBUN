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
        self::assertSame('Cameră Dublă', RoomType::toDisplayName('DBL', 'ro'));
        self::assertSame('Cameră Triplă', RoomType::toDisplayName('TRP', 'ro'));
        self::assertSame('Cameră Cvadruplă', RoomType::toDisplayName('QUA', 'ro'));
        self::assertSame('Suită', RoomType::toDisplayName('SUITE', 'ro'));
        self::assertSame('Suită Junior', RoomType::toDisplayName('JST', 'ro'));
        self::assertSame('Vilă', RoomType::toDisplayName('VILLA', 'ro'));
        // Deliberately NOT Romanianized — the word has no Romanian form.
        self::assertSame('Maisonette', RoomType::toDisplayName('MAISONETTE', 'ro'));
        self::assertSame('Cameră Familială', RoomType::toDisplayName('FAM', 'ro'));
        self::assertSame('Cameră Superioară', RoomType::toDisplayName('SUP', 'ro'));
    }

    public function testAliasCodesResolveToTheSameDiacriticNames(): void
    {
        self::assertSame('Cameră Triplă', RoomType::toDisplayName('TRPL', 'ro'));
        self::assertSame('Suită', RoomType::toDisplayName('STE', 'ro'));
        self::assertSame('Vilă', RoomType::toDisplayName('VLA', 'ro'));
    }

    public function testUnknownCodePassesThroughAndBedroomsFormat(): void
    {
        self::assertSame('XYZ', RoomType::toDisplayName('XYZ', 'ro'));
        self::assertSame('Apartament 2 Dormitoare', RoomType::toDisplayName('2-BR', 'ro'));
        self::assertSame('Apartament 1 Dormitor', RoomType::toDisplayName('1-BR', 'ro'));
    }

    public function testFormatRoomLabelBuildsDiacriticLabel(): void
    {
        self::assertSame('Cameră Dublă (DBL 2+1)', RoomType::formatRoomLabel('DBL 2+1', 'DBL', 'ro'));
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

    public function testProviderTypeNamesResolveToCanonicalDisplayNames(): void
    {
        // The hotelinfo API sends free-text Type strings; these must not leak
        // verbatim into the storefront ("QUATRIPLE" is the provider's spelling).
        self::assertSame('Cameră Cvadruplă', RoomType::toDisplayName('QUATRIPLE', 'ro'));
        self::assertSame('Cameră Cvadruplă', RoomType::toDisplayName('QUAT', 'ro'));
        self::assertSame('Cameră Cvadruplă', RoomType::toDisplayName('Quadruple', 'ro'));
        self::assertSame('Cameră Dublă', RoomType::toDisplayName('Camera Dubla', 'ro'));
        self::assertSame('Cameră Triplă', RoomType::toDisplayName('Camera Tripla', 'ro'));
        self::assertSame('Suită', RoomType::toDisplayName('Suita', 'ro'));
        self::assertSame('Vilă', RoomType::toDisplayName('Vila', 'ro'));
    }

    public function testFormatRoomLabelRewritesProviderTypeNames(): void
    {
        self::assertSame(
            'Cameră Cvadruplă (QUAT 2+2 FRENCH BALCONY)',
            RoomType::formatRoomLabel('QUAT 2+2 FRENCH BALCONY', 'QUATRIPLE', 'ro'),
        );
        self::assertSame(
            'Cameră Dublă (DBL 2+0 FRENCH BALCONY)',
            RoomType::formatRoomLabel('DBL 2+0 FRENCH BALCONY', 'Camera Dubla', 'ro'),
        );
        // roomId-prefix fallback (no provider type): QUAT maps too.
        self::assertSame(
            'Cameră Cvadruplă (QUAT 2+2)',
            RoomType::formatRoomLabel('QUAT 2+2', '', 'ro'),
        );
    }

    public function testEnglishDisplayNames(): void
    {
        self::assertSame('Quadruple Room', RoomType::toDisplayName('QUATRIPLE', 'en'));
        self::assertSame('Double Room', RoomType::toDisplayName('DBL', 'en'));
        self::assertSame('Junior Suite', RoomType::toDisplayName('JST', 'en'));
        self::assertSame('Apartment 2 Bedrooms', RoomType::toDisplayName('2-BR', 'en'));
        self::assertSame('Apartment 1 Bedroom', RoomType::toDisplayName('1-BR', 'en'));
        // Explicit language always wins over CART_LANGUAGE.
        self::assertSame('Cameră Dublă', RoomType::toDisplayName('DBL', 'ro'));
    }

    public function testEnglishFormattedLabelsAreIdempotentToo(): void
    {
        self::assertSame(
            'Double Room (DBL 2+1)',
            RoomType::formatRoomLabel('DBL 2+1', 'Double Room (DBL 2+1)'),
        );
    }
}
