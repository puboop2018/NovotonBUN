<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Http;

/**
 * Shared HTTP resilience policy: the geometric retry-backoff schedule and the
 * consecutive-failure circuit breaker both provider clients implement.
 * Extracted so the logic exists ONCE (it lived in parallel in
 * NovotonHttpClient and SphinxHttpClient) and is unit-testable with an
 * injected clock.
 *
 * The policy owns DECISIONS and STATE only — clients keep their transport,
 * their retry-loop shape and their logging (the policy reports transitions
 * through return values so callers can log in their own voice).
 *
 * Half-open behavior is parameterized because the two clients genuinely
 * differ and both behaviors are preserved:
 *  - resetCountAtHalfOpen=true  (sphinx): cooldown expiry clears the failure
 *    count — it takes a full new threshold of failures to re-open.
 *  - resetCountAtHalfOpen=false (novoton): the count survives cooldown — a
 *    single new failure re-opens the circuit immediately.
 */
final class ResiliencePolicy
{
    private int $failureCount = 0;
    private int $lastFailureAt = 0;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly int $failureThreshold,
        private readonly int $cooldownSeconds,
        private readonly int $initialDelayMs,
        private readonly float $delayMultiplier,
        private readonly bool $resetCountAtHalfOpen,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Backoff delay for the Nth retry (1-based). Computed iteratively with a
     * per-step integer cast — for float multipliers this matches the chained
     * `$delay = (int) ($delay * $multiplier)` the clients used, which can
     * differ from a one-shot pow() by rounding.
     */
    public function delayMsForRetry(int $retryNumber): int
    {
        $delay = $this->initialDelayMs;
        for ($i = 1; $i < $retryNumber; $i++) {
            $delay = (int) ($delay * $this->delayMultiplier);
        }

        return max(0, $delay);
    }

    /**
     * Whether the circuit currently blocks requests. Cooldown expiry flips to
     * half-open: requests flow again, with the failure count cleared or kept
     * per the configured half-open mode.
     */
    public function isOpen(): bool
    {
        if ($this->failureCount < $this->failureThreshold) {
            return false;
        }
        if (($this->clock)() - $this->lastFailureAt < $this->cooldownSeconds) {
            return true;
        }
        if ($this->resetCountAtHalfOpen) {
            $this->failureCount = 0;
        }

        return false;
    }

    /**
     * @return bool True when this failure put (or keeps) the circuit at/over
     *              the threshold — callers use it to log the OPEN transition.
     */
    public function recordFailure(): bool
    {
        $this->failureCount++;
        $this->lastFailureAt = ($this->clock)();

        return $this->failureCount >= $this->failureThreshold;
    }

    /**
     * @return bool True when there was failure state to clear — callers use
     *              it to log the RESET transition.
     */
    public function recordSuccess(): bool
    {
        $wasDirty = $this->failureCount > 0;
        $this->failureCount = 0;
        $this->lastFailureAt = 0;

        return $wasDirty;
    }

    public function forceReset(): void
    {
        $this->failureCount = 0;
        $this->lastFailureAt = 0;
    }

    public function secondsUntilRetry(): int
    {
        if (!$this->isOpen()) {
            return 0;
        }

        return max(0, $this->cooldownSeconds - (($this->clock)() - $this->lastFailureAt));
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function lastFailureAt(): int
    {
        return $this->lastFailureAt;
    }

    public function failureThreshold(): int
    {
        return $this->failureThreshold;
    }

    public function cooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }

    /**
     * Transport-level retryability: connection-class curl errors, 5xx, 429,
     * and code 0 with an error message. 4xx (except 429) is permanent.
     * (Moved verbatim from NovotonHttpClient::isRetryableError.)
     */
    public static function isRetryableTransportError(string $error, int $httpCode): bool
    {
        $retryableErrors = [
            'Connection timed out',
            'Connection refused',
            'Could not resolve host',
            'Operation timed out',
            'SSL connection timeout',
            'Network is unreachable',
            'Empty reply from server',
        ];

        foreach ($retryableErrors as $retryable) {
            if (str_contains(strtolower($error), strtolower($retryable))) {
                return true;
            }
        }

        if ($httpCode >= 500 && $httpCode < 600) {
            return true;
        }

        if ($httpCode === 429) {
            return true;
        }

        return $httpCode === 0 && $error !== '';
    }
}
