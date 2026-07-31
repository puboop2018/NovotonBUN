<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the wiring of the "don't verify what the API says needs no verifying"
 * change, end to end.
 *
 * The decision is worthless unless the snapshot it reads actually gets
 * written, and there are TWO search paths that cache results — the
 * synchronous one in search.php and the polled one in search_poll.php. The
 * polled path is the one most searches take, so a snapshot written only by
 * search.php would leave the booking form falling back to the broken verify
 * for nearly every customer.
 */
final class VerifySkipWiringTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testBothSearchPathsWriteOfferSnapshots(): void
    {
        foreach ([
            'controllers/frontend/sphinx_booking/search.php',
            'controllers/frontend/sphinx_booking/search_poll.php',
        ] as $relative) {
            self::assertStringContainsString(
                'OfferSnapshotStore::remember(',
                self::src($relative),
                $relative . ': must persist per-offer snapshots when it caches results',
            );
        }
    }

    /**
     * The price must come from the server's own snapshot. booking_form.php is
     * reached by URL, so a price taken from the request could be edited into
     * an order total.
     */
    public function testBookingFormSkipsVerifyOnlyViaTheServerSideSnapshot(): void
    {
        $src = self::src('controllers/frontend/sphinx_booking/booking_form.php');

        self::assertStringContainsString('OfferSnapshotStore::get($offer_id)', $src);
        self::assertStringContainsString('OfferSnapshotStore::isBookableWithoutVerify($snapshot)', $src);
        // The snapshot decides; the request never does.
        self::assertStringNotContainsString("RequestCoerce::float(\$_REQUEST, 'price'", $src);
        // Verify still runs whenever the skip does not apply.
        self::assertStringContainsString('$verifyResult = $api->verifyHotelOffer($offer_id);', $src);
        // A skip is auditable.
        self::assertStringContainsString('verify SKIPPED', $src);
    }

    /**
     * The outage branch must not fire for a skipped verify — there is no
     * verify response to judge, and running the availability gate over a
     * snapshot would reject every skipped offer.
     */
    public function testTheOutageBranchIsGatedOnActuallyHavingVerified(): void
    {
        $src = self::src('controllers/frontend/sphinx_booking/booking_form.php');
        self::assertStringContainsString('if (!$skipVerify && !\Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::isVerifiedAvailable(', $src);
    }

    /**
     * The terms modal cannot invent a payment schedule — it only exists on the
     * verify response — but search DOES carry cancellation_fees.is_free, and
     * during an outage that single fact beats "we can tell you nothing".
     */
    public function testTermsModalFallsBackToTheSearchCancellationFact(): void
    {
        $controller = self::src('controllers/frontend/sphinx_booking/offer_terms.php');
        self::assertStringContainsString("'status' => 'partial'", $controller);
        self::assertStringContainsString('OfferSnapshotStore::get($offer_id)', $controller);
        // Only during an OUTAGE. A genuine rejection still says unavailable.
        self::assertStringContainsString('if ($outage && $snapshotFees !== []', $controller);

        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/../../../js/addons/sphinx_holidays/search-results.js',
        );
        self::assertStringContainsString("data.status === 'partial'", $js);
        // A degraded answer must not be cached over the real one.
        self::assertStringContainsString("if (data.status === 'ok') {", $js);

        $langKeys = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertArrayHasKey('sphinx_holidays.terms_partial', $langKeys);
    }

    /**
     * Snapshots must expire WITH the search results they came from. Outliving
     * them would let a stale price be served after the offers are gone.
     */
    public function testSnapshotsShareTheSearchCacheTtl(): void
    {
        self::assertStringContainsString(
            'OfferSnapshotStore::remember($initialResults, $cacheTtl)',
            self::src('controllers/frontend/sphinx_booking/search.php'),
        );
        self::assertStringContainsString(
            "TypeCoerce::toInt(\$searchMeta['cache_ttl'])",
            self::src('controllers/frontend/sphinx_booking/search_poll.php'),
        );
    }
}
