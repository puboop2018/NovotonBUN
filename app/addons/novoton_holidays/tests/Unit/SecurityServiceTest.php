<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\SecurityService;

/**
 * @covers \Tygh\Addons\NovotonHolidays\Services\SecurityService
 */
class SecurityServiceTest extends TestCase
{
    private SecurityService $sut;

    protected function setUp(): void
    {
        $this->sut = new SecurityService();
    }

    // ── validateBookingData ─────────────────────────────────────────────

    public function testValidBookingDataPasses(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'  => 'HTL123',
            'check_in'  => date('Y-m-d', strtotime('+7 days')),
            'check_out' => date('Y-m-d', strtotime('+14 days')),
            'adults'    => 2,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testMissingRequiredFieldsReturnsErrors(): void
    {
        $result = $this->sut->validateBookingData([]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        // Should flag all 4 required fields
        $this->assertGreaterThanOrEqual(4, count($result['errors']));
    }

    public function testInvalidHotelIdFormat(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'  => 'INVALID<script>ID',
            'check_in'  => date('Y-m-d', strtotime('+1 day')),
            'check_out' => date('Y-m-d', strtotime('+8 days')),
            'adults'    => 2,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Invalid hotel ID format', $result['errors']);
    }

    public function testPastCheckInDateRejected(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'  => 'HTL1',
            'check_in'  => '2020-01-01',
            'check_out' => '2020-01-08',
            'adults'    => 2,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Check-in date cannot be in the past', $result['errors']);
    }

    public function testCheckOutBeforeCheckInRejected(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'  => 'HTL1',
            'check_in'  => date('Y-m-d', strtotime('+10 days')),
            'check_out' => date('Y-m-d', strtotime('+5 days')),
            'adults'    => 2,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Check-out must be after check-in', $result['errors']);
    }

    public function testAdultsOutOfRangeRejected(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'  => 'HTL1',
            'check_in'  => date('Y-m-d', strtotime('+1 day')),
            'check_out' => date('Y-m-d', strtotime('+8 days')),
            'adults'    => 99,
        ]);

        $this->assertFalse($result['valid']);
    }

    public function testChildAgeOutOfRangeRejected(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'      => 'HTL1',
            'check_in'      => date('Y-m-d', strtotime('+1 day')),
            'check_out'     => date('Y-m-d', strtotime('+8 days')),
            'adults'        => 2,
            'children'      => 1,
            'children_ages' => '25',
        ]);

        $this->assertFalse($result['valid']);
    }

    public function testNegativePriceRejected(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'    => 'HTL1',
            'check_in'    => date('Y-m-d', strtotime('+1 day')),
            'check_out'   => date('Y-m-d', strtotime('+8 days')),
            'adults'      => 2,
            'total_price' => -500,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Invalid price value', $result['errors']);
    }

    public function testXssInGuestNameRejected(): void
    {
        $result = $this->sut->validateBookingData([
            'hotel_id'    => 'HTL1',
            'check_in'    => date('Y-m-d', strtotime('+1 day')),
            'check_out'   => date('Y-m-d', strtotime('+8 days')),
            'adults'      => 2,
            'holder_name' => '<script>alert("xss")</script>',
        ]);

        $this->assertFalse($result['valid']);
    }

    // ── validateSearchParams ────────────────────────────────────────────

    public function testSearchParamsSanitizesNights(): void
    {
        $result = $this->sut->validateSearchParams(['nights' => '999']);

        // MAX_NIGHTS = 30
        $this->assertEquals(30, $result['nights']);
    }

    public function testSearchParamsDefaultsApplied(): void
    {
        $result = $this->sut->validateSearchParams([]);

        $this->assertEquals(7, $result['nights']);   // DEFAULT_NIGHTS
        $this->assertEquals(2, $result['adults']);   // DEFAULT_ADULTS
        $this->assertEquals(0, $result['children']); // DEFAULT_CHILDREN
        $this->assertEquals(1, $result['rooms']);    // DEFAULT_ROOMS
    }

    public function testSearchParamsValidDatePassesThrough(): void
    {
        $result = $this->sut->validateSearchParams([
            'check_in' => '2026-08-15',
        ]);

        $this->assertEquals('2026-08-15', $result['check_in']);
    }

    public function testSearchParamsInvalidDateFiltered(): void
    {
        $result = $this->sut->validateSearchParams([
            'check_in' => 'not-a-date',
        ]);

        $this->assertArrayNotHasKey('check_in', $result);
    }

    public function testSearchParamsHotelIdSanitized(): void
    {
        $result = $this->sut->validateSearchParams([
            'hotel_id' => 'HTL-123_abc<script>',
        ]);

        // sanitizeHotelId strips non-[a-zA-Z0-9_-] chars; < and > removed but letters kept
        $this->assertEquals('HTL-123_abcscript', $result['hotel_id']);
    }

    public function testSearchParamsChildrenAgesOnlyDigitsAndCommas(): void
    {
        $result = $this->sut->validateSearchParams([
            'children_ages' => '3,7; DROP TABLE--',
        ]);

        $this->assertEquals('3,7', $result['children_ages']);
    }

    public function testSearchParamsFlexDaysCapped(): void
    {
        $result = $this->sut->validateSearchParams([
            'flex_days' => '100',
        ]);

        $this->assertEquals(30, $result['flex_days']);
    }

    // ── sanitizeGuestData ───────────────────────────────────────────────

    public function testSanitizeGuestDataStripsInvalidCharacters(): void
    {
        $result = $this->sut->sanitizeGuestData([
            [
                'first_name' => 'John123!@#',
                'last_name'  => "O'Brien",
                'type'       => 'adult',
                'age'        => 30,
                'room'       => 1,
            ],
        ]);

        $this->assertEquals('John', $result[0]['first_name']);
        $this->assertEquals("O'Brien", $result[0]['last_name']);
    }

    public function testSanitizeGuestDataInvalidTypeFallsBackToAdult(): void
    {
        $result = $this->sut->sanitizeGuestData([
            ['type' => 'hacker', 'age' => 25],
        ]);

        $this->assertEquals('adult', $result[0]['type']);
    }

    public function testSanitizeGuestDataAgeClamped(): void
    {
        $result = $this->sut->sanitizeGuestData([
            ['age' => 200],
        ]);

        $this->assertEquals(99, $result[0]['age']);
    }

    // ── encrypt / decrypt ───────────────────────────────────────────────

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'sensitive guest data';
        $encrypted = $this->sut->encrypt($plaintext);

        $this->assertNotEquals($plaintext, $encrypted);
        $this->assertEquals($plaintext, $this->sut->decrypt($encrypted));
    }

    public function testDecryptReturnsNullForGarbage(): void
    {
        $this->assertNull($this->sut->decrypt('not-valid-base64!!'));
    }

    public function testDecryptReturnsNullForTooShortData(): void
    {
        $this->assertNull($this->sut->decrypt(base64_encode('short')));
    }

    // ── escapeHtml ──────────────────────────────────────────────────────

    public function testEscapeHtml(): void
    {
        $this->assertEquals(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
            $this->sut->escapeHtml('<script>alert("xss")</script>')
        );
    }
}
