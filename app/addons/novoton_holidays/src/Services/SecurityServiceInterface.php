<?php
declare(strict_types=1);
/**
 * Security Service Interface
 *
 * Contract for input validation, CSRF protection, rate limiting,
 * encryption, and secure data handling.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface SecurityServiceInterface
{
    /**
     * Validate booking data.
     *
     * @param array $data Booking data
     * @return array{valid: bool, errors: array}
     */
    public function validateBookingData(array $data): array;

    /**
     * Validate and sanitize search parameters.
     *
     * @param array $params Search parameters
     * @return array Sanitized parameters
     */
    public function validateSearchParams(array $params): array;

    /**
     * Validate and sanitize guest data.
     *
     * @param array $guests Guest data
     * @return array Sanitized guest data
     */
    public function sanitizeGuestData(array $guests): array;

    /**
     * Verify CSRF token.
     *
     * @param string $token Token to verify
     * @return bool Is valid
     */
    public function verifyCsrfToken(string $token): bool;

    /**
     * Generate CSRF token.
     *
     * @return string Token
     */
    public function generateCsrfToken(): string;

    /**
     * Check rate limit.
     *
     * @param string   $key         Rate limit key (e.g., IP, user_id)
     * @param int|null $maxRequests Max requests per window
     * @param int|null $window      Window in seconds
     * @return array{allowed: bool, remaining: int, reset: int}
     */
    public function checkRateLimit(string $key, ?int $maxRequests = null, ?int $window = null): array;

    /**
     * Check booking rate limit (stricter).
     *
     * @param string $identifier User ID or session ID
     * @return bool Is allowed
     */
    public function checkBookingRateLimit(string $identifier): bool;

    /**
     * Encrypt sensitive data.
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data
     */
    public function encrypt(string $data): string;

    /**
     * Decrypt sensitive data.
     *
     * @param string $data Encrypted data
     * @return string|null Decrypted data or null on failure
     */
    public function decrypt(string $data): ?string;

    /**
     * Sanitize output for HTML.
     *
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    public function escapeHtml(string $string): string;

    /**
     * Log security event.
     *
     * @param string $event Event type
     * @param array  $data  Event data
     */
    public function logSecurityEvent(string $event, array $data = []): void;
}
