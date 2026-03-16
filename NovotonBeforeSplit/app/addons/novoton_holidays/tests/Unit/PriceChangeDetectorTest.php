<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\PriceChangeDetector;
use Tygh\Tygh;

/**
 * Tests for the "No Surprises" PriceChangeDetector service.
 *
 * Covers:
 *   - Price increase detection (orange badge)
 *   - Price decrease detection (green badge)
 *   - Price Tolerance (silent updates for changes < threshold)
 *   - Boundary conditions (exactly at threshold, zero price, etc.)
 *   - Session alert storage lifecycle (store → peek → consume)
 *   - Custom tolerance configuration
 *
 * @covers \Tygh\Addons\TravelCore\Services\PriceChangeDetector
 */
class PriceChangeDetectorTest extends TestCase
{
    private PriceChangeDetector $sut;

    protected function setUp(): void
    {
        // Ensure session is clean
        if (!isset(Tygh::$app['session'])) {
            Tygh::$app['session'] = [];
        }
        unset(Tygh::$app['session']['travel_price_change_alerts']);

        // Default 1% tolerance via constructor
        $this->sut = new PriceChangeDetector(1.0);
    }

    protected function tearDown(): void
    {
        unset(Tygh::$app['session']['travel_price_change_alerts']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRICE INCREASE (orange badge, "Old vs New")
    // ═══════════════════════════════════════════════════════════════

    public function testSignificantIncreaseReturnsWarningBadge(): void
    {
        // Customer saw $100, API now says $110 (+10%)
        $result = $this->sut->analyse(100.0, 110.0, 'EUR', 'add_to_cart');

        $this->assertTrue($result['significant']);
        $this->assertSame('increase', $result['direction']);
        $this->assertSame('warning', $result['badge_type']);
        $this->assertEqualsWithDelta(10.0, $result['difference'], 0.01);
        $this->assertEqualsWithDelta(10.0, $result['percent'], 0.01);
    }

    public function testIncreasePreservesOldAndNewPrice(): void
    {
        $result = $this->sut->analyse(450.0, 480.0, 'RON', 'checkout');

        $this->assertEqualsWithDelta(450.0, $result['old_price'], 0.01);
        $this->assertEqualsWithDelta(480.0, $result['new_price'], 0.01);
        $this->assertSame('RON', $result['currency']);
    }

    public function testIncreasePreservesBookingMeta(): void
    {
        $meta = ['hotel_name' => 'Hotel Paradise', 'hotel_id' => 'HP001'];
        $result = $this->sut->analyse(100.0, 120.0, 'EUR', 'add_to_cart', $meta);

        $this->assertSame('Hotel Paradise', $result['booking_meta']['hotel_name']);
        $this->assertSame('HP001', $result['booking_meta']['hotel_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRICE DECREASE (green badge, "Price Dropped!")
    // ═══════════════════════════════════════════════════════════════

    public function testSignificantDecreaseReturnsSuccessBadge(): void
    {
        // Customer saw $500, API now says $450 (-10%)
        $result = $this->sut->analyse(500.0, 450.0, 'RON', 'checkout');

        $this->assertTrue($result['significant']);
        $this->assertSame('decrease', $result['direction']);
        $this->assertSame('success', $result['badge_type']);
        $this->assertEqualsWithDelta(-50.0, $result['difference'], 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRICE TOLERANCE — silent updates below threshold
    // ═══════════════════════════════════════════════════════════════

    public function testBelowToleranceIsSilent(): void
    {
        // 450 → 452 Lei = 0.44% change, below 1% tolerance
        $result = $this->sut->analyse(450.0, 452.0, 'RON');

        $this->assertFalse($result['significant']);
        $this->assertSame('increase', $result['direction']); // direction still detected
        $this->assertSame('none', $result['badge_type']);     // but no badge shown
    }

    public function testSmallDecreaseIsSilent(): void
    {
        // 450 → 448.50 = 0.33%, below tolerance
        $result = $this->sut->analyse(450.0, 448.50, 'RON');

        $this->assertFalse($result['significant']);
        $this->assertSame('decrease', $result['direction']);
        $this->assertSame('none', $result['badge_type']);
    }

    public function testExactSamePriceIsNone(): void
    {
        $result = $this->sut->analyse(300.0, 300.0, 'EUR');

        $this->assertFalse($result['significant']);
        $this->assertSame('none', $result['direction']);
        $this->assertEqualsWithDelta(0.0, $result['difference'], 0.001);
        $this->assertSame('none', $result['badge_type']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  BOUNDARY CONDITIONS
    // ═══════════════════════════════════════════════════════════════

    public function testExactlyAtToleranceBoundaryIsSignificant(): void
    {
        // 1000 → 1010 = exactly 1.0% — should trigger
        $result = $this->sut->analyse(1000.0, 1010.0, 'EUR');

        $this->assertTrue($result['significant']);
        $this->assertEqualsWithDelta(1.0, $result['percent'], 0.01);
    }

    public function testJustBelowToleranceBoundaryIsSilent(): void
    {
        // 1000 → 1009 = 0.9% — should be silent
        $result = $this->sut->analyse(1000.0, 1009.0, 'EUR');

        $this->assertFalse($result['significant']);
    }

    public function testZeroOldPriceIsNotSignificant(): void
    {
        // Edge case: old price is 0 (shouldn't divide by zero)
        $this->assertFalse($this->sut->isSignificant(0.0, 100.0));
    }

    public function testZeroNewPriceWithOldPriceIsSignificant(): void
    {
        // Edge case: new price drops to 0 (100% decrease)
        // abs(0 - 100) / 100 * 100 = 100% — well above tolerance
        $result = $this->sut->analyse(100.0, 0.0, 'EUR');

        // While the math gives 100%, difference is > 0.01 and percent >= 1
        $this->assertTrue($result['significant']);
        $this->assertSame('decrease', $result['direction']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  isSignificant() helper
    // ═══════════════════════════════════════════════════════════════

    public function testIsSignificantHelper(): void
    {
        $this->assertTrue($this->sut->isSignificant(100.0, 110.0));   // +10%
        $this->assertTrue($this->sut->isSignificant(100.0, 90.0));    // -10%
        $this->assertFalse($this->sut->isSignificant(100.0, 100.5));  // +0.5%
        $this->assertFalse($this->sut->isSignificant(0.0, 100.0));    // zero base
    }

    // ═══════════════════════════════════════════════════════════════
    //  CUSTOM TOLERANCE (admin sets 5%)
    // ═══════════════════════════════════════════════════════════════

    public function testCustomToleranceSilencesSmallerChanges(): void
    {
        // 5% tolerance via constructor
        $detector = new PriceChangeDetector(5.0);

        // 3% change should be silent at 5% tolerance
        $result = $detector->analyse(100.0, 103.0, 'EUR');
        $this->assertFalse($result['significant']);

        // 6% change should trigger at 5% tolerance
        $result = $detector->analyse(100.0, 106.0, 'EUR');
        $this->assertTrue($result['significant']);
    }

    public function testZeroToleranceTriggersOnAnyChange(): void
    {
        // Zero tolerance — any change triggers
        $detector = new PriceChangeDetector(0.01);

        // Even $0.02 change on $100 = 0.02% should trigger
        $result = $detector->analyse(100.0, 100.02, 'EUR');
        $this->assertTrue($result['significant']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTEXT FIELD
    // ═══════════════════════════════════════════════════════════════

    public function testContextIsPreservedInResult(): void
    {
        $result = $this->sut->analyse(100.0, 120.0, 'EUR', 'checkout');
        $this->assertSame('checkout', $result['context']);

        $result = $this->sut->analyse(100.0, 120.0, 'EUR', 'add_to_cart');
        $this->assertSame('add_to_cart', $result['context']);

        $result = $this->sut->analyse(100.0, 120.0, 'EUR', 'recalculate');
        $this->assertSame('recalculate', $result['context']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  SESSION ALERT STORAGE
    // ═══════════════════════════════════════════════════════════════

    public function testStoreAndPeekAlerts(): void
    {
        $alert = $this->sut->analyse(100.0, 120.0, 'EUR', 'add_to_cart');
        $this->sut->storeAlert($alert, 'cart_abc');

        $peeked = $this->sut->peekAlerts();
        $this->assertCount(1, $peeked);
        $this->assertArrayHasKey('cart_abc', $peeked);
        $this->assertTrue($peeked['cart_abc']['significant']);
    }

    public function testPeekDoesNotClearAlerts(): void
    {
        $alert = $this->sut->analyse(100.0, 120.0, 'EUR');
        $this->sut->storeAlert($alert, 'cart_1');

        $this->sut->peekAlerts();
        $this->sut->peekAlerts();

        // Still there after two peeks
        $this->assertCount(1, $this->sut->peekAlerts());
    }

    public function testConsumeReturnsAndClearsAlerts(): void
    {
        $alert1 = $this->sut->analyse(100.0, 120.0, 'EUR');
        $alert2 = $this->sut->analyse(500.0, 480.0, 'RON');
        $this->sut->storeAlert($alert1, 'cart_a');
        $this->sut->storeAlert($alert2, 'cart_b');

        $consumed = $this->sut->consumeAlerts();
        $this->assertCount(2, $consumed);
        $this->assertArrayHasKey('cart_a', $consumed);
        $this->assertArrayHasKey('cart_b', $consumed);

        // After consume, nothing left
        $this->assertCount(0, $this->sut->peekAlerts());
    }

    public function testStoreWithoutCartIdUsesAutoKey(): void
    {
        $alert = $this->sut->analyse(100.0, 120.0, 'EUR');
        $this->sut->storeAlert($alert); // no cart ID

        $peeked = $this->sut->peekAlerts();
        $this->assertCount(1, $peeked);
        // Key should be 'global_0'
        $this->assertArrayHasKey('global_0', $peeked);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRICE FATIGUE — repeated small changes stay silent
    // ═══════════════════════════════════════════════════════════════

    public function testRepeatedSmallChangesStaySilent(): void
    {
        // Simulates price bouncing: 100→100.30→100.60→100.90
        // Each step < 1%, all should be silent
        $this->assertFalse($this->sut->isSignificant(100.0, 100.30));
        $this->assertFalse($this->sut->isSignificant(100.30, 100.60));
        $this->assertFalse($this->sut->isSignificant(100.60, 100.90));
    }

    // ═══════════════════════════════════════════════════════════════
    //  REAL-WORLD SCENARIO
    // ═══════════════════════════════════════════════════════════════

    public function testRealWorldCheckoutPriceIncrease(): void
    {
        // Standard Room: 450 Lei → 480 Lei at checkout
        // Expected: ~~450 Lei~~ 480 Lei, orange badge
        $result = $this->sut->analyse(450.0, 480.0, 'RON', 'checkout', [
            'hotel_name' => 'Hotel Paradise',
            'hotel_id'   => 'HP001',
            'room_id'    => 'DBL 2+1)',
        ]);

        $this->assertTrue($result['significant']);
        $this->assertSame('increase', $result['direction']);
        $this->assertEqualsWithDelta(30.0, $result['difference'], 0.01);
        $this->assertEqualsWithDelta(6.67, $result['percent'], 0.01);
        $this->assertSame('warning', $result['badge_type']);
        $this->assertSame('checkout', $result['context']);
        $this->assertSame('Hotel Paradise', $result['booking_meta']['hotel_name']);
    }

    public function testRealWorldAddToCartPriceDecrease(): void
    {
        // Family Room: 1200 Lei → 1080 Lei at add-to-cart (-10%)
        // Expected: green "Price Dropped!" badge
        $result = $this->sut->analyse(1200.0, 1080.0, 'RON', 'add_to_cart', [
            'hotel_name' => 'Sunny Beach Resort',
        ]);

        $this->assertTrue($result['significant']);
        $this->assertSame('decrease', $result['direction']);
        $this->assertSame('success', $result['badge_type']);
        $this->assertEqualsWithDelta(-120.0, $result['difference'], 0.01);
    }

    public function testTimestampIsPresent(): void
    {
        $before = time();
        $result = $this->sut->analyse(100.0, 110.0, 'EUR');
        $after  = time();

        $this->assertArrayHasKey('timestamp', $result);
        $this->assertGreaterThanOrEqual($before, $result['timestamp']);
        $this->assertLessThanOrEqual($after, $result['timestamp']);
    }
}
