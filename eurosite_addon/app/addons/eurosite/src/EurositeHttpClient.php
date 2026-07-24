<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Http\ResiliencePolicy;

/**
 * Eurosite HTTP transport.
 *
 * POSTs a raw XML request body to the Eurosite web-service endpoint and returns
 * the raw response, with retry/backoff + a circuit breaker via the shared
 * travel_core ResiliencePolicy (same resilience novoton/sphinx use). The
 * endpoint URL, credentials and tuning come from the settings map handed in by
 * the Container (Registry stays out of src/).
 */
final class EurositeHttpClient implements EurositeTransportInterface
{
    private readonly string $apiUrl;

    private readonly bool $allowInsecure;

    private readonly int $maxRetries;

    private readonly int $timeout;

    private readonly ResiliencePolicy $resilience;

    // Debug state — last transport result, for probes/logging.
    public int $lastHttpCode = 0;

    public string $lastError = '';

    public string $lastResponseRaw = '';

    /**
     * @param array<string, mixed> $settings ConfigProvider::toClientSettings()
     */
    public function __construct(array $settings, ?ResiliencePolicy $resilience = null)
    {
        $url = TypeCoerce::toString($settings['api_url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('Eurosite API URL not configured — set api_url in addon settings.');
        }
        if (preg_match('#^https?://#', $url) !== 1) {
            $url = 'http://' . $url;
        }
        $this->apiUrl = $url;

        $this->allowInsecure = (bool) ($settings['allow_insecure_api'] ?? true);
        if (str_starts_with(strtolower($this->apiUrl), 'http://') && !$this->allowInsecure) {
            throw new \InvalidArgumentException(
                'Eurosite API URL uses insecure HTTP but "allow_insecure_api" is disabled. '
                . 'Use an https:// endpoint or re-enable the insecure-connection setting.',
            );
        }

        $this->maxRetries = max(1, TypeCoerce::toInt($settings['api_max_retries'] ?? 3));
        $this->timeout = max(5, TypeCoerce::toInt($settings['api_timeout'] ?? 60));

        $this->resilience = $resilience ?? new ResiliencePolicy(
            failureThreshold: max(1, TypeCoerce::toInt($settings['circuit_breaker_threshold'] ?? 5)),
            cooldownSeconds: max(1, TypeCoerce::toInt($settings['circuit_breaker_timeout'] ?? 60)),
            initialDelayMs: 1000,
            delayMultiplier: 2.0,
            resetCountAtHalfOpen: true,
        );
    }

    /**
     * POST an XML request body; return the raw response text.
     *
     * @throws \RuntimeException On an open circuit or an exhausted-retry failure.
     */
    #[\Override]
    public function post(string $xml): string
    {
        if ($this->resilience->isOpen()) {
            $this->lastError = 'Circuit breaker open — Eurosite API temporarily unavailable';
            throw new \RuntimeException($this->lastError);
        }

        $lastError = '';
        $lastHttpCode = 0;
        $response = false;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init($this->apiUrl);
            if ($ch === false) {
                throw new \RuntimeException('Eurosite: failed to initialize curl');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=UTF-8', 'Accept: text/xml'],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => !$this->allowInsecure,
                CURLOPT_SSL_VERIFYHOST => $this->allowInsecure ? 0 : 2,
            ]);
            $response = curl_exec($ch);
            $lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            if (($lastError === '') && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                $this->resilience->recordSuccess();
                break;
            }

            $retryable = ResiliencePolicy::isRetryableTransportError($lastError, $lastHttpCode);
            if ($retryable && $attempt < $this->maxRetries) {
                usleep($this->resilience->delayMsForRetry($attempt) * 1000);
            } elseif (!$retryable) {
                break;
            }
        }

        $this->lastHttpCode = $lastHttpCode;
        $this->lastError = $lastError;
        $this->lastResponseRaw = is_string($response) ? $response : '';

        if ($lastError !== '' || $lastHttpCode < 200 || $lastHttpCode >= 300) {
            $this->resilience->recordFailure();
            throw new \RuntimeException(
                "Eurosite API request failed (HTTP {$lastHttpCode})" . ($lastError !== '' ? ": {$lastError}" : ''),
            );
        }

        return is_string($response) ? $response : '';
    }
}
