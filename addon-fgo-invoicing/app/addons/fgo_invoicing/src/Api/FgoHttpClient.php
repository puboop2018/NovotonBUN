<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Api;

/**
 * cURL-based HTTP client for the FGO REST API.
 *
 * FGO accepts only `application/x-www-form-urlencoded` POST bodies and
 * encodes the auth (CodUnic + Hash + Token fields) inline; there are no
 * Authorization headers. Retry / circuit-breaker / rate-limit handling
 * mirror SphinxHttpClient. A class-level last-call timestamp throttles to
 * `minIntervalMs` (default 1 s) to satisfy FGO's published rate limit.
 *
 * The transport surface is intentionally inline (`curl_*`) to match the
 * sibling addons; a Transport-interface extraction is the same deferred
 * refactor that's already pending on SphinxHttpClient.
 */
class FgoHttpClient
{
    private const int CURL_TIMEOUT = 30;
    private const int CURL_CONNECT_TIMEOUT = 10;
    private const int RATE_LIMIT_FALLBACK_WAIT = 60;

    private string $baseUrl;
    private int $maxRetries;
    private int $retryDelayMs;
    private float $retryMultiplier;
    private int $cbThreshold;
    private int $cbTimeout;
    private int $minIntervalMs;
    private bool $debugLogging;

    private int $failureCount = 0;
    private int $circuitOpenedAt = 0;

    private int $lastHttpCode = 0;
    private string $lastError = '';
    private ?string $lastResponseRaw = null;

    /** Microsecond timestamp of the last attempted request (for throttling). */
    private static float $lastCallAt = 0.0;

    public function __construct(
        string $baseUrl,
        int $maxRetries = 2,
        int $retryDelayMs = 500,
        float $retryMultiplier = 2.0,
        int $cbThreshold = 5,
        int $cbTimeout = 60,
        int $minIntervalMs = 1000,
        bool $debugLogging = false,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->maxRetries = max(0, $maxRetries);
        $this->retryDelayMs = max(0, $retryDelayMs);
        $this->retryMultiplier = $retryMultiplier > 0 ? $retryMultiplier : 1.0;
        $this->cbThreshold = max(1, $cbThreshold);
        $this->cbTimeout = max(1, $cbTimeout);
        $this->minIntervalMs = max(0, $minIntervalMs);
        $this->debugLogging = $debugLogging;
    }

    /**
     * Send a form-encoded POST and return the decoded JSON body.
     *
     * @param string $path Endpoint path relative to base URL (no leading slash).
     * @param array<string, scalar> $form Flat form fields. Nested keys (e.g. "Client[Tip]")
     *                                    must already be flattened by the caller.
     *
     * @return array<string, mixed>|null Decoded body on success; null if the request
     *                                   ultimately failed (see getLastError() / getLastHttpCode()).
     */
    public function post(string $path, array $form): ?array
    {
        if ($this->isCircuitOpen()) {
            $this->lastError = 'Circuit breaker is open — API calls suspended for ' . $this->cbTimeout . 's';
            return null;
        }

        $url = $this->baseUrl . ltrim($path, '/');
        $body = http_build_query($form, '', '&', PHP_QUERY_RFC1738);

        $attempt = 0;
        $delayMs = $this->retryDelayMs;

        while ($attempt <= $this->maxRetries) {
            $this->throttle();

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);

            $response = curl_exec($ch);
            $this->lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            self::$lastCallAt = microtime(true);
            $this->lastResponseRaw = is_string($response) ? $response : null;

            if ($response === false) {
                $this->lastError = 'cURL error: ' . $curlError;
                $this->recordFailure();
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep($delayMs * 1000);
                    $delayMs = (int) ($delayMs * $this->retryMultiplier);
                }
                continue;
            }

            if ($this->lastHttpCode === 429) {
                $waitSeconds = self::RATE_LIMIT_FALLBACK_WAIT;
                $this->lastError = "Rate limited. Waiting {$waitSeconds}s.";
                $this->log("Rate limited on POST {$path}. Waiting {$waitSeconds}s.");
                sleep($waitSeconds);
                $attempt++;
                continue;
            }

            if ($this->lastHttpCode >= 500) {
                $this->lastError = "Server error HTTP {$this->lastHttpCode}";
                $this->recordFailure();
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep($delayMs * 1000);
                    $delayMs = (int) ($delayMs * $this->retryMultiplier);
                }
                continue;
            }

            if ($this->lastHttpCode >= 400) {
                $this->lastError = "Client error HTTP {$this->lastHttpCode}: " . (string) $response;
                return null;
            }

            $this->resetFailures();
            $decoded = json_decode((string) $response, true);
            if (!is_array($decoded)) {
                $this->lastError = 'JSON decode error: ' . (json_last_error_msg() ?: 'response is not a JSON object/array');
                return null;
            }
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        return null;
    }

    /**
     * Sleep just long enough that successive calls are at least `minIntervalMs`
     * apart. Static state is intentional — FGO's throttle is per-merchant, not
     * per-instance, so the lock is global to the PHP process.
     */
    private function throttle(): void
    {
        if ($this->minIntervalMs <= 0 || self::$lastCallAt <= 0.0) {
            return;
        }
        $elapsedMs = (microtime(true) - self::$lastCallAt) * 1000.0;
        $remainingMs = $this->minIntervalMs - $elapsedMs;
        if ($remainingMs > 0) {
            usleep((int) ($remainingMs * 1000));
        }
    }

    public function isCircuitOpen(): bool
    {
        if ($this->failureCount >= $this->cbThreshold) {
            if (time() - $this->circuitOpenedAt < $this->cbTimeout) {
                return true;
            }
            $this->failureCount = 0;
        }
        return false;
    }

    private function recordFailure(): void
    {
        $this->failureCount++;
        if ($this->failureCount >= $this->cbThreshold) {
            $this->circuitOpenedAt = time();
        }
    }

    private function resetFailures(): void
    {
        $this->failureCount = 0;
        $this->circuitOpenedAt = 0;
    }

    private function log(string $message): void
    {
        if ($this->debugLogging && function_exists('fn_log_event')) {
            fn_log_event('fgo_invoicing', 'runtime', ['message' => '[FgoHttpClient] ' . $message]);
        }
    }

    public function getCircuitBreakerTimeout(): int
    {
        return $this->cbTimeout;
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getLastResponseRaw(): ?string
    {
        return $this->lastResponseRaw;
    }

    /** Reset the static throttle timestamp — used by tests only. */
    public static function resetThrottle(): void
    {
        self::$lastCallAt = 0.0;
    }
}
