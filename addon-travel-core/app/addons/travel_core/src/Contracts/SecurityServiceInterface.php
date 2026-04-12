<?php
declare(strict_types=1);
/**
 * Security Service Interface
 *
 * Core contract for booking-related security validation, rate limiting,
 * and data sanitization. All travel providers should implement this.
 *
 * Provider addons may extend this interface with additional methods
 * (e.g. CSRF, encryption) specific to their security needs.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface SecurityServiceInterface
{
    /**
     * Validate booking data before submission.
     *
     * @param array<string, mixed> $data Booking data
     * @return array{valid: bool, errors: array} Validation result
     */
    public function validateBookingData(array $data): array;

    /**
     * Validate and sanitize search parameters.
     *
     * @param array<string, mixed> $params Raw search parameters
     * @return array Sanitized parameters (provider decides format)
     */
    public function validateSearchParams(array $params): array;

    /**
     * Check booking rate limit for the given identifier.
     *
     * @param string $identifier Rate limit key (user ID, session ID, or action type)
     * @return bool True if within limits, false if rate-limited
     */
    public function checkBookingRateLimit(string $identifier): bool;

    /**
     * Sanitize guest data for safe storage.
     *
     * @param array<string, mixed> $guests Raw guest data
     * @return array Sanitized guest data
     */
    public function sanitizeGuestData(array $guests): array;
}
