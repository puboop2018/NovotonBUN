<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\GuestDataService;

/**
 * Characterization coverage for GuestDataService's pure methods, added
 * alongside the boundary-typing paydown (mixed reads now coerced through
 * TypeCoerce). Pins api-name building, room grouping, validation, the
 * fill-empty merge, DOB parsing, holder resolution and the guest list.
 */
#[CoversClass(GuestDataService::class)]
class GuestDataServiceTest extends TestCase
{
    private GuestDataService $service;

    protected function setUp(): void
    {
        $this->service = new GuestDataService();
    }

    public function testFormatApiName(): void
    {
        $this->assertSame('John Doe', $this->service->formatApiName(['api_name' => 'John Doe']));
        $this->assertSame('John Doe', $this->service->formatApiName(['first_name' => 'John', 'last_name' => 'Doe']));
        $this->assertSame('Solo', $this->service->formatApiName(['name' => 'Solo']));
        $this->assertSame('Guest', $this->service->formatApiName([]));
    }

    public function testBuildGuestList(): void
    {
        $list = $this->service->buildGuestList([
            'g1' => ['name' => 'Alpha'],
            'g2' => ['api_name' => 'Beta'],
            'g3' => 'not-an-array',
        ]);

        $this->assertSame('Alpha, Beta', $list);
    }

    public function testGetHolderNamePrefersHolderFlag(): void
    {
        $name = $this->service->getHolderName([
            'g1' => ['name' => 'Other'],
            'g2' => ['name' => 'Holder', 'is_holder' => 1],
        ]);

        $this->assertSame('Holder', $name);
    }

    public function testGetHolderNameFallsBackToBookingData(): void
    {
        $this->assertSame('Booking Holder', $this->service->getHolderName([], ['holder_name' => 'Booking Holder']));
    }

    public function testGetGuestsByRoomGroupsByRoomNumber(): void
    {
        $byRoom = $this->service->getGuestsByRoom([
            'room1_adult_1' => ['name' => 'A'],
            'room1_adult_2' => ['name' => 'B'],
            'room2_adult_1' => ['name' => 'C'],
        ]);

        $this->assertSame([1, 2], array_keys($byRoom));
        $this->assertCount(2, $byRoom[1]);
        $this->assertCount(1, $byRoom[2]);
    }

    public function testValidateCountsAndFlagsShortNames(): void
    {
        $ok = $this->service->validate(['room1_adult_1' => ['name' => 'John', 'type' => 'adult']]);
        $this->assertTrue($ok['valid']);
        $this->assertSame(1, $ok['adults']);
        $this->assertSame(0, $ok['children']);

        $bad = $this->service->validate(['g' => ['name' => 'X', 'type' => 'adult']]);
        $this->assertFalse($bad['valid']);
        $this->assertNotEmpty($bad['errors']);
    }

    public function testMergeFillsEmptyFieldsFromLaterSources(): void
    {
        $merged = $this->service->merge(
            ['g' => ['name' => 'John', 'first_name' => '']],
            ['g' => ['name' => 'John', 'first_name' => 'Johnny']],
        );

        $this->assertSame('Johnny', $merged['g']['first_name']);
    }

    public function testParseDob(): void
    {
        $this->assertSame('1990-05-15', GuestDataService::parseDob(['dob' => '15/05/1990']));
        $this->assertSame('1990-05-15', GuestDataService::parseDob(['dob' => '1990-05-15']));
        $this->assertSame('2000-01-02', GuestDataService::parseDob(['dob_day' => '2', 'dob_month' => '1', 'dob_year' => '2000']));
        $this->assertSame('', GuestDataService::parseDob(['dob' => 'not-a-date']));
    }

    public function testParseGuestsDataEmpty(): void
    {
        $this->assertSame([], $this->service->parseGuestsData([]));
    }
}
