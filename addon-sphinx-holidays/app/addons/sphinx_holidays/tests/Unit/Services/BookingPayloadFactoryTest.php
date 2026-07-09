<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\BookingPayloadFactory;

/**
 * The factory owns the book* wire format for BOTH submission paths (order
 * placement + admin retry). Shape drift here re-opens the class of bug where
 * the retry path submitted a payload no endpoint understood.
 */
#[CoversClass(BookingPayloadFactory::class)]
final class BookingPayloadFactoryTest extends TestCase
{
    public function testBuildProducesTheProvenWireShape(): void
    {
        $guests = [
            'room1_adult_1' => ['name' => 'Pop, Ana', 'type' => 'adult', 'is_holder' => 1],
            'room1_child_1' => ['name' => 'Pop, Mia', 'type' => 'child', 'age' => 7],
        ];

        $payload = BookingPayloadFactory::build('OFF-123', $guests, ['email' => 'a@b.ro', 'phone' => '+40711'], 85);

        self::assertSame([
            'offer_id' => 'OFF-123',
            'guests' => $guests,
            'contact' => ['email' => 'a@b.ro', 'phone' => '+40711'],
            'reference_code' => '85',
        ], $payload);
    }

    public function testBuildOmitsReferenceCodeWithoutOrderAndCoercesMissingContact(): void
    {
        $payload = BookingPayloadFactory::build('OFF-9', [], []);

        self::assertArrayNotHasKey('reference_code', $payload);
        self::assertSame(['email' => '', 'phone' => ''], $payload['contact']);
    }

    public function testContactFromUserDataFallsThroughPhoneVariants(): void
    {
        self::assertSame(
            ['email' => 'c@d.ro', 'phone' => '+40100'],
            BookingPayloadFactory::contactFromUserData(['email' => 'c@d.ro', 'phone' => '+40100', 'b_phone' => '+40200']),
        );

        // Profile phone empty → billing phone → shipping phone
        self::assertSame(
            ['email' => 'c@d.ro', 'phone' => '+40200'],
            BookingPayloadFactory::contactFromUserData(['email' => 'c@d.ro', 'phone' => '', 'b_phone' => '+40200']),
        );
        self::assertSame(
            ['email' => 'c@d.ro', 'phone' => '+40300'],
            BookingPayloadFactory::contactFromUserData(['email' => 'c@d.ro', 's_phone' => '+40300']),
        );

        self::assertSame(['email' => '', 'phone' => ''], BookingPayloadFactory::contactFromUserData([]));
    }
}
