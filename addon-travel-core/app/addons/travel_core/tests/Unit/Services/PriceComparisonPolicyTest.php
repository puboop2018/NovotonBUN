<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Enums\PriceComparisonOutcome;
use Tygh\Addons\TravelCore\Enums\PriceDeltaBase;
use Tygh\Addons\TravelCore\Services\PriceComparisonPolicy;

/**
 * Pins the shared checkout "No Surprises" policy for BOTH providers'
 * historical configurations (audit A1). If either provider's knobs or the
 * branching order changes, these tests catch it — the drift that motivated
 * the extraction can no longer happen silently.
 */
#[CoversClass(PriceComparisonPolicy::class)]
final class PriceComparisonPolicyTest extends TestCase
{
    private static function novoton(float $threshold = 10.0): PriceComparisonPolicy
    {
        // novoton: percent of API price, no match epsilon
        return new PriceComparisonPolicy($threshold);
    }

    private static function sphinx(): PriceComparisonPolicy
    {
        // sphinx: 20% threshold, 0.01 epsilon, percent of form price
        return new PriceComparisonPolicy(20.0, 0.01, PriceDeltaBase::Form);
    }

    private static function unified(): PriceComparisonPolicy
    {
        // the CheckoutPriceGuard defaults now shared by all providers:
        // 20% of API price, 0.01 epsilon, €5 alert floor, €100 big-overage
        return new PriceComparisonPolicy(20.0, 0.01, PriceDeltaBase::Api, 5.0, 100.0);
    }

    /** @return array<string, array{PriceComparisonPolicy, float, float, PriceComparisonOutcome}> */
    public static function outcomes(): array
    {
        return [
            // ── novoton configuration ──
            'novoton: form below api → correct up'            => [self::novoton(), 100.00, 115.00, PriceComparisonOutcome::CorrectUp],
            'novoton: a cent below api still corrects'         => [self::novoton(), 114.99, 115.00, PriceComparisonOutcome::CorrectUp],
            'novoton: exact match'                             => [self::novoton(), 115.00, 115.00, PriceComparisonOutcome::Match],
            'novoton: above api within threshold'              => [self::novoton(), 120.00, 115.00, PriceComparisonOutcome::Match],
            'novoton: above api beyond threshold'              => [self::novoton(), 130.00, 115.00, PriceComparisonOutcome::AboveThreshold],
            'novoton: exactly AT threshold is allowed (>)'     => [self::novoton(), 110.00, 100.00, PriceComparisonOutcome::Match],
            'novoton: api price missing → no action'           => [self::novoton(), 115.00, 0.0, PriceComparisonOutcome::Match],
            // ── sphinx configuration ──
            'sphinx: form below api → correct up'              => [self::sphinx(), 100.00, 115.00, PriceComparisonOutcome::CorrectUp],
            'sphinx: sub-epsilon gap is a match'                => [self::sphinx(), 115.005, 115.00, PriceComparisonOutcome::Match],
            'sphinx: sub-epsilon gap below api is a match too'  => [self::sphinx(), 114.995, 115.00, PriceComparisonOutcome::Match],
            'sphinx: above api within 20% of form'              => [self::sphinx(), 130.00, 115.00, PriceComparisonOutcome::Match],
            'sphinx: above api beyond 20% of form'              => [self::sphinx(), 150.00, 115.00, PriceComparisonOutcome::AboveThreshold],
            'sphinx: api price missing → no action'             => [self::sphinx(), 115.00, 0.0, PriceComparisonOutcome::Match],
            // ── unified configuration (floor + big-overage) ──
            'unified: big percent but under €5 floor stays quiet' => [self::unified(), 5.00, 4.00, PriceComparisonOutcome::Match],
            'unified: big percent and over the floor alerts'      => [self::unified(), 48.50, 40.00, PriceComparisonOutcome::AboveThreshold],
            'unified: small percent but €300 overage alerts'      => [self::unified(), 5300.00, 5000.00, PriceComparisonOutcome::AboveThreshold],
            'unified: small percent, under big-overage, quiet'    => [self::unified(), 5090.00, 5000.00, PriceComparisonOutcome::Match],
            'unified: sub-cent float noise is a match'            => [self::unified(), 114.995, 115.00, PriceComparisonOutcome::Match],
            'unified: below api beyond noise corrects up'         => [self::unified(), 100.00, 115.00, PriceComparisonOutcome::CorrectUp],
        ];
    }

    #[DataProvider('outcomes')]
    public function testOutcome(PriceComparisonPolicy $policy, float $form, float $api, PriceComparisonOutcome $expected): void
    {
        self::assertSame($expected, $policy->compare($form, $api)->outcome);
    }

    public function testDeltaNumbersUseTheConfiguredBase(): void
    {
        // 23 above 115: novoton expresses it against the API price…
        $novoton = self::novoton()->compare(138.00, 115.00);
        self::assertEqualsWithDelta(23.0, $novoton->difference, 0.0001);
        self::assertEqualsWithDelta(20.0, $novoton->percentDelta, 0.0001);

        // …sphinx against the form price.
        $sphinx = self::sphinx()->compare(138.00, 115.00);
        self::assertEqualsWithDelta(23.0, $sphinx->difference, 0.0001);
        self::assertEqualsWithDelta(16.666, $sphinx->percentDelta, 0.001);
    }

    public function testCorrectUpReportsHowFarBelowTheApiPriceTheFormWas(): void
    {
        $result = self::novoton()->compare(100.00, 115.00);
        self::assertEqualsWithDelta(15.0, $result->difference, 0.0001);
        self::assertEqualsWithDelta(13.043, $result->percentDelta, 0.001);
    }
}
