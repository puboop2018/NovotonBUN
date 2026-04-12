<?php
declare(strict_types=1);
/**
 * Safe JSON decoding with logging for malformed data.
 *
 * Replaces the unguarded pattern:
 *   $data = json_decode($raw, true) ?: [];
 *
 * Usage:
 *   $data = JsonDecoder::decode($raw);              // returns [] on failure
 *   $data = JsonDecoder::decode($raw, 'bookings');  // logs context on failure
 *
 * @package NovotonHolidays
 * @since   3.6.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

class JsonDecoder
{
    /**
     * Decode a JSON string, returning a default on failure and logging the error.
     *
     * @param mixed  $json     The raw JSON string (null/empty/non-string are handled)
     * @param string $context  Human-readable label for log messages (e.g. 'rooms_data', 'priceinfo')
     * @param array<string, mixed>  $default  Value to return on decode failure
     * @return array<string, mixed>
     */
    public static function decode($json, string $context = '', array $default = []): array
    {
        if ($json === null || $json === '' || $json === false) {
            return $default;
        }

        if (!is_string($json)) {
            if (is_array($json)) {
                return $json; // already decoded
            }
            self::logError($context, 'Expected string, got ' . gettype($json), '');
            return $default;
        }

        $decoded = json_decode($json, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            self::logError($context, json_last_error_msg(), substr($json, 0, 200));
            return $default;
        }

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Log a JSON decode error for debugging.
     */
    private static function logError(string $context, string $error, string $snippet): void
    {
        $label = $context ? "JsonDecoder [{$context}]" : 'JsonDecoder';

        fn_log_event('general', 'runtime', [
            'message' => "{$label}: {$error}",
            'snippet' => $snippet,
        ]);
    }
}