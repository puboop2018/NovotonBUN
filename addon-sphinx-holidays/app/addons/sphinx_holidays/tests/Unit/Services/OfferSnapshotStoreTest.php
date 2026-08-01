<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\OfferSnapshotStore;

/**
 * The gate that decides whether an offer can be booked WITHOUT calling verify.
 *
 * Field case this exists for: the provider's verify endpoint 500s ("Undefined
 * array key 0" inside their EtripV2Client) and we were calling it for every
 * offer, including the ones the API explicitly marks must_verify=false. All
 * four live Porto Bello Hotel Resort&Spa offers carry must_verify=false, so
 * both the terms modal and the Rezervă acum button failed on a call that the
 * API's own contract says is unnecessary.
 *
 * The gate protects an ORDER TOTAL, so every ambiguous case must fail closed:
 * the booking form is reached by URL and a skipped verify means the snapshot's
 * price is what the customer is shown.
 */
final class OfferSnapshotStoreTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function offer(array $overrides = []): array
    {
        return $overrides + [
            'must_verify' => false,
            'confirmation' => 'immediate',
            'price' => 1452.0,      // what the card shows (commissioned)
            'base_price' => 1355.0, // the provider's own figure
        ];
    }

    public function testSkipsVerifyForAnImmediateOfferThatDeclaresMustVerifyFalse(): void
    {
        self::assertTrue(OfferSnapshotStore::isBookableWithoutVerify(self::offer()));
    }

    public function testVerifiesWhenTheApiAsksForIt(): void
    {
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['must_verify' => true])));
    }

    /**
     * A MISSING must_verify is not a licence to skip. The API documents the
     * flag as always present; an absent one means the shape drifted or the
     * snapshot predates this field, and neither is evidence that verification
     * is unnecessary.
     */
    public function testAbsentMustVerifyIsTreatedAsRequiringVerification(): void
    {
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify([
            'confirmation' => 'immediate',
            'price' => 1452.0,
        ]));
    }

    public function testNoSnapshotMeansVerify(): void
    {
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(null));
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify([]));
    }

    /**
     * on_request offers are confirmed by the supplier, not instantly, so
     * skipping the round trip would let us present an unconfirmed booking as
     * a confirmed one.
     */
    public function testOnRequestOffersStillVerify(): void
    {
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['confirmation' => 'on_request'])));
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['confirmation' => ''])));
    }

    /** Nothing to charge means nothing to trust — verify instead of showing 0. */
    public function testAZeroOrNegativePriceNeverSkipsVerify(): void
    {
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['price' => 0])));
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['price' => -10])));
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['price' => 'abc'])));
    }

    /**
     * Only a real boolean false permits the skip.
     *
     * This caught a live bug: the gate first coerced the flag before
     * comparing, and TypeCoerce::toBool(null) is false — so an offer whose
     * payload simply lacked the field, decoding to null, was treated as
     * "verification not required" and skipped. null/0/"" mean UNKNOWN, and
     * unknown must verify. JSON `false` is the only shape the API can
     * legitimately send, so strict identity is both correct and safe.
     */
    public function testOnlyABooleanFalsePermitsTheSkip(): void
    {
        foreach ([true, 'true', 'false', 1, 0, '0', '', null, [], 'no'] as $value) {
            self::assertFalse(
                OfferSnapshotStore::isBookableWithoutVerify(self::offer(['must_verify' => $value])),
                'must_verify=' . var_export($value, true) . ' must NOT skip verification',
            );
        }

        self::assertTrue(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['must_verify' => false])));
    }

    public function testConfirmationMatchIsCaseInsensitive(): void
    {
        self::assertTrue(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['confirmation' => 'IMMEDIATE'])));
    }

    public function testKeyIsNamespacedAndVersioned(): void
    {
        // Versioned so a snapshot written by an older deploy, with a different
        // shape, is never read back as if it were current. v2 added base_price.
        self::assertSame('offer:v2:abc-123', OfferSnapshotStore::key('abc-123'));
    }

    /**
     * The commission trap.
     *
     * The search controllers commission every row BEFORE caching it
     * (`$result['original_price'] = $result['price']; $result['price'] =
     * applyCommission(...)`), while booking_form.php applies commission to
     * whatever OfferAvailability::extractPrice() hands it. So the payload the
     * skip path substitutes for a verify response must carry the PRE-commission
     * figure — otherwise commission is charged twice and the booking form shows
     * a higher total than the card the customer clicked.
     */
    public function testVerifyShapeCarriesThePreCommissionPrice(): void
    {
        $shape = OfferSnapshotStore::toVerifyShape(self::offer([
            'offer_id' => 'abc',
            'room_name' => 'STANDARD SIDE SEA VIEW',
        ]));

        self::assertSame(1355.0, $shape['price'], 'must be base_price, not the commissioned card price');
        self::assertNotSame(1452.0, $shape['price']);
        // Presented as an already-verified offer so downstream gates pass.
        self::assertFalse($shape['must_verify']);
        self::assertSame('STANDARD SIDE SEA VIEW', $shape['room_name']);
    }

    /**
     * A snapshot with no base_price cannot be priced correctly, so it must
     * verify rather than fall back to the commissioned figure. This also
     * covers v1-shaped rows left over from an earlier deploy.
     */
    public function testMissingBasePriceRefusesToSkip(): void
    {
        $noBase = self::offer();
        unset($noBase['base_price']);
        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify($noBase));

        self::assertFalse(OfferSnapshotStore::isBookableWithoutVerify(self::offer(['base_price' => 0])));
    }
}
