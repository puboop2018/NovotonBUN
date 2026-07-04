<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;
use Tygh\Addons\TravelCore\Services\CheckoutPriceGuard;
use Tygh\Registry;

/**
 * Pins the settings-backed factory that gives ALL providers one checkout
 * price policy: code-side defaults when settings are absent, Registry
 * overrides when set, and the absorb allowance for the reconfirm flow.
 */
#[CoversClass(CheckoutPriceGuard::class)]
final class CheckoutPriceGuardTest extends TestCase
{
    private const SETTINGS = [
        'checkout_alert_percent',
        'checkout_alert_floor',
        'checkout_big_overage',
        'checkout_absorb_increase',
    ];

    protected function setUp(): void
    {
        $this->clearSettings();
    }

    protected function tearDown(): void
    {
        $this->clearSettings();
    }

    private function clearSettings(): void
    {
        foreach (self::SETTINGS as $name) {
            Registry::del('addons.travel_core.' . $name);
        }
    }

    public function testDefaultsApplyWhenSettingsAbsent(): void
    {
        self::assertSame(20.0, CheckoutPriceGuard::alertPercent());
        self::assertSame(5.0, CheckoutPriceGuard::alertFloor());
        self::assertSame(100.0, CheckoutPriceGuard::bigOverage());
        self::assertSame(0.0, CheckoutPriceGuard::absorbIncrease());
    }

    public function testSettingsOverrideDefaults(): void
    {
        Registry::set('addons.travel_core.checkout_alert_percent', '10');
        Registry::set('addons.travel_core.checkout_alert_floor', '2.5');
        Registry::set('addons.travel_core.checkout_big_overage', '250');
        Registry::set('addons.travel_core.checkout_absorb_increase', '3');

        self::assertSame(10.0, CheckoutPriceGuard::alertPercent());
        self::assertSame(2.5, CheckoutPriceGuard::alertFloor());
        self::assertSame(250.0, CheckoutPriceGuard::bigOverage());
        self::assertSame(3.0, CheckoutPriceGuard::absorbIncrease());
    }

    public function testPolicyReflectsTheSettings(): void
    {
        // Defaults: €8 over on a €40 item = 20% of API — above 20? No (>, not >=):
        // exactly-at-threshold stays quiet; €8.01 would alert.
        self::assertSame(
            PriceComparisonOutcome::Match,
            CheckoutPriceGuard::policy()->compare(48.00, 40.00)->outcome,
        );

        Registry::set('addons.travel_core.checkout_alert_percent', '10');
        self::assertSame(
            PriceComparisonOutcome::AboveThreshold,
            CheckoutPriceGuard::policy()->compare(48.00, 40.00)->outcome,
        );
    }

    public function testPolicyAppliesTheSharedEpsilon(): void
    {
        // Sub-cent float noise below the API price must NOT trigger a correction.
        self::assertSame(
            PriceComparisonOutcome::Match,
            CheckoutPriceGuard::policy()->compare(114.999999, 115.00)->outcome,
        );
        // A real cent still corrects.
        self::assertSame(
            PriceComparisonOutcome::CorrectUp,
            CheckoutPriceGuard::policy()->compare(114.99, 115.00)->outcome,
        );
    }
}
