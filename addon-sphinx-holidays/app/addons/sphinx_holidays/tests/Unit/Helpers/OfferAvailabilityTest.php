<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;

#[CoversClass(OfferAvailability::class)]
class OfferAvailabilityTest extends TestCase
{
    public function testIsImmediateTrueForImmediateConfirmation(): void
    {
        $this->assertTrue(OfferAvailability::isImmediate(['confirmation' => 'immediate']));
    }

    public function testIsImmediateIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertTrue(OfferAvailability::isImmediate(['confirmation' => '  IMMEDIATE ']));
        $this->assertTrue(OfferAvailability::isImmediate(['confirmation' => 'Immediate']));
    }

    public function testIsImmediateFalseForOnRequestOrMissing(): void
    {
        $this->assertFalse(OfferAvailability::isImmediate(['confirmation' => 'on_request']));
        $this->assertFalse(OfferAvailability::isImmediate(['confirmation' => '']));
        $this->assertFalse(OfferAvailability::isImmediate([]));
    }

    public function testFilterImmediateKeepsOnlyImmediateAndReindexes(): void
    {
        $offers = [
            ['offer_id' => 'a', 'confirmation' => 'immediate'],
            ['offer_id' => 'b', 'confirmation' => 'on_request'],
            ['offer_id' => 'c'], // missing field → dropped
            ['offer_id' => 'd', 'confirmation' => 'immediate'],
        ];

        $filtered = OfferAvailability::filterImmediate($offers);

        $this->assertCount(2, $filtered);
        $this->assertSame('a', $filtered[0]['offer_id']);
        $this->assertSame('d', $filtered[1]['offer_id']);
        // Preserves all original keys of the kept offers.
        $this->assertSame('immediate', $filtered[0]['confirmation']);
    }

    public function testCollectImmediateHotelIdsDedupesAndUsesIdFallback(): void
    {
        $offers = [
            ['hotel_id' => '100', 'confirmation' => 'immediate'],
            ['hotel_id' => '100', 'confirmation' => 'immediate'], // duplicate hotel
            ['hotel_id' => '200', 'confirmation' => 'on_request'], // not immediate → excluded
            ['id' => 300, 'confirmation' => 'immediate'], // id fallback, numeric
        ];

        $ids = OfferAvailability::collectImmediateHotelIds($offers);

        $this->assertSame(['100' => true, '300' => true], $ids);
        $this->assertArrayNotHasKey('200', $ids);
    }

    public function testCollectImmediateHotelIdsSkipsNonArrayAndEmptyIds(): void
    {
        $offers = [
            'not-an-array',
            ['confirmation' => 'immediate'], // no hotel id → skipped
            ['hotel_id' => '', 'confirmation' => 'immediate'], // empty id → skipped
            ['hotel_id' => '500', 'confirmation' => 'immediate'],
        ];

        $this->assertSame(['500' => true], OfferAvailability::collectImmediateHotelIds($offers));
    }

    // ── extractPrice (shared price chain) ────────────────────────────────────

    public function testExtractPriceChain(): void
    {
        $this->assertSame(100.0, OfferAvailability::extractPrice(['price' => 100]));
        $this->assertSame(987.0, OfferAvailability::extractPrice(['selling_price' => 987]));
        $this->assertSame(842.5, OfferAvailability::extractPrice(['pricing' => ['selling_price' => 842.5]]));
        // flat `price` wins over the other sources
        $this->assertSame(1.0, OfferAvailability::extractPrice(['price' => 1, 'selling_price' => 2]));
        $this->assertSame(0.0, OfferAvailability::extractPrice(['price' => 'n/a']));
        $this->assertSame(0.0, OfferAvailability::extractPrice([]));
    }

    // ── isVerifiedAvailable (tolerant verify-response semantics) ─────────────

    /**
     * The live verify response: the offer itself, NO `available` key. The old
     * `available ?? false` gate rejected every one of these ("offer no longer
     * available" on each Book-now click).
     */
    public function testVerifiedAvailableForLiveOfferShape(): void
    {
        $liveOffer = [
            'hotel_id' => 61992,
            'offer_id' => 'e302a15f-ad04-4d57-b7fd-90255cf2393f',
            'confirmation' => 'immediate',
            'selling_price' => 987,
            'currency' => 'EUR',
            'meal_type' => 'ULTRA ALL INCLUSIVE',
        ];

        $this->assertTrue(OfferAvailability::isVerifiedAvailable($liveOffer, true));
        $this->assertTrue(OfferAvailability::isVerifiedAvailable($liveOffer, false));
    }

    public function testVerifiedAvailableHonorsExplicitAvailableKey(): void
    {
        $this->assertTrue(OfferAvailability::isVerifiedAvailable(['available' => true], true));
        $this->assertTrue(OfferAvailability::isVerifiedAvailable(['available' => 'yes'], true));
        $this->assertTrue(OfferAvailability::isVerifiedAvailable(['available' => '1'], true));
        // Explicit false wins even when the offer otherwise looks bookable
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(
            ['available' => false, 'confirmation' => 'immediate', 'price' => 100],
            false,
        ));
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(['available' => 'false'], false));
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(['available' => ''], false));
    }

    public function testVerifiedAvailableConfirmationRespectsImmediateRequirement(): void
    {
        $onRequest = ['confirmation' => 'on_request', 'selling_price' => 500];

        $this->assertFalse(OfferAvailability::isVerifiedAvailable($onRequest, true));
        $this->assertTrue(OfferAvailability::isVerifiedAvailable($onRequest, false));
    }

    public function testVerifiedAvailableFallsBackToPrice(): void
    {
        $this->assertTrue(OfferAvailability::isVerifiedAvailable(['selling_price' => 250], true));
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(['room_name' => 'DBL'], true));
    }

    public function testVerifiedAvailableRejectsEmptyResponses(): void
    {
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(null, false));
        $this->assertFalse(OfferAvailability::isVerifiedAvailable([], false));
    }

    /**
     * Regression (devx: every Rezervă click → "offer no longer available" on
     * every date): the live verify endpoint wraps the offer in an envelope
     * ({success, data: {...}} — same convention as the results endpoint's
     * {status, results|data}). The gate used to inspect only the TOP level,
     * found no offer signals there, and rejected everything.
     */
    public function testUnwrapOfferDescendsThroughKnownEnvelopes(): void
    {
        $offer = ['confirmation' => 'immediate', 'selling_price' => 987];

        // Flat responses pass through unchanged
        $this->assertSame($offer, OfferAvailability::unwrapOffer($offer));

        // Single-level envelopes
        $this->assertSame($offer, OfferAvailability::unwrapOffer(['success' => true, 'data' => $offer]));
        $this->assertSame($offer, OfferAvailability::unwrapOffer(['offer' => $offer]));
        $this->assertSame($offer, OfferAvailability::unwrapOffer(['result' => $offer]));

        // Two-level nesting ({result: {data: {...}}})
        $this->assertSame($offer, OfferAvailability::unwrapOffer(['result' => ['data' => $offer]]));

        // Empty → null
        $this->assertNull(OfferAvailability::unwrapOffer(null));
        $this->assertNull(OfferAvailability::unwrapOffer([]));
    }

    public function testVerifiedAvailableAcceptsEnvelopedOffers(): void
    {
        $offer = ['confirmation' => 'immediate', 'selling_price' => 987];

        $this->assertTrue(OfferAvailability::isVerifiedAvailable(['success' => true, 'data' => $offer], true));
        $this->assertTrue(OfferAvailability::isVerifiedAvailable(['offer' => $offer], true));

        // Enveloped on-request offer still honors the immediate requirement
        $onRequest = ['data' => ['confirmation' => 'on_request', 'selling_price' => 500]];
        $this->assertFalse(OfferAvailability::isVerifiedAvailable($onRequest, true));
        $this->assertTrue(OfferAvailability::isVerifiedAvailable($onRequest, false));

        // Enveloped explicit available=false is still a rejection
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(['data' => ['available' => false]], false));

        // An envelope carrying NO offer payload (error responses) is rejected
        $this->assertFalse(OfferAvailability::isVerifiedAvailable(['success' => false, 'message' => 'expired'], false));
    }

    public function testExtractPriceReadsThroughEnvelopes(): void
    {
        $this->assertSame(987.0, OfferAvailability::extractPrice(['data' => ['selling_price' => 987]]));
        $this->assertSame(750.5, OfferAvailability::extractPrice(['offer' => ['pricing' => ['selling_price' => '750.50']]]));
        // Flat offers unchanged
        $this->assertSame(250.0, OfferAvailability::extractPrice(['selling_price' => 250]));
        $this->assertSame(0.0, OfferAvailability::extractPrice(['success' => false]));
    }

    public function testExtractPriceFromDocumentedFullPricingFixture(): void
    {
        // The documented verify shape with the complete pricing breakdown
        // (marketing/discount/selling/commission/supplier/taxes/fees):
        // extractPrice must read pricing.selling_price through the envelope.
        $raw = (string) file_get_contents(
            dirname(__DIR__, 2) . '/Fixtures/api/hotels_verify.full_pricing.json',
        );
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        $this->assertSame(4000.0, OfferAvailability::extractPrice($decoded));
    }
}
