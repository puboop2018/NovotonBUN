<?php

declare(strict_types=1);

/**
 * Adult-Only Hotel Detector
 *
 * Detects whether a hotel is adults-only by scanning the hotel name
 * for common patterns like "Adults Only", "Adult Only", "18+", "16+", etc.
 *
 * The Novoton API does not provide a structured field for this property,
 * so text-based detection from the hotel name is the only available signal.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Api;

class AdultOnlyDetector
{
    /**
     * Patterns that indicate an adults-only property.
     * Ordered by specificity (most explicit first).
     */
    private const array PATTERNS = [
        '/\bADULTS?\s*ONLY\b/i',
        '/\bONLY\s+ADULTS?\b/i',
        '/\b(?:16|18)\+/i',
        '/\bADULT\s+HOTEL\b/i',
        '/\bADULT\s+RESORT\b/i',
        '/\bNO\s+CHILD(?:REN)?\b/i',
    ];

    /**
     * Detect if a hotel is adults-only from its name.
     *
     * @param string $hotelName Hotel name from API
     * @return bool True if the hotel name indicates adults-only
     */
    public function detect(string $hotelName): bool
    {
        $hotelName = trim($hotelName);
        if ($hotelName === '') {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $hotelName)) {
                return true;
            }
        }

        return false;
    }
}
