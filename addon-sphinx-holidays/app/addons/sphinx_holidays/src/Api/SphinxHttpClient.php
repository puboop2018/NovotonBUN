<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

/**
 * HTTP client for the Sphinx REST API.
 *
 * Handles Bearer token authentication, rate limiting, retry logic with
 * exponential backoff, and circuit breaker pattern.
 */
class SphinxHttpClient
{
    private const int CURL_TIMEOUT = 30;
    private const int CURL_CONNECT_TIMEOUT = 10;
    private const int RATE_LIMIT_PAUSE_DEFAULT = 5;
    private const int RATE_LIMIT_MAX_WAIT = 120;
    private const int RATE_LIMIT_FALLBACK_WAIT = 60;

    private string $baseUrl;
    private string $apiKey;
    private int $maxRetries;
    private int $retryDelayMs;
    private float $retryMultiplier;
    private int $cbThreshold;
    private int $cbTimeout;
    private bool $debugLogging;

    private int $failureCount = 0;
    private int $circuitOpenedAt = 0;

    private int $lastHttpCode = 0;
    private string $lastError = '';
    private ?string $lastResponseRaw = null;
    private ?int $rateLimitLimit = null;
    private ?int $rateLimitRemaining = null;
    private ?int $rateLimitReset = null;
    private ?int $retryAfter = null;
    private int $rateLimitHitCount = 0;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        int $maxRetries = 3,
        int $retryDelayMs = 500,
        float $retryMultiplier = 2.0,
        int $cbThreshold = 5,
        int $cbTimeout = 60,
        bool $debugLogging = false,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->maxRetries = $maxRetries;
        $this->retryDelayMs = $retryDelayMs;
        $this->retryMultiplier = $retryMultiplier;
        $this->cbThreshold = $cbThreshold;
        $this->cbTimeout = $cbTimeout;
        $this->debugLogging = $debugLogging;
    }

    /**
     * Send a GET request.
     *
     * @param string $endpoint API endpoint (e.g., '/api/v1/static/destinations')
     * @param array<string, mixed> $query Query parameters
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function get(string $endpoint, array $query = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url);
    }

    /**
     * Send a POST request.
     *
     * @param string $endpoint API endpoint
     * @param array<string, mixed> $data Request body (will be JSON-encoded)
     * @return array<string, mixed>|null Decoded JSON response or null on failure
     */
    public function post(string $endpoint, array $data = []): ?array
    {
        $url = $this->baseUrl . $endpoint;
        return $this->request('POST', $url, $data);
    }

    /**
     * Execute an HTTP request with retry and circuit breaker logic.
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, ?array $body = null): ?array
    {
        // Circuit breaker check
        if ($this->isCircuitOpen()) {
            $this->lastError = 'Circuit breaker is open — API calls suspended for ' . $this->cbTimeout . 's';
            return null;
        }

        $attempt = 0;
        $delayMs = $this->retryDelayMs;

        while ($attempt <= $this->maxRetries) {
            // Proactive throttle: pause when approaching rate limit
            if ($this->rateLimitRemaining !== null && $this->rateLimitRemaining <= 2) {
                $pauseSeconds = ($this->rateLimitReset !== null)
                    ? max(1, min(30, $this->rateLimitReset - time()))
                    : self::RATE_LIMIT_PAUSE_DEFAULT;
                $this->log("Approaching rate limit (remaining={$this->rateLimitRemaining}). Pausing {$pauseSeconds}s.");
                sleep($pauseSeconds);
            }

            // Reset per-attempt header state
            $this->rateLimitLimit = null;
            $this->rateLimitRemaining = null;
            $this->rateLimitReset = null;
            $this->retryAfter = null;

            $ch = curl_init($url);

            $headers = [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ];

            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HEADERFUNCTION => [$this, 'parseResponseHeaders'],
            ];

            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body);
                }
            }

            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $this->lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->lastResponseRaw = is_string($response) ? $response : null;

            // cURL error (network failure)
            if ($response === false) {
                $this->lastError = "cURL error: {$curlError}";
                $this->recordFailure();
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    usleep($delayMs * 1000);
                    $delayMs = (int) ($delayMs * $this->retryMultiplier);
                }
                continue;
            }

            // Rate limited — wait and retry
            if ($this->lastHttpCode === 429) {
                $this->rateLimitHitCount++;
                // Priority: Retry-After (seconds) > X-RateLimit-Reset (timestamp) > fallback 60s
                // Properties are set by curl HEADERFUNCTION callback (parseResponseHeaders)
                /** @var int|null $retryAfter */
                $retryAfter = $this->retryAfter;
                /** @var int|null $rateLimitReset */
                $rateLimitReset = $this->rateLimitReset;
                if ($retryAfter !== null && $retryAfter > 0) {
                    $waitSeconds = $retryAfter;
                } elseif ($rateLimitReset !== null) {
                    $waitSeconds = max(1, $rateLimitReset - time());
                } else {
                    $waitSeconds = self::RATE_LIMIT_FALLBACK_WAIT;
                }
                $waitSeconds = min($waitSeconds, self::RATE_LIMIT_MAX_WAIT);
                $this->lastError = "Rate limited. Waiting {$waitSeconds}s.";
                if ($this->debugLogging) {
                    fn_log_event('general', 'runtime', ['message' => "[SphinxHttpClient] Rate limited on {$method} {$url}. Waiting {$waitSeconds}s. Remaining: {$this->rateLimitRemaining}, Limit: {$this->rateLimitLimit}"]);
                }
                sleep($waitSeconds);
                $attempt++;
                continue;
            }

            // Server error — retry
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

            // Client error (4xx except 429) — do not retry
            if ($this->lastHttpCode >= 400) {
                $this->lastError = "Client error HTTP {$this->lastHttpCode}: {$response}";
                return null;
            }

            // Success
            $this->resetFailures();
            $decoded = json_decode((string) $response, true);
            if (!is_array($decoded)) {
                $this->lastError = 'JSON decode error: ' . (json_last_error_msg() ?: 'response is not a JSON object/array');
                return null;
            }

            return $decoded;
        }

        // All retries exhausted
        return null;
    }

    /**
     * Parse rate limit headers from response.
     *
     * Note: called via `CURLOPT_HEADERFUNCTION => [$this, 'parseResponseHeaders']`,
     * which PHPMD's UnusedPrivateMethod sniff cannot detect.
     */
    private function parseResponseHeaders(\CurlHandle $ch, string $header): int
    {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($name === 'x-ratelimit-limit') {
                $this->rateLimitLimit = (int) $value;
            } elseif ($name === 'x-ratelimit-remaining') {
                $this->rateLimitRemaining = (int) $value;
            } elseif ($name === 'x-ratelimit-reset') {
                $this->rateLimitReset = (int) $value;
            } elseif ($name === 'retry-after') {
                $this->retryAfter = (int) $value;
            }
        }
        return $len;
    }

    public function isCircuitOpen(): bool
    {
        if ($this->failureCount >= $this->cbThreshold) {
            if (time() - $this->circuitOpenedAt < $this->cbTimeout) {
                return true;
            }
            // Half-open: reset and allow one request through
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
        if ($this->debugLogging) {
            fn_log_event('general', 'runtime', ['message' => '[SphinxHttpClient] ' . $message]);
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
    public function getRateLimitLimit(): ?int
    {
        return $this->rateLimitLimit;
    }
    public function getRateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }
    public function getRateLimitReset(): ?int
    {
        return $this->rateLimitReset;
    }
    public function getRateLimitHitCount(): int
    {
        return $this->rateLimitHitCount;
    }

    /**
     * Get full rate limit state for monitoring/logging.
     * @return array<string, mixed>
     */
    public function getRateLimitState(): array
    {
        return [
            'limit' => $this->rateLimitLimit,
            'remaining' => $this->rateLimitRemaining,
            'reset' => $this->rateLimitReset,
            'reset_in' => $this->rateLimitReset !== null ? max(0, $this->rateLimitReset - time()) : null,
        ];
    }
}
