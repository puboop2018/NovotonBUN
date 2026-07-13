<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\AlternativeRequestNotifier;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * The admin notification for new "no availability" requests: goes to the
 * orders-department email with the request context in the body, and quietly
 * does nothing (logged) when that email is not configured.
 */
#[CoversClass(AlternativeRequestNotifier::class)]
final class AlternativeRequestNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testSendsMailWithRequestContext(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => 'orders@example.com';

        $sent = [];
        $notifier = new AlternativeRequestNotifier(static function (array $mail) use (&$sent): bool {
            $sent = $mail;
            return true;
        });

        $ok = $notifier->notifyAdmin([
            'provider' => 'sphinx',
            'hotel_id' => 's1-hotel-61992',
            'hotel_name' => 'Ozkaymak Falez Otel',
            'check_in' => '2026-08-06',
            'check_out' => '2026-08-13',
            'nights' => 7,
            'adults' => 2,
            'children' => 1,
            'num_rooms' => 1,
            'contact_email' => 'guest@example.com',
            'contact_phone' => '+40 700 000 000',
            'notes' => 'Sea view preferred',
        ]);

        self::assertTrue($ok);
        self::assertSame('orders@example.com', $sent['to']);
        self::assertStringContainsString('Ozkaymak Falez Otel', $sent['subject']);
        self::assertStringContainsString('2026-08-06', $sent['subject']);
        self::assertStringContainsString('Provider: sphinx', $sent['body']);
        self::assertStringContainsString('guest@example.com', $sent['body']);
        self::assertStringContainsString('+40 700 000 000', $sent['body']);
        self::assertStringContainsString('Sea view preferred', $sent['body']);
        self::assertStringContainsString('travel_alternatives.manage', $sent['body']);
    }

    public function testDoesNothingWhenAdminEmailMissing(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => '';

        $called = false;
        $notifier = new AlternativeRequestNotifier(static function (array $mail) use (&$called): bool {
            $called = true;
            return true;
        });

        self::assertFalse($notifier->notifyAdmin(['provider' => 'novoton']));
        self::assertFalse($called, 'no mail must be attempted without a configured admin email');
    }
}
