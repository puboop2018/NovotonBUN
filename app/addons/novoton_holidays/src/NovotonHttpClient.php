<?php
declare(strict_types=1);
/**
 * Novoton HTTP Client
 * Handles HTTP requests, retries with exponential backoff, and circuit breaker.
 *
 * Path: app/addons/novoton_holidays/src/NovotonHttpClient.php
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;

class NovotonHttpClient implements HttpClientInterface
{
    private string $apiUrl;
    private string $apiKey;
    private string $apiId;
    private string $apiUser;
    private string $apiPassword;

    // Retry configuration
    private int $maxRetries;
    private int $retryDelayMs;
    private int $retryMultiplier;

    // Circuit breaker configuration
    private int $circuitBreakerThreshold;
    private int $circuitBreakerTimeout;
    private static int $failureCount = 0;
    private static int $lastFailureTime = 0;
    private static bool $circuitOpen = false;

    // Debug properties
    public int $lastHttpCode = 0;
    public string $lastError = '';
    public string $lastResponseRaw = '';

    public function __construct(array $settings)
    {
        if (empty($settings['api_url'])) {
            throw new \InvalidArgumentException(
                'Novoton API URL not configured — set api_url in addon settings'
            );
        }
        $apiUrl = $settings['api_url'];
        // Preserve scheme if provided in settings, otherwise default to https://
        if (preg_match('#^https?://#', $apiUrl)) {
            $this->apiUrl = $apiUrl;
        } else {
            $this->apiUrl = 'https://' . $apiUrl;
        }

        // Warn when API credentials will be sent over unencrypted HTTP
        if (stripos($this->apiUrl, 'http://') === 0) {
            fn_log_event('general', 'warning', [
                'message' => 'Novoton API URL uses HTTP — API credentials are transmitted unencrypted. '
                    . 'Configure HTTPS in addon settings for production environments.',
                'api_url' => preg_replace('#//.*:.*@#', '//***:***@', $this->apiUrl),
            ]);
        }
        $this->apiKey = $settings['api_key'] ?? '';
        $this->apiId = $settings['api_id'] ?? '';
        $this->apiUser = $settings['api_user'] ?? '';
        $this->apiPassword = $settings['api_password'] ?? '';

        if (empty($this->apiKey) || empty($this->apiUser) || empty($this->apiPassword)) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API credentials not configured — set api_key, api_user, and api_password in addon settings',
                'has_key' => !empty($this->apiKey),
                'has_user' => !empty($this->apiUser),
                'has_password' => !empty($this->apiPassword),
            ]);
        }

        $this->maxRetries = (int)($settings['api_max_retries'] ?? 3);
        $this->retryDelayMs = (int)($settings['api_retry_delay_ms'] ?? 1000);
        $this->retryMultiplier = max(1, (int)($settings['api_retry_multiplier'] ?? 2));
        $this->circuitBreakerThreshold = (int)($settings['circuit_breaker_threshold'] ?? 5);
        $this->circuitBreakerTimeout = (int)($settings['circuit_breaker_timeout'] ?? 60);
    }

    /**
     * @return string API username (for XML request building)
     */
    public function getApiUser(): string
    {
        return $this->apiUser;
    }

    /**
     * @return string API password (for XML request building)
     */
    public function getApiPassword(): string
    {
        return $this->apiPassword;
    }

    /**
     * Send POST request to Novoton API with retry and circuit breaker
     *
     * @param string $function API function name
     * @param string $xml XML request body
     * @param string $lang Language code
     * @return string Raw response body
     * @throws ApiException On circuit breaker open or request failure after retries
     */
    public function sendRequest(string $function, string $xml = '', string $lang = 'UK'): string
    {
        if (!$this->isCircuitClosed()) {
            $secondsUntilRetry = $this->circuitBreakerTimeout - (time() - self::$lastFailureTime);
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API request blocked by circuit breaker',
                'function' => $function,
                'seconds_until_retry' => $secondsUntilRetry
            ]);
            $this->lastError = 'Circuit breaker open - API temporarily unavailable';
            throw ApiException::circuitBreakerOpen($function, $secondsUntilRetry);
        }

        $url = $this->apiUrl . '/index.php';

        $postData = [
            'fn' => $function,
            'key' => $this->apiKey,
            'id' => $this->apiId,
            'xml' => $xml,
            'lang' => $lang
        ];

        $lastError = '';
        $lastHttpCode = 0;
        $response = false;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init();
            if ($ch === false) {
                throw ApiException::requestFailed($function, 'Failed to initialize curl', 0, 1);
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_REFERER, Constants::IMAGE_BASE_URL);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);

            curl_close($ch);

            if (!$lastError && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                $this->recordSuccess();
                break;
            }

            $isRetryable = $this->isRetryableError($lastError, $lastHttpCode);

            if ($isRetryable && $attempt < $this->maxRetries) {
                $delayMs = $this->retryDelayMs * pow($this->retryMultiplier, $attempt - 1);

                fn_log_event('general', 'runtime', [
                    'message' => "Novoton API retry attempt $attempt/$this->maxRetries",
                    'function' => $function,
                    'error' => $lastError,
                    'http_code' => $lastHttpCode,
                    'delay_ms' => $delayMs
                ]);

                usleep($delayMs * 1000);
            } else if (!$isRetryable) {
                break;
            }
        }

        $this->lastHttpCode = $lastHttpCode;
        $this->lastError = $lastError;
        $this->lastResponseRaw = is_string($response) ? $response : '';

        if ($lastError || $lastHttpCode < 200 || $lastHttpCode >= 300) {
            $this->recordFailure();
            $attempts = min($attempt, $this->maxRetries);
            fn_log_event('general', 'runtime', [
                'message' => "Novoton API Error after {$attempts} attempts: {$lastError}",
                'function' => $function,
                'http_code' => $lastHttpCode,
                'circuit_status' => $this->getCircuitStatus()
            ]);
            throw ApiException::requestFailed($function, $lastError, $lastHttpCode, $attempts);
        }

        return is_string($response) ? $response : '';
    }

    /**
     * Send multiple requests in parallel using curl_multi
     *
     * @param array $requests Keyed array: key => ['function' => ..., 'xml' => ..., 'lang' => ...]
     * @param int $concurrency Max simultaneous requests
     * @return array key => raw response string or false
     */
    public function sendBatchRequests(array $requests, int $concurrency = 5): array
    {
        if (empty($requests)) {
            return [];
        }

        if (!$this->isCircuitClosed()) {
            return array_fill_keys(array_keys($requests), false);
        }

        $url = $this->apiUrl . '/index.php';
        $results = [];
        $mh = curl_multi_init();
        if ($mh === false) {
            return array_fill_keys(array_keys($requests), false);
        }

        $chunks = array_chunk($requests, $concurrency, true);

        foreach ($chunks as $chunk) {
            $handles = [];

            foreach ($chunk as $key => $req) {
                $postData = [
                    'fn' => $req['function'],
                    'key' => $this->apiKey,
                    'id' => $this->apiId,
                    'xml' => $req['xml'],
                    'lang' => $req['lang'] ?? 'UK'
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                curl_setopt($ch, CURLOPT_REFERER, Constants::IMAGE_BASE_URL);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                // Enable HTTP/2 multiplexing for better batch throughput
                if (defined('CURL_HTTP_VERSION_2_0')) {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
                }

                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($handles as $key => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);

                if (!$error && $httpCode >= 200 && $httpCode < 300 && !empty($response)) {
                    $this->recordSuccess();
                    $results[$key] = $response;
                } else {
                    $this->recordFailure();
                    $results[$key] = false;
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            usleep(Constants::API_DELAY_LIGHT);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Check if circuit breaker allows requests
     */
    public function isCircuitClosed(): bool
    {
        if (!self::$circuitOpen) {
            return true;
        }

        if (time() - self::$lastFailureTime >= $this->circuitBreakerTimeout) {
            self::$circuitOpen = false;
            return true;
        }

        return false;
    }

    /**
     * Record API failure for circuit breaker
     */
    public function recordFailure(): void
    {
        self::$failureCount++;
        self::$lastFailureTime = time();

        if (self::$failureCount >= $this->circuitBreakerThreshold) {
            self::$circuitOpen = true;
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API circuit breaker OPENED after ' . self::$failureCount . ' failures',
                'threshold' => $this->circuitBreakerThreshold,
                'timeout_seconds' => $this->circuitBreakerTimeout
            ]);
        }
    }

    /**
     * Record API success - reset circuit breaker
     */
    public function recordSuccess(): void
    {
        if (self::$failureCount > 0 || self::$circuitOpen) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton API circuit breaker RESET after success',
                'previous_failures' => self::$failureCount
            ]);
        }
        self::$failureCount = 0;
        self::$circuitOpen = false;
    }

    /**
     * Get circuit breaker status (for monitoring)
     */
    public function getCircuitStatus(): array
    {
        return [
            'is_open' => self::$circuitOpen,
            'failure_count' => self::$failureCount,
            'threshold' => $this->circuitBreakerThreshold,
            'last_failure' => self::$lastFailureTime > 0 ? date('Y-m-d H:i:s', self::$lastFailureTime) : null,
            'timeout_seconds' => $this->circuitBreakerTimeout,
            'seconds_until_retry' => self::$circuitOpen ? max(0, $this->circuitBreakerTimeout - (time() - self::$lastFailureTime)) : 0
        ];
    }

    /**
     * Manually reset circuit breaker (for admin use)
     */
    public function resetCircuitBreaker(): void
    {
        $wasOpen = self::$circuitOpen;
        $previousFailures = self::$failureCount;

        self::$failureCount = 0;
        self::$lastFailureTime = 0;
        self::$circuitOpen = false;

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton API circuit breaker manually reset',
            'was_open' => $wasOpen,
            'previous_failures' => $previousFailures
        ]);
    }

    /**
     * Determine if an error is retryable
     */
    private function isRetryableError(string $error, int $httpCode): bool
    {
        $retryableErrors = [
            'Connection timed out',
            'Connection refused',
            'Could not resolve host',
            'Operation timed out',
            'SSL connection timeout',
            'Network is unreachable',
            'Empty reply from server'
        ];

        foreach ($retryableErrors as $retryable) {
            if (stripos($error, $retryable) !== false) {
                return true;
            }
        }

        if ($httpCode >= 500 && $httpCode < 600) {
            return true;
        }

        if ($httpCode === 429) {
            return true;
        }

        if ($httpCode === 0 && !empty($error)) {
            return true;
        }

        return false;
    }
}
