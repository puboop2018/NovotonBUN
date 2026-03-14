<?php
declare(strict_types=1);
/**
 * Travel Core Validation Helper
 *
 * Common validation methods shared across all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\TravelConstants;

class ValidationHelper
{
    /**
     * Validate date format (YYYY-MM-DD).
     */
    public static function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        list($year, $month, $day) = explode('-', $date);
        return checkdate((int)$month, (int)$day, (int)$year);
    }

    /**
     * Validate date is not in the past.
     */
    public static function isFutureDate(string $date): bool
    {
        if (!self::isValidDate($date)) {
            return false;
        }
        return strtotime($date) >= strtotime('today');
    }

    /**
     * Validate check-out is after check-in.
     */
    public static function isValidDateRange(string $checkIn, string $checkOut): bool
    {
        if (!self::isValidDate($checkIn) || !self::isValidDate($checkOut)) {
            return false;
        }
        return strtotime($checkOut) > strtotime($checkIn);
    }

    /**
     * Calculate nights between dates.
     */
    public static function calculateNights(string $checkIn, string $checkOut): int
    {
        if (!self::isValidDateRange($checkIn, $checkOut)) {
            return 0;
        }

        $diff = strtotime($checkOut) - strtotime($checkIn);
        return (int)floor($diff / (60 * 60 * 24));
    }

    /**
     * Validate email address.
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number (basic).
     */
    public static function isValidPhone(string $phone): bool
    {
        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $phone);
        return preg_match('/^\+?\d{7,15}$/', $cleaned) === 1;
    }

    /**
     * Validate name (letters, spaces, hyphens, apostrophes).
     */
    public static function isValidName(string $name, int $minLength = 2, int $maxLength = 100): bool
    {
        $length = mb_strlen($name);

        if ($length < $minLength || $length > $maxLength) {
            return false;
        }

        return preg_match('/^[\p{L}\s\'-]+$/u', $name) === 1;
    }

    /**
     * Validate hotel ID format.
     */
    public static function isValidHotelId(string $hotelId): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $hotelId) === 1;
    }

    /**
     * Validate adults count.
     */
    public static function isValidAdults(int $adults): bool
    {
        return $adults >= 1 && $adults <= TravelConstants::MAX_ADULTS;
    }

    /**
     * Validate children count.
     */
    public static function isValidChildren(int $children): bool
    {
        return $children >= 0 && $children <= TravelConstants::MAX_CHILDREN;
    }

    /**
     * Validate child age.
     */
    public static function isValidChildAge(int $age): bool
    {
        return $age >= TravelConstants::MIN_CHILD_AGE && $age <= TravelConstants::MAX_CHILD_AGE;
    }

    /**
     * Validate rooms count.
     */
    public static function isValidRooms(int $rooms): bool
    {
        return $rooms >= 1 && $rooms <= TravelConstants::MAX_ROOMS;
    }

    /**
     * Validate nights count.
     */
    public static function isValidNights(int $nights): bool
    {
        return $nights >= 1 && $nights <= TravelConstants::MAX_NIGHTS;
    }

    /**
     * Validate price.
     */
    public static function isValidPrice(float $price): bool
    {
        return $price >= 0 && $price <= 1000000;
    }

    /**
     * Validate currency code.
     */
    public static function isValidCurrency(string $currency): bool
    {
        $currencies = \Tygh\Registry::get('currencies');
        if (!empty($currencies)) {
            return isset($currencies[strtoupper($currency)]);
        }
        $valid = ['EUR', 'USD', 'GBP', 'BGN', 'RON'];
        return in_array(strtoupper($currency), $valid);
    }

    /**
     * Validate booking status.
     */
    public static function isValidBookingStatus(string $status): bool
    {
        $valid = [
            TravelConstants::STATUS_PENDING,
            TravelConstants::STATUS_CONFIRMED,
            TravelConstants::STATUS_CANCELLED,
            TravelConstants::STATUS_COMPLETED,
            TravelConstants::STATUS_FAILED,
            TravelConstants::STATUS_ASK,
            TravelConstants::STATUS_WAITING,
        ];
        return in_array(strtolower($status), $valid);
    }

    /**
     * Sanitize string for safe output.
     */
    public static function sanitizeString(string $string, int $maxLength = 255): string
    {
        $string = strip_tags($string);
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr($string, 0, $maxLength);
    }

    /**
     * Sanitize name.
     */
    public static function sanitizeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\s\'-]/u', '', $name);
        return mb_substr(trim($name), 0, 100);
    }

    /**
     * Sanitize email.
     */
    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim(strtolower($email)), FILTER_SANITIZE_EMAIL);
    }

    /**
     * Sanitize phone.
     */
    public static function sanitizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone);
    }

    /**
     * Parse and validate children ages.
     *
     * @param mixed $ages Ages (string comma-separated or array)
     * @param int $expectedCount Expected number of children
     * @return array Validated ages array
     */
    public static function parseChildrenAges($ages, int $expectedCount = 0): array
    {
        if (empty($ages)) {
            return [];
        }

        if (is_string($ages)) {
            $ages = array_map('trim', explode(',', $ages));
        }

        $validated = [];
        foreach ($ages as $age) {
            $age = (int)$age;
            if (self::isValidChildAge($age)) {
                $validated[] = $age;
            }
        }

        while (count($validated) < $expectedCount) {
            $validated[] = 0;
        }

        return array_slice($validated, 0, $expectedCount ?: count($validated));
    }

    /**
     * Format date for display.
     */
    public static function formatDate(string $date, string $format = 'd M Y', string $locale = 'ro_RO'): string
    {
        if (!self::isValidDate($date)) {
            return $date;
        }

        $timestamp = strtotime($date);

        if (class_exists('IntlDateFormatter')) {
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::NONE
            );
            return $formatter->format($timestamp);
        }

        return date($format, $timestamp);
    }

    /**
     * Format price for display.
     */
    public static function formatPrice(float $price, ?string $currency = null): string
    {
        if ($currency === null) {
            $currency = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'EUR';
        }

        $currencies = \Tygh\Registry::get('currencies');

        if (!empty($currencies[$currency])) {
            $curr = $currencies[$currency];
            $decimals = isset($curr['decimals']) ? (int)$curr['decimals'] : 2;
            $dec_sign = $curr['decimals_separator'] ?? ',';
            $ths_sign = $curr['thousands_separator'] ?? ' ';
            $symbol = $curr['symbol'] ?? $currency;
            $after = !empty($curr['after']) && $curr['after'] === 'Y';

            $formatted = number_format($price, $decimals, $dec_sign, $ths_sign);

            return $after ? $formatted . ' ' . $symbol : $symbol . ' ' . $formatted;
        }

        $formatted = number_format($price, 2, ',', ' ');
        return $formatted . ' ' . $currency;
    }
}
