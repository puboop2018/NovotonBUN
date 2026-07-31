<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\TermsDebug;

/**
 * The terms modal's debug panel.
 *
 * Two things must hold. It must be OFF unless the addon's debug setting says
 * otherwise — it renders on a customer-facing modal and carries raw provider
 * payloads including their stack traces. And when on, it must reproduce
 * payment_terms / cancellation_fees EXACTLY, because the whole point is to
 * distinguish "the API sent is_loaded:false" from "we mangled it".
 */
final class TermsDebugTest extends TestCase
{
    /** The shape the API actually sends on search — both blocks empty. */
    private const array LIVE_OFFER = [
        'confirmation' => 'immediate',
        'must_verify' => false,
        'payment_terms' => [
            'is_loaded' => false,
            'text' => null,
            'rules' => [],
        ],
        'cancellation_fees' => [
            'is_loaded' => false,
            'is_free' => false,
            'text' => null,
            'rules' => [],
        ],
    ];

    public function testReturnsNothingWhenDebugIsOff(): void
    {
        self::assertNull(TermsDebug::build(false, 'verify', 500, '', '{"message":"boom"}', self::LIVE_OFFER));
    }

    public function testReproducesBothBlocksVerbatim(): void
    {
        $debug = TermsDebug::build(true, 'verify', 200, '', '{}', self::LIVE_OFFER);

        self::assertNotNull($debug);
        // Identical, not merely equivalent: false must not become 0 or "",
        // null must not become "", and [] must not become null.
        self::assertSame(self::LIVE_OFFER['payment_terms'], $debug['payment_terms']);
        self::assertSame(self::LIVE_OFFER['cancellation_fees'], $debug['cancellation_fees']);
        self::assertFalse($debug['must_verify']);
        self::assertSame('immediate', $debug['confirmation']);
    }

    /**
     * An ABSENT block and an EMPTY block are different findings — one means
     * the payload never carried the key, the other means the API sent it
     * unloaded. Collapsing them would defeat the panel's purpose.
     */
    public function testAbsentBlocksReportAsNullRatherThanEmptyObjects(): void
    {
        $debug = TermsDebug::build(true, 'verify', 200, '', '{}', ['confirmation' => 'immediate']);

        self::assertNotNull($debug);
        self::assertNull($debug['payment_terms']);
        self::assertNull($debug['cancellation_fees']);
        self::assertNull($debug['must_verify']);
    }

    /** The outage case: no offer at all, but the provider's error body is the point. */
    public function testCarriesTheUpstreamErrorBodyWhenThereIsNoOffer(): void
    {
        $body = '{"message":"Undefined array key 0","file":"EtripV2Client.php","line":536}';
        $debug = TermsDebug::build(true, 'verify_failed_showing_search_snapshot', 500, '', $body, null);

        self::assertNotNull($debug);
        self::assertSame(500, $debug['http_code']);
        self::assertSame($body, $debug['raw_response']);
        self::assertFalse($debug['raw_response_truncated']);
        self::assertSame('verify_failed_showing_search_snapshot', $debug['source']);
        self::assertNull($debug['payment_terms']);
    }

    public function testTransportFailuresSurfaceTheCurlErrorAndAreNotConfusedWithEmpty(): void
    {
        $debug = TermsDebug::build(true, 'verify_failed', 0, 'Operation timed out', '', null);

        self::assertNotNull($debug);
        self::assertSame(0, $debug['http_code']);
        self::assertSame('Operation timed out', $debug['transport_error']);
        // No error → null, so "" never reads as a transport failure.
        $clean = TermsDebug::build(true, 'verify', 200, '', '{}', self::LIVE_OFFER);
        self::assertNull($clean['transport_error']);
    }

    /** A 5xx trace runs to many KB; the modal must not swallow the browser. */
    public function testLongBodiesAreTruncatedAndSaySo(): void
    {
        $huge = str_repeat('x', 9000);
        $debug = TermsDebug::build(true, 'verify_failed', 500, '', $huge, null);

        self::assertNotNull($debug);
        self::assertTrue($debug['raw_response_truncated']);
        self::assertSame(9000, $debug['raw_response_bytes']);
        self::assertSame(4000, strlen($debug['raw_response']));
    }
}
