<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\ResponseDecoder;
use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;

/**
 * Contract tests for the API decode boundary, driven by recorded JSON
 * fixtures (tests/Fixtures/api) that pin the CURRENT live response shapes.
 *
 * Three production incidents came from consumers re-deriving API shapes ad
 * hoc (offers-collection drift, verify field drift, verify ENVELOPE drift —
 * "every Rezervă rejected"). Every fixture variant of an endpoint must
 * decode to the same canonical payload, so the next shape drift turns into a
 * red test here instead of a storefront outage. When the API changes shape:
 * record the new response as a fixture variant, make the decoder absorb it,
 * keep the old variants green.
 */
final class ResponseDecoderTest extends TestCase
{
    private ResponseDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ResponseDecoder();
    }

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/api/' . $name . '.json';
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, "fixture {$name} must be a JSON object");

        return $decoded;
    }

    // ── verify (offer) ────────────────────────────────────────────────────

    public function testHotelVerifyVariantsDecodeToTheSameBookableOffer(): void
    {
        $enveloped = $this->decoder->offer(self::fixture('hotels_verify.enveloped'));
        $bare = $this->decoder->offer(self::fixture('hotels_verify.bare_offer'));

        self::assertIsArray($enveloped);
        self::assertIsArray($bare);

        // Both variants surface the same offer identity + signals
        self::assertSame('e302a15f-ad04-4d57-b7fd-90255cf2393f', $enveloped['offer_id']);
        self::assertSame($bare['offer_id'], $enveloped['offer_id']);
        self::assertSame('immediate', $enveloped['confirmation']);
        self::assertSame(987.5, OfferAvailability::extractPrice($enveloped));
        self::assertSame(987.5, OfferAvailability::extractPrice($bare));

        // The gate accepts both — the exact regression of the envelope incident
        self::assertTrue(OfferAvailability::isVerifiedAvailable($enveloped, true));
        self::assertTrue(OfferAvailability::isVerifiedAvailable($bare, true));

        // The enveloped variant surfaces the terms the booking form displays
        self::assertNotEmpty($enveloped['payment_terms']);
    }

    public function testHotelVerifyLegacyAvailableBoolVariantStillDecodes(): void
    {
        $offer = $this->decoder->offer(self::fixture('hotels_verify.available_bool'));

        self::assertIsArray($offer);
        self::assertTrue(OfferAvailability::isVerifiedAvailable($offer, true));
        self::assertSame(987.5, OfferAvailability::extractPrice($offer));
    }

    public function testHotelVerifyErrorEnvelopeIsRejectedByTheGate(): void
    {
        $offer = $this->decoder->offer(self::fixture('hotels_verify.error'));

        // Unknown shape is handed back for diagnostics, but the gate rejects it
        self::assertFalse(OfferAvailability::isVerifiedAvailable($offer, false));
    }

    public function testPackageVerifyVariantsDecodeToThePricedPayload(): void
    {
        $enveloped = $this->decoder->offer(self::fixture('packages_verify.enveloped'));
        $bare = $this->decoder->offer(self::fixture('packages_verify.bare'));

        self::assertIsArray($enveloped);
        self::assertIsArray($bare);

        // The package booking form requires pricing on the payload
        self::assertNotEmpty($enveloped['pricing']);
        self::assertNotEmpty($bare['pricing']);
        self::assertSame(1450.0, OfferAvailability::extractPrice($enveloped));
        self::assertSame($bare['offer_id'], $enveloped['offer_id']);

        // Nested detail blocks survive the unwrap (the form renders these)
        self::assertIsArray($enveloped['hotel']);
        self::assertIsArray($enveloped['flight']);
    }

    public function testOfferIsIdempotentOnAlreadyDecodedPayloads(): void
    {
        $once = $this->decoder->offer(self::fixture('hotels_verify.enveloped'));
        $twice = $this->decoder->offer($once);

        self::assertSame($once, $twice);
    }

    // ── search / poll (canonical {status, results, cursor}) ──────────────

    public function testSearchInitiationCanonicalizesSearchIdIntoCursor(): void
    {
        $search = $this->decoder->search(self::fixture('hotels_search'));

        self::assertIsArray($search);
        self::assertSame('pending', $search['status']);
        self::assertSame([], $search['results']);
        self::assertSame('b7f2b9a0-1111-2222-3333-444455556666', $search['cursor']);
        // Original keys preserved
        self::assertArrayHasKey('search_id', $search);
    }

    public function testResultsVariantsCanonicalizeIdentically(): void
    {
        $viaResults = $this->decoder->search(self::fixture('hotels_results.results'));
        $viaData = $this->decoder->search(self::fixture('hotels_results.data'));

        self::assertIsArray($viaResults);
        self::assertIsArray($viaData);

        // Both variants expose the list under the SAME canonical key
        self::assertCount(2, $viaResults['results']);
        self::assertCount(1, $viaData['results']);
        self::assertSame('o1', $viaResults['results'][0]['offer_id']);
        self::assertSame('o1', $viaData['results'][0]['offer_id']);

        // Cursor canonicalized from next_cursor|cursor; null when exhausted
        self::assertNull($viaResults['cursor']);
        self::assertNotEmpty($viaData['cursor']);

        // Extra payload keys (alternatives, …) survive canonicalization —
        // the poller reads them
        self::assertArrayHasKey('alternatives', $viaResults);
    }

    public function testPackageResultsCanonicalizeLikeHotelResults(): void
    {
        $results = $this->decoder->search(self::fixture('packages_results'));

        self::assertIsArray($results);
        self::assertSame('completed', $results['status']);
        self::assertCount(1, $results['results']);
        self::assertSame('tok-2', $results['cursor']);
    }

    public function testSearchNullStaysNull(): void
    {
        self::assertNull($this->decoder->search(null));
    }

    // ── single / list (A2 surface, pinned now) ───────────────────────────

    public function testSingleUnwrapsDataUnlessBodyIsTheResource(): void
    {
        self::assertSame(
            ['id' => 7, 'name' => 'X'],
            $this->decoder->single(['success' => true, 'data' => ['id' => 7, 'name' => 'X']]),
        );
        // Body already IS the resource (has id at top level) — no unwrap
        self::assertSame(
            ['id' => 7, 'data' => ['nested' => true]],
            $this->decoder->single(['id' => 7, 'data' => ['nested' => true]]),
        );
        self::assertNull($this->decoder->single(null));
        self::assertNull($this->decoder->single([]));
    }

    public function testListAcceptsAllCollectionEnvelopesAndBareLists(): void
    {
        $rows = [['id' => 1], ['id' => 2]];

        self::assertSame($rows, $this->decoder->list(['results' => $rows]));
        self::assertSame($rows, $this->decoder->list(['data' => $rows]));
        self::assertSame($rows, $this->decoder->list(['items' => $rows]));
        self::assertSame($rows, $this->decoder->list(['hotels' => $rows]));
        self::assertSame($rows, $this->decoder->list($rows));
        self::assertSame([], $this->decoder->list(['message' => 'no collection here']));
        self::assertSame([], $this->decoder->list(null));
    }

    // ── diagnostics ───────────────────────────────────────────────────────

    public function testLastEnvelopeRecordsBranchAndKeys(): void
    {
        $this->decoder->offer(self::fixture('hotels_verify.enveloped'));
        $envelope = $this->decoder->lastEnvelope();

        self::assertSame('offer:unwrapped', $envelope['branch']);
        self::assertContains('success', $envelope['keys']);
        self::assertContains('data', $envelope['keys']);
    }
}
