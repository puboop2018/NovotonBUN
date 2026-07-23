<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

final class ResiliencePolicyTest extends TestCase
{
    private int $now = 1_000_000;

    private function policy(
        int $threshold = 5,
        int $cooldown = 60,
        int $initialDelayMs = 1000,
        float $multiplier = 2.0,
        bool $resetCountAtHalfOpen = true,
    ): ResiliencePolicy {
        return new ResiliencePolicy(
            failureThreshold: $threshold,
            cooldownSeconds: $cooldown,
            initialDelayMs: $initialDelayMs,
            delayMultiplier: $multiplier,
            resetCountAtHalfOpen: $resetCountAtHalfOpen,
            clock: fn (): int => $this->now,
        );
    }

    public function testBackoffIsGeometricFromTheInitialDelay(): void
    {
        $p = $this->policy(initialDelayMs: 1000, multiplier: 2.0);

        self::assertSame(1000, $p->delayMsForRetry(1));
        self::assertSame(2000, $p->delayMsForRetry(2));
        self::assertSame(4000, $p->delayMsForRetry(3));
    }

    public function testBackoffCastsPerStepLikeTheChainedClientLoops(): void
    {
        // (int)((int)(100 * 1.5) * 1.5) = (int)(150 * 1.5) = 225 — the chained
        // per-step cast both clients used, not a one-shot pow().
        $p = $this->policy(initialDelayMs: 100, multiplier: 1.5);

        self::assertSame(100, $p->delayMsForRetry(1));
        self::assertSame(150, $p->delayMsForRetry(2));
        self::assertSame(225, $p->delayMsForRetry(3));
    }

    public function testCircuitOpensAtTheThresholdAndReportsTheTransition(): void
    {
        $p = $this->policy(threshold: 3);

        self::assertFalse($p->recordFailure());
        self::assertFalse($p->recordFailure());
        self::assertFalse($p->isOpen(), 'below threshold the circuit stays closed');
        self::assertTrue($p->recordFailure(), 'the threshold-crossing failure reports the OPEN transition');
        self::assertTrue($p->isOpen());
    }

    public function testCircuitClosesAfterTheCooldown(): void
    {
        $p = $this->policy(threshold: 2, cooldown: 60);
        $p->recordFailure();
        $p->recordFailure();
        self::assertTrue($p->isOpen());
        self::assertSame(60, $p->secondsUntilRetry());

        $this->now += 30;
        self::assertTrue($p->isOpen());
        self::assertSame(30, $p->secondsUntilRetry());

        $this->now += 30;
        self::assertFalse($p->isOpen());
        self::assertSame(0, $p->secondsUntilRetry());
    }

    public function testHalfOpenWithResetNeedsAFullNewThresholdToReopen(): void
    {
        // Sphinx mode: cooldown expiry clears the count.
        $p = $this->policy(threshold: 2, cooldown: 60, resetCountAtHalfOpen: true);
        $p->recordFailure();
        $p->recordFailure();
        $this->now += 61;
        self::assertFalse($p->isOpen());

        $p->recordFailure();
        self::assertFalse($p->isOpen(), 'one failure after half-open must NOT reopen in reset mode');
        $p->recordFailure();
        self::assertTrue($p->isOpen());
    }

    public function testHalfOpenWithoutResetReopensOnASingleFailure(): void
    {
        // Novoton mode: the count survives cooldown.
        $p = $this->policy(threshold: 2, cooldown: 60, resetCountAtHalfOpen: false);
        $p->recordFailure();
        $p->recordFailure();
        $this->now += 61;
        self::assertFalse($p->isOpen());

        self::assertTrue($p->recordFailure(), 'still at/over threshold');
        self::assertTrue($p->isOpen(), 'a single failure after half-open reopens in keep mode');
    }

    public function testSuccessResetsAndReportsWhetherStateWasDirty(): void
    {
        $p = $this->policy(threshold: 3);
        self::assertFalse($p->recordSuccess(), 'clean state — nothing to report');
        $p->recordFailure();
        self::assertTrue($p->recordSuccess(), 'failure state cleared — callers log the RESET');
        self::assertSame(0, $p->failureCount());
        self::assertFalse($p->isOpen());
    }

    public function testForceResetClearsEverything(): void
    {
        $p = $this->policy(threshold: 1);
        $p->recordFailure();
        self::assertTrue($p->isOpen());
        $p->forceReset();
        self::assertFalse($p->isOpen());
        self::assertSame(0, $p->failureCount());
        self::assertSame(0, $p->lastFailureAt());
    }

    public function testRetryableTransportErrorClassification(): void
    {
        self::assertTrue(ResiliencePolicy::isRetryableTransportError('Connection timed out after 5000ms', 0));
        self::assertTrue(ResiliencePolicy::isRetryableTransportError('could not resolve host: api.example', 0));
        self::assertTrue(ResiliencePolicy::isRetryableTransportError('', 503));
        self::assertTrue(ResiliencePolicy::isRetryableTransportError('', 429));
        self::assertTrue(ResiliencePolicy::isRetryableTransportError('some transport error', 0));
        self::assertFalse(ResiliencePolicy::isRetryableTransportError('', 404), '4xx (except 429) is permanent');
        self::assertFalse(ResiliencePolicy::isRetryableTransportError('', 400));
        self::assertFalse(ResiliencePolicy::isRetryableTransportError('', 0), 'code 0 with no error message is not retryable');
    }
}
