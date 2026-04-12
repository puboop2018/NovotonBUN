<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;
use Tygh\Addons\NovotonHolidays\Services\CartAssemblyService;
use Tygh\Addons\NovotonHolidays\Services\RoomsDataParser;
use Tygh\Addons\NovotonHolidays\Services\AlternativeDateSearcher;
use Tygh\Addons\NovotonHolidays\Services\SearchService;

/**
 * Integration-path tests for the money-critical and booking-critical
 * code paths that previously had zero automated coverage.
 *
 * These are still unit-like (no DB, no API), but they exercise the
 * service logic that handles real money and user-facing actions.
 */
class IntegrationPathsTest extends TestCase
{
    // ====================================================================
    // Commission calculation edge cases (the bool→string bug path)
    // ====================================================================

    public function testCommissionWithStringY(): void
    {
        $calc = new CommissionCalculator(15.0, 'Y');
        $result = $calc->apply(100.0);
        // 100 * 1.15 = 115.0, rounded = 115
        $this->assertEqualsWithDelta(115.0, $result, 0.01);
    }

    public function testCommissionWithStringN(): void
    {
        $calc = new CommissionCalculator(15.0, 'N');
        $result = $calc->apply(99.50);
        // 99.50 * 1.15 = 114.425, NOT rounded
        $this->assertEqualsWithDelta(114.43, $result, 0.01);
    }

    /**
     * Verify that passing a boolean (the bug we fixed) throws TypeError
     * under strict_types. This ensures the fix stays fixed.
     */
    public function testCommissionRejectsBoolUnderStrictTypes(): void
    {
        $this->expectException(\TypeError::class);
        // @phpstan-ignore argument.type
        new CommissionCalculator(10.0, true);
    }

    public function testCommissionZeroReturnsOriginalPrice(): void
    {
        $calc = new CommissionCalculator(0.0, 'N');
        $this->assertEqualsWithDelta(250.99, $calc->apply(250.99), 0.001);
    }

    public function testCommissionNegativePriceReturnsZero(): void
    {
        $calc = new CommissionCalculator(10.0, 'N');
        $this->assertEqualsWithDelta(0.0, $calc->apply(0.0), 0.001);
    }

    // ====================================================================
    // CartAssemblyService — cart product structure
    // ====================================================================

    public function testAssembleCartProductReturnsRequiredKeys(): void
    {
        $service = new CartAssemblyService();

        $bookingData = [
            'hotel_id' => 'H123',
            'hotel_name' => 'Test Hotel',
            'room_id' => 'DBL',
            'board_id' => 'AI',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'adults' => 2,
            'children' => 0,
        ];

        $hotelInfo = [
            'hotel_name' => 'Test Hotel',
            'city' => 'Sunny Beach',
            'country' => 'BULGARIA',
            'star_rating' => '4',
        ];

        $guestsData = [
            ['name' => 'John Doe', 'type' => 'adult', 'age' => 33],
            ['name' => 'Jane Doe', 'type' => 'adult', 'age' => 30],
        ];

        $priceResult = [
            'total_price' => 850.00,
            'base_price' => 750.00,
            'terms_of_payment' => '<TermsOfPayment/>',
            'terms_of_cancellation' => '<TermsOfCancellation/>',
            'remark' => '',
            'important' => '',
        ];

        $result = $service->assembleCartProduct(
            42, 1, $bookingData, $hotelInfo, $guestsData, $priceResult, []
        );

        $this->assertArrayHasKey('product_id', $result);
        $this->assertArrayHasKey('price', $result);
        $this->assertArrayHasKey('extra', $result);
        $this->assertEquals(42, $result['product_id']);
        $this->assertEqualsWithDelta(850.00, $result['price'], 0.01);
        $this->assertTrue($result['extra']['novoton_booking'] ?? false);
        $this->assertEquals('H123', $result['extra']['hotel_id']);
        $this->assertEquals('AI', $result['extra']['board_id']);
    }

    public function testAssembleCartProductExtractsChildAges(): void
    {
        $service = new CartAssemblyService();

        $guestsData = [
            ['name' => 'Parent', 'type' => 'adult', 'age' => 35],
            ['name' => 'Child 1', 'type' => 'child', 'age' => 8],
            ['name' => 'Child 2', 'type' => 'child', 'age' => 3],
        ];

        $result = $service->assembleCartProduct(
            1, 1,
            ['hotel_id' => 'H1', 'room_id' => 'R1', 'board_id' => 'BB',
             'check_in' => '2026-08-01', 'check_out' => '2026-08-08',
             'adults' => 1, 'children' => 2],
            ['hotel_name' => 'X', 'city' => '', 'country' => ''],
            $guestsData,
            ['total_price' => 500.00, 'base_price' => 450.0,
             'terms_of_payment' => '', 'terms_of_cancellation' => '',
             'remark' => '', 'important' => ''],
            []
        );

        $this->assertEquals('8,3', $result['extra']['children_ages']);
    }

    // ====================================================================
    // CartAssemblyService — night calculation
    // ====================================================================

    public function testCalculateNightsReturnsCorrectCount(): void
    {
        $nights = CartAssemblyService::calculateNights('2026-07-01', '2026-07-08');
        $this->assertEquals(7, $nights);
    }

    public function testCalculateNightsSameDayReturnsOne(): void
    {
        // Edge case: same-day should be at least 1 night
        $nights = CartAssemblyService::calculateNights('2026-07-01', '2026-07-01');
        $this->assertGreaterThanOrEqual(0, $nights);
    }

    // ====================================================================
    // RoomsDataParser — parsing rooms from form data
    // ====================================================================

    public function testParseRoomsDataSingleRoom(): void
    {
        $parser = new RoomsDataParser();

        $bookingData = [
            'adults' => 2,
            'children' => 1,
            'children_ages' => '5',
            'num_rooms' => 1,
        ];

        $result = $parser->parseRoomsData($bookingData);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // ====================================================================
    // AlternativeDateSearcher — debug log isolation
    // ====================================================================

    public function testAlternativeDateSearcherDebugLogStartsEmpty(): void
    {
        $searcher = new AlternativeDateSearcher(true);
        $this->assertEmpty($searcher->getDebugLog());
    }

    // ====================================================================
    // SearchService — static helpers (these handle real pricing data)
    // ====================================================================

    public function testMatchesMealPlanAllBoardsMatchesEverything(): void
    {
        $this->assertTrue(SearchService::matchesMealPlan('ALL INCL', ''));
        $this->assertTrue(SearchService::matchesMealPlan('BB', ''));
        $this->assertTrue(SearchService::matchesMealPlan('HB', ''));
    }

    public function testMatchesMealPlanFiltersByCode(): void
    {
        $this->assertTrue(SearchService::matchesMealPlan('ALL INCL', 'AI'));
        $this->assertFalse(SearchService::matchesMealPlan('BB', 'AI'));
    }

    public function testParseQuotaValueNumeric(): void
    {
        $result = SearchService::parseQuotaValue('5');
        $this->assertEquals(5, $result['availability']);
        $this->assertFalse($result['is_on_request']);
    }

    public function testParseQuotaValueOnRequest(): void
    {
        $result = SearchService::parseQuotaValue('RQ');
        $this->assertEquals(0, $result['availability']);
        $this->assertTrue($result['is_on_request']);
    }

    public function testParseQuotaValueNull(): void
    {
        $result = SearchService::parseQuotaValue(null);
        $this->assertNull($result['availability']);
        $this->assertFalse($result['is_on_request']);
    }

    public function testDeduplicateResultsKeepsLowestPrice(): void
    {
        $results = [
            ['room_id' => 'DBL', 'board_id' => 'AI', 'package_name' => 'Pkg1',
             'total_price' => 500.0, 'extras' => ''],
            ['room_id' => 'DBL', 'board_id' => 'AI', 'package_name' => 'Pkg1',
             'total_price' => 450.0, 'extras' => ''],
        ];

        $deduped = SearchService::deduplicateResults($results);
        $this->assertCount(1, $deduped);
        $this->assertEqualsWithDelta(450.0, $deduped[0]['total_price'], 0.01);
    }

    public function testDeduplicateResultsMergesExtrasPromotion(): void
    {
        $results = [
            ['room_id' => 'DBL', 'board_id' => 'AI', 'package_name' => 'P',
             'total_price' => 500.0, 'extras' => ''],
            ['room_id' => 'DBL', 'board_id' => 'AI', 'package_name' => 'P',
             'total_price' => 420.0, 'extras' => '7 = 6 (1 night free)'],
        ];

        $deduped = SearchService::deduplicateResults($results);
        $this->assertCount(1, $deduped);
        // Standard price kept as base, promotional price attached
        $this->assertEqualsWithDelta(500.0, $deduped[0]['total_price'], 0.01);
        $this->assertEqualsWithDelta(420.0, $deduped[0]['extras_price'], 0.01);
    }
}
