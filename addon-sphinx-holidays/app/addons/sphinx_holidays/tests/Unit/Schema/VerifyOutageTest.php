<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the outage/expiry distinction around the offer VERIFY call.
 *
 * verifyHotelOffer() returns null both when the API says the offer is gone
 * (404) and when the verify SERVICE itself is down (5xx/transport — field
 * case: the staging API 500ing inside its supplier client on every verify).
 * The two must not share copy: "please search again" during an outage sends
 * the guest chasing fresh offers that cannot verify either, and the
 * refresh=1 cache eviction is pure waste. The HTTP status code is the
 * discriminator: 0 (transport) or >= 500 → outage.
 */
final class VerifyOutageTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function testOfferTermsReportsOutageDistinctFromUnavailable(): void
    {
        $src = self::src('controllers/frontend/sphinx_booking/offer_terms.php');

        self::assertStringContainsString('getLastHttpCode()', $src);
        self::assertStringContainsString("'outage' : 'unavailable'", $src);
        self::assertStringContainsString('$verifyHttpCode === 0 || $verifyHttpCode >= 500', $src);
    }

    public function testBookingFormOutageSkipsRefreshAndSaysTheTruth(): void
    {
        $src = self::src('controllers/frontend/sphinx_booking/booking_form.php');

        self::assertStringContainsString('$verifyOutage = $verifyHttpCode === 0 || $verifyHttpCode >= 500;', $src);
        self::assertStringContainsString('sphinx_holidays.booking_system_unavailable', $src);
        // Genuine expiry keeps the live re-search; an outage must not evict.
        self::assertStringContainsString("if (!\$verifyOutage) {\n            \$redirectParams['refresh'] = 1;", $src);
        // The log line names the outage so it is never mistaken for expiry.
        self::assertStringContainsString('verify OUTAGE (HTTP ', $src);
    }

    /**
     * BOTH verify rejections must leave a trace, and it must carry the RAW
     * upstream body.
     *
     * Field case: a guest reported "Condițiile nu pot fi afișate" and "Sistemul
     * de rezervări este momentan indisponibil" on the same page. They are one
     * outage — offer_terms and booking_form both re-verify the offer — but only
     * booking_form logged anything, so the terms half was invisible and the two
     * looked unrelated.
     *
     * And what booking_form logged was nearly useless: a 5xx decodes to null,
     * so `verify_response` recorded the literal string "null" while the
     * provider's actual error (an "Undefined array key 0" inside their own
     * EtripV2Client) was discarded — the one thing worth forwarding to them.
     */
    public function testBothVerifyRejectionsLogTheRawUpstreamFailure(): void
    {
        foreach ([
            'controllers/frontend/sphinx_booking/offer_terms.php',
            'controllers/frontend/sphinx_booking/booking_form.php',
        ] as $relative) {
            $src = self::src($relative);

            self::assertStringContainsString('fn_log_event(', $src, $relative . ': rejection must be logged');
            self::assertStringContainsString(
                "'raw_response' => substr((string) \$",
                $src,
                $relative . ': log the RAW body — a 5xx decodes to null',
            );
            self::assertStringContainsString(
                "'transport_error' =>",
                $src,
                $relative . ': a transport failure (HTTP 0) has no body, only a curl error',
            );
        }
    }

    /**
     * The standalone probe is how "is it them or us?" gets answered without a
     * storefront round-trip, and it must classify identically to the code above.
     */
    public function testTheStandaloneVerifyProbeExistsAndMatchesTheStorefrontRule(): void
    {
        $probe = (string) file_get_contents(dirname(__DIR__, 7) . '/dev/sphinx_api_dev/VerifyOffer.php');

        self::assertNotSame('', $probe, 'dev/sphinx_api_dev/VerifyOffer.php must exist');
        self::assertStringContainsString('/api/v1/hotels/verify?offer_id=', $probe);
        // Same discriminator as offer_terms.php / booking_form.php.
        self::assertStringContainsString('if ($code >= 500)', $probe);
        self::assertStringContainsString("if (\$code === 0)", $probe);
        // It searches first, because offer ids expire.
        self::assertStringContainsString('/api/v1/hotels/search', $probe);
    }

    public function testModalRendersTheOutageCopy(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/../../../js/addons/sphinx_holidays/search-results.js',
        );
        self::assertStringContainsString('function showOutage()', $js);
        self::assertStringContainsString("data.status === 'outage'", $js);
        self::assertStringContainsString('.catch(showOutage)', $js);

        // The label ships through the data-client-config JSON.
        $controller = self::src('controllers/frontend/sphinx_booking/search.php');
        self::assertStringContainsString("'termsOutage' =>", $controller);

        // Seeded (runtime seeder + .po parity is enforced addon-wide).
        $langKeys = require dirname(__DIR__, 3) . '/lang_keys.php';
        self::assertArrayHasKey('sphinx_holidays.terms_outage', $langKeys);
        self::assertArrayHasKey('sphinx_holidays.booking_system_unavailable', $langKeys);
    }
}
