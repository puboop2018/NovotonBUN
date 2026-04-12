<?php
declare(strict_types=1);
/**
 * HTTP Client Interface
 *
 * Contract for HTTP communication with the Novoton API.
 * Covers request sending, batch operations, and circuit breaker management.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays;

interface HttpClientInterface
{
    /**
     * Get API username for XML request building.
     *
     * @return string
     */
    public function getApiUser(): string;

    /**
     * Get API password for XML request building.
     *
     * @return string
     */
    public function getApiPassword(): string;

    /**
     * Send POST request to Novoton API with retry and circuit breaker.
     *
     * @param string $function API function name
     * @param string $xml      XML request body
     * @param string $lang     Language code
     * @return string Raw response body
     * @throws \Tygh\Addons\NovotonHolidays\Exceptions\ApiException
     */
    public function sendRequest(string $function, string $xml = '', string $lang = 'UK'): string;

    /**
     * Send multiple requests in parallel using curl_multi.
     *
     * @param array<string, mixed> $requests    Keyed array: key => ['function' => ..., 'xml' => ..., 'lang' => ...]
     * @param int   $concurrency Max simultaneous requests
     * @return array key => raw response string or false
     */
    public function sendBatchRequests(array $requests, int $concurrency = 5): array;

    /**
     * Check if circuit breaker allows requests.
     *
     * @return bool
     */
    public function isCircuitClosed(): bool;

    /**
     * Record API failure for circuit breaker.
     */
    public function recordFailure(): void;

    /**
     * Record API success — reset circuit breaker.
     */
    public function recordSuccess(): void;

    /**
     * Get circuit breaker status for monitoring.
     *
     * @return array{is_open: bool, failure_count: int, threshold: int, last_failure: string|null, timeout_seconds: int, seconds_until_retry: int}
     */
    public function getCircuitStatus(): array;

    /**
     * Manually reset circuit breaker.
     */
    public function resetCircuitBreaker(): void;
}
