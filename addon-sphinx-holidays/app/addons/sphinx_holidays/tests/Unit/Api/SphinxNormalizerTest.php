<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;

#[CoversClass(SphinxNormalizer::class)]
class SphinxNormalizerTest extends TestCase
{
    private SphinxNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SphinxNormalizer();
        // Static collector is process-global — hygiene required.
        SphinxNormalizer::clearUnknownBoards();
    }

    protected function tearDown(): void
    {
        SphinxNormalizer::clearUnknownBoards();
    }

    public function testGetProviderName(): void
    {
        $this->assertSame('sphinx', $this->normalizer->getProviderName());
    }

    // ── normalizeStarRating ─────────────────────────────────────────────────

    public function testNormalizeStarRatingValidRange(): void
    {
        $this->assertSame('1', $this->normalizer->normalizeStarRating('1'));
        $this->assertSame('3', $this->normalizer->normalizeStarRating(3));
        $this->assertSame('5', $this->normalizer->normalizeStarRating('5'));
    }

    public function testNormalizeStarRatingBoundaryBelowAndAbove(): void
    {
        $this->assertNull($this->normalizer->normalizeStarRating('0'));
        $this->assertNull($this->normalizer->normalizeStarRating('6'));
        $this->assertNull($this->normalizer->normalizeStarRating(-1));
    }

    public function testNormalizeStarRatingNullAndEmpty(): void
    {
        $this->assertNull($this->normalizer->normalizeStarRating(null));
        $this->assertNull($this->normalizer->normalizeStarRating(''));
    }

    public function testNormalizeStarRatingNonNumeric(): void
    {
        // (int) 'abc' → 0 → out of range → null
        $this->assertNull($this->normalizer->normalizeStarRating('abc'));
    }

    // ── normalizeBoardCode ──────────────────────────────────────────────────

    public function testNormalizeBoardCodeDirectMatches(): void
    {
        $this->assertSame('AI', $this->normalizer->normalizeBoardCode('all inclusive'));
        $this->assertSame('FB', $this->normalizer->normalizeBoardCode('full board'));
        $this->assertSame('HB', $this->normalizer->normalizeBoardCode('half board'));
        $this->assertSame('BB', $this->normalizer->normalizeBoardCode('bed and breakfast'));
        $this->assertSame('BB', $this->normalizer->normalizeBoardCode('b&b'));
        $this->assertSame('RO', $this->normalizer->normalizeBoardCode('room only'));
        $this->assertSame('SC', $this->normalizer->normalizeBoardCode('self catering'));
    }

    public function testNormalizeBoardCodeCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame('AI', $this->normalizer->normalizeBoardCode('  ALL INCLUSIVE  '));
        $this->assertSame('FB', $this->normalizer->normalizeBoardCode('Full Board'));
    }

    public function testNormalizeBoardCodePartialMatch(): void
    {
        // Unknown values that contain a known substring route through
        // the partial-match fallback.
        $this->assertSame('AI', $this->normalizer->normalizeBoardCode('all inclusive premium'));
    }

    public function testNormalizeBoardCodePrecedenceUltraBeforeAll(): void
    {
        // Direct match wins over partial match.
        $this->assertSame('UAI', $this->normalizer->normalizeBoardCode('ultra all inclusive'));
        // Partial: 'ultra all inclusive' appears first in the map, so
        // 'ultra all inclusive premium' partial-matches UAI rather than AI.
        $this->assertSame('UAI', $this->normalizer->normalizeBoardCode('ultra all inclusive premium'));
    }

    public function testNormalizeBoardCodeRomanianWithDiacritic(): void
    {
        $this->assertSame('FB', $this->normalizer->normalizeBoardCode('pensiune completă'));
        $this->assertSame('RO', $this->normalizer->normalizeBoardCode('fără masă'));
    }

    public function testNormalizeBoardCodeRomanianWithoutDiacritic(): void
    {
        $this->assertSame('FB', $this->normalizer->normalizeBoardCode('pensiune completa'));
        $this->assertSame('HB', $this->normalizer->normalizeBoardCode('demipensiune'));
        $this->assertSame('BB', $this->normalizer->normalizeBoardCode('mic dejun'));
        $this->assertSame('RO', $this->normalizer->normalizeBoardCode('fara masa'));
    }

    public function testNormalizeBoardCodeInvalidInputsReturnNull(): void
    {
        $this->assertNull($this->normalizer->normalizeBoardCode(null));
        $this->assertNull($this->normalizer->normalizeBoardCode(''));
        $this->assertNull($this->normalizer->normalizeBoardCode(0));
        $this->assertNull($this->normalizer->normalizeBoardCode(42));
        $this->assertNull($this->normalizer->normalizeBoardCode(['foo']));
    }

    public function testNormalizeBoardCodeTracksUnknownInCollector(): void
    {
        $this->assertNull($this->normalizer->normalizeBoardCode('mysterious buffet'));
        $this->assertNull($this->normalizer->normalizeBoardCode('mysterious buffet'));
        $this->assertNull($this->normalizer->normalizeBoardCode('another unknown'));

        $unknown = SphinxNormalizer::getUnknownBoards();
        $this->assertSame(2, $unknown['mysterious buffet']);
        $this->assertSame(1, $unknown['another unknown']);
    }

    public function testClearUnknownBoardsResetsCollector(): void
    {
        $this->normalizer->normalizeBoardCode('alien dining');
        $this->assertSame(['alien dining' => 1], SphinxNormalizer::getUnknownBoards());

        SphinxNormalizer::clearUnknownBoards();
        $this->assertSame([], SphinxNormalizer::getUnknownBoards());
    }

    // ── normalizeRoomTypeCode ───────────────────────────────────────────────

    public function testNormalizeRoomTypeCodePrefixes(): void
    {
        $this->assertSame('SGL', $this->normalizer->normalizeRoomTypeCode('single room'));
        $this->assertSame('DBL', $this->normalizer->normalizeRoomTypeCode('double bed'));
        $this->assertSame('TWIN', $this->normalizer->normalizeRoomTypeCode('twin room'));
        $this->assertSame('TRP', $this->normalizer->normalizeRoomTypeCode('triple deluxe'));
        $this->assertSame('QUAD', $this->normalizer->normalizeRoomTypeCode('quad family'));
        $this->assertSame('SUITE', $this->normalizer->normalizeRoomTypeCode('suite presidential'));
        $this->assertSame('APT', $this->normalizer->normalizeRoomTypeCode('apartment one bed'));
        $this->assertSame('STUDIO', $this->normalizer->normalizeRoomTypeCode('studio loft'));
        $this->assertSame('DBL', $this->normalizer->normalizeRoomTypeCode('family deluxe'));
    }

    public function testNormalizeRoomTypeCodeCaseInsensitive(): void
    {
        $this->assertSame('SGL', $this->normalizer->normalizeRoomTypeCode('  SINGLE Deluxe '));
    }

    public function testNormalizeRoomTypeCodeUnmatchedReturnsNull(): void
    {
        $this->assertNull($this->normalizer->normalizeRoomTypeCode('penthouse'));
        $this->assertNull($this->normalizer->normalizeRoomTypeCode(''));
        $this->assertNull($this->normalizer->normalizeRoomTypeCode(null));
        $this->assertNull($this->normalizer->normalizeRoomTypeCode(42));
    }

    // ── normalizePropertyType ───────────────────────────────────────────────

    public function testNormalizePropertyTypeDirect(): void
    {
        $this->assertSame('hotel', $this->normalizer->normalizePropertyType('Hotel'));
        $this->assertSame('villa', $this->normalizer->normalizePropertyType('VILLA'));
        $this->assertSame('apartment', $this->normalizer->normalizePropertyType('apartment'));
        $this->assertSame('resort', $this->normalizer->normalizePropertyType('Resort'));
        $this->assertSame('hostel', $this->normalizer->normalizePropertyType('hostel'));
        $this->assertSame('motel', $this->normalizer->normalizePropertyType('motel'));
    }

    public function testNormalizePropertyTypeAliases(): void
    {
        $this->assertSame('guest_house', $this->normalizer->normalizePropertyType('guesthouse'));
        $this->assertSame('guest_house', $this->normalizer->normalizePropertyType('pension'));
        $this->assertSame('guest_house', $this->normalizer->normalizePropertyType('pensiune'));
        $this->assertSame('chalet', $this->normalizer->normalizePropertyType('chalet'));
        $this->assertSame('chalet', $this->normalizer->normalizePropertyType('cabana'));
    }

    public function testNormalizePropertyTypeUnknownAndInvalid(): void
    {
        $this->assertNull($this->normalizer->normalizePropertyType('treehouse'));
        $this->assertNull($this->normalizer->normalizePropertyType(''));
        $this->assertNull($this->normalizer->normalizePropertyType(null));
        $this->assertNull($this->normalizer->normalizePropertyType(42));
    }

    // ── normalizeFacilityCode ───────────────────────────────────────────────

    public function testNormalizeFacilityCodePassthrough(): void
    {
        // Sphinx facilities come as numeric IDs — pass through as-is.
        $this->assertSame('123', $this->normalizer->normalizeFacilityCode(123));
        $this->assertSame('456', $this->normalizer->normalizeFacilityCode('456'));
        $this->assertSame('wifi', $this->normalizer->normalizeFacilityCode('wifi'));
    }

    public function testNormalizeFacilityCodeInvalid(): void
    {
        $this->assertNull($this->normalizer->normalizeFacilityCode(null));
        $this->assertNull($this->normalizer->normalizeFacilityCode(['array']));
        $this->assertNull($this->normalizer->normalizeFacilityCode(true));
    }

    // ── normalizeResort ─────────────────────────────────────────────────────

    public function testNormalizeResortTrimsAndTitleCases(): void
    {
        $this->assertSame('Sunny Beach', $this->normalizer->normalizeResort('  sunny beach  '));
        $this->assertSame('Mamaia', $this->normalizer->normalizeResort('MAMAIA'));
    }

    public function testNormalizeResortInvalidInputs(): void
    {
        $this->assertNull($this->normalizer->normalizeResort(null));
        $this->assertNull($this->normalizer->normalizeResort(''));
        $this->assertNull($this->normalizer->normalizeResort('   '));
        $this->assertNull($this->normalizer->normalizeResort(42));
    }

    // ── normalizeBookingStatus ──────────────────────────────────────────────

    public function testNormalizeBookingStatusSynonyms(): void
    {
        $this->assertSame(TravelConstants::STATUS_CONFIRMED, $this->normalizer->normalizeBookingStatus('Confirmed'));
        $this->assertSame(TravelConstants::STATUS_PENDING, $this->normalizer->normalizeBookingStatus('pending'));
        $this->assertSame(TravelConstants::STATUS_PENDING, $this->normalizer->normalizeBookingStatus('on_hold'));
        $this->assertSame(TravelConstants::STATUS_CANCELLED, $this->normalizer->normalizeBookingStatus('cancelled'));
        $this->assertSame(TravelConstants::STATUS_CANCELLED, $this->normalizer->normalizeBookingStatus('canceled'));
        $this->assertSame(TravelConstants::STATUS_FAILED, $this->normalizer->normalizeBookingStatus('rejected'));
        $this->assertSame(TravelConstants::STATUS_FAILED, $this->normalizer->normalizeBookingStatus('failed'));
    }

    public function testNormalizeBookingStatusUnknownFallsBackToPending(): void
    {
        $this->assertSame(TravelConstants::STATUS_PENDING, $this->normalizer->normalizeBookingStatus('quantum'));
        $this->assertSame(TravelConstants::STATUS_PENDING, $this->normalizer->normalizeBookingStatus(''));
    }
}
