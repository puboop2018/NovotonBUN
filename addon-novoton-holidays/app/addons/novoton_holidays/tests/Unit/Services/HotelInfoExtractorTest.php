<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\HotelInfoExtractor;

/**
 * Characterization coverage for HotelInfoExtractor — the pure hotelinfo-XML
 * parsing extracted from HotelAvailabilitySearcher. Pins the room-node
 * extraction, the IdRoom => Type map (skipping rows missing either field),
 * and the board-type rules: the `IdBoard ?: text` fallback, the hard-coded
 * default list when no boards exist, and the meal-plan re-ordering that floats
 * the preferred board(s) to the front.
 */
#[CoversClass(HotelInfoExtractor::class)]
class HotelInfoExtractorTest extends TestCase
{
    private HotelInfoExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HotelInfoExtractor();
    }

    private function xml(string $body): \SimpleXMLElement
    {
        $element = simplexml_load_string("<hotelinfo>{$body}</hotelinfo>");
        self::assertInstanceOf(\SimpleXMLElement::class, $element);
        return $element;
    }

    // ── extractRooms ─────────────────────────────────────────────────────────

    public function testExtractRoomsReturnsEachRoomsNode(): void
    {
        $rooms = $this->extractor->extractRooms($this->xml(
            '<rooms><IdRoom>R1</IdRoom><Type>DBL</Type></rooms>'
            . '<rooms><IdRoom>R2</IdRoom><Type>SGL</Type></rooms>',
        ));

        $this->assertCount(2, $rooms);
        $this->assertSame('R1', (string) $rooms[0]->IdRoom);
        $this->assertSame('R2', (string) $rooms[1]->IdRoom);
    }

    public function testExtractRoomsEmptyWhenAbsent(): void
    {
        $this->assertSame([], $this->extractor->extractRooms($this->xml('<name>Hotel</name>')));
    }

    // ── buildRoomTypeMap ─────────────────────────────────────────────────────

    public function testBuildRoomTypeMapMapsIdToType(): void
    {
        $rooms = $this->extractor->extractRooms($this->xml(
            '<rooms><IdRoom>R1</IdRoom><Type>DBL</Type></rooms>'
            . '<rooms><IdRoom>R2</IdRoom><Type>SGL</Type></rooms>',
        ));

        $this->assertSame(['R1' => 'DBL', 'R2' => 'SGL'], $this->extractor->buildRoomTypeMap($rooms));
    }

    public function testBuildRoomTypeMapSkipsRowsMissingIdOrType(): void
    {
        $rooms = $this->extractor->extractRooms($this->xml(
            '<rooms><IdRoom>R1</IdRoom><Type>DBL</Type></rooms>'
            . '<rooms><IdRoom>R3</IdRoom></rooms>'       // no Type -> skipped
            . '<rooms><Type>FAM</Type></rooms>',          // no IdRoom -> skipped
        ));

        $this->assertSame(['R1' => 'DBL'], $this->extractor->buildRoomTypeMap($rooms));
    }

    // ── extractBoardTypes ────────────────────────────────────────────────────

    public function testBoardTypesFromIdBoardPreserveOrder(): void
    {
        $boards = $this->extractor->extractBoardTypes($this->xml(
            '<board><IdBoard>BB</IdBoard></board><board><IdBoard>HB</IdBoard></board>',
        ), '');

        $this->assertSame(['BB', 'HB'], $boards);
    }

    public function testBoardTypeFallsBackToElementText(): void
    {
        // No <IdBoard> child -> uses the <board> text content.
        $boards = $this->extractor->extractBoardTypes($this->xml('<board>FB</board>'), '');

        $this->assertSame(['FB'], $boards);
    }

    public function testBoardTypesDefaultListWhenNonePresent(): void
    {
        $boards = $this->extractor->extractBoardTypes($this->xml('<name>Hotel</name>'), '');

        $this->assertSame(['ALL INCL', 'AI', 'FB', 'HB', 'BB', 'RO'], $boards);
    }

    public function testMealPlanFloatsPreferredBoardToFront(): void
    {
        // HB is third in the source order; selecting meal plan HB moves it first.
        $boards = $this->extractor->extractBoardTypes($this->xml(
            '<board><IdBoard>BB</IdBoard></board>'
            . '<board><IdBoard>AI</IdBoard></board>'
            . '<board><IdBoard>HB</IdBoard></board>',
        ), 'HB');

        $this->assertSame(['HB', 'BB', 'AI'], $boards);
    }
}
