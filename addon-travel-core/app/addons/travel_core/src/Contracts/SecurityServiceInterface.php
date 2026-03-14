<?php
declare(strict_types=1);
/**
 * Security Service Interface
 *
 * Contract for booking-related security validation.
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
     * @param array $data Booking data
     * @return array Validation errors (empty if valid)
     */
    public function validateBookingData(array $data): array;

    /**
     * Validate search parameters.
     *
     * @param array $params Search parameters
     * @return array Validation errors (empty if valid)
     */
    public function validateSearchParams(array $params): array;

    /**
     * Check booking rate limit for the current session/user.
     *
     * @param string $action Action type (e.g. 'search', 'book')
     * @return bool True if within limits
     */
    public function checkBookingRateLimit(string $action): bool;

    /**
     * Sanitize guest data for safe storage.
     *
     * @param array $guests Raw guest data
     * @return array Sanitized guest data
     */
    public function sanitizeGuestData(array $guests): array;
}
