<?php
/**
 * Novoton Validation Helper
 * 
 * Common validation methods used throughout the addon.
 * Provides consistent validation patterns and error messages.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class ValidationHelper
{
    /**
     * Validate date format (YYYY-MM-DD)
     * 
     * @param string $date Date string
     * @return bool Is valid
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
     * Validate date is not in the past
     * 
     * @param string $date Date string (YYYY-MM-DD)
     * @return bool Is future or today
     */
    public static function isFutureDate(string $date): bool
    {
        if (!self::isValidDate($date)) {
            return false;
        }
        return strtotime($date) >= strtotime('today');
    }
    
    /**
     * Validate check-out is after check-in
     * 
     * @param string $checkIn Check-in date
     * @param string $checkOut Check-out date
     * @return bool Is valid range
     */
    public static function isValidDateRange(string $checkIn, string $checkOut): bool
    {
        if (!self::isValidDate($checkIn) || !self::isValidDate($checkOut)) {
            return false;
        }
        return strtotime($checkOut) > strtotime($checkIn);
    }
    
    /**
     * Calculate nights between dates
     * 
     * @param string $checkIn Check-in date
     * @param string $checkOut Check-out date
     * @return int Number of nights (0 if invalid)
     */
    public static function calculateNights(string $checkIn, string $checkOut): int
    {
        if (!self::isValidDateRange($checkIn, $checkOut)) {
            return 0;
        }
        
        $diff = strtotime($checkOut) - strtotime($checkIn);
        return (int) floor($diff / (60 * 60 * 24));
    }
    
    /**
     * Validate email address
     * 
     * @param string $email Email address
     * @return bool Is valid
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate phone number (basic)
     * 
     * @param string $phone Phone number
     * @return bool Is valid
     */
    public static function isValidPhone(string $phone): bool
    {
        // Remove common formatting characters
        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $phone);
        
        // Must be 7-15 digits, optionally starting with +
        return preg_match('/^\+?\d{7,15}$/', $cleaned) === 1;
    }
    
    /**
     * Validate name (letters, spaces, hyphens, apostrophes)
     * 
     * @param string $name Name
     * @param int $minLength Minimum length
     * @param int $maxLength Maximum length
     * @return bool Is valid
     */
    public static function isValidName(string $name, int $minLength = 2, int $maxLength = 100): bool
    {
        $length = mb_strlen($name);
        
        if ($length < $minLength || $length > $maxLength) {
            return false;
        }
        
        // Allow letters (including accented), spaces, hyphens, apostrophes
        return preg_match('/^[\p{L}\s\'-]+$/u', $name) === 1;
    }
    
    /**
     * Validate hotel ID format
     * 
     * @param string $hotelId Hotel ID
     * @return bool Is valid
     */
    public static function isValidHotelId(string $hotelId): bool
    {
        // Alphanumeric, hyphens, underscores, 1-50 chars
        return preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $hotelId) === 1;
    }
    
    /**
     * Validate adults count
     * 
     * @param int $adults Number of adults
     * @return bool Is valid
     */
    public static function isValidAdults(int $adults): bool
    {
        return $adults >= 1 && $adults <= 10;
    }
    
    /**
     * Validate children count
     * 
     * @param int $children Number of children
     * @return bool Is valid
     */
    public static function isValidChildren(int $children): bool
    {
        return $children >= 0 && $children <= 6;
    }
    
    /**
     * Validate child age
     * 
     * @param int $age Child age
     * @return bool Is valid
     */
    public static function isValidChildAge(int $age): bool
    {
        return $age >= 0 && $age <= 17;
    }
    
    /**
     * Validate rooms count
     * 
     * @param int $rooms Number of rooms
     * @return bool Is valid
     */
    public static function isValidRooms(int $rooms): bool
    {
        return $rooms >= 1 && $rooms <= 5;
    }
    
    /**
     * Validate nights count
     * 
     * @param int $nights Number of nights
     * @return bool Is valid
     */
    public static function isValidNights(int $nights): bool
    {
        return $nights >= 1 && $nights <= 30;
    }
    
    /**
     * Validate price
     * 
     * @param float $price Price value
     * @return bool Is valid
     */
    public static function isValidPrice(float $price): bool
    {
        return $price >= 0 && $price <= 1000000;
    }
    
    /**
     * Validate currency code
     * 
     * @param string $currency Currency code
     * @return bool Is valid
     */
    public static function isValidCurrency(string $currency): bool
    {
        $valid = ['EUR', 'USD', 'GBP', 'BGN', 'RON'];
        return in_array(strtoupper($currency), $valid);
    }
    
    /**
     * Validate booking status
     * 
     * @param string $status Status
     * @return bool Is valid
     */
    public static function isValidBookingStatus(string $status): bool
    {
        $valid = ['pending', 'confirmed', 'cancelled', 'completed', 'failed', 'ask', 'waiting'];
        return in_array(strtolower($status), $valid);
    }
    
    /**
     * Sanitize string for safe output
     * 
     * @param string $string Input string
     * @param int $maxLength Maximum length
     * @return string Sanitized string
     */
    public static function sanitizeString(string $string, int $maxLength = 255): string
    {
        $string = strip_tags($string);
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr($string, 0, $maxLength);
    }
    
    /**
     * Sanitize name
     * 
     * @param string $name Name
     * @return string Sanitized name
     */
    public static function sanitizeName(string $name): string
    {
        // Remove anything that's not a letter, space, hyphen, or apostrophe
        $name = preg_replace('/[^\p{L}\s\'-]/u', '', $name);
        return mb_substr(trim($name), 0, 100);
    }
    
    /**
     * Sanitize email
     * 
     * @param string $email Email
     * @return string Sanitized email
     */
    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim(strtolower($email)), FILTER_SANITIZE_EMAIL);
    }
    
    /**
     * Sanitize phone
     * 
     * @param string $phone Phone
     * @return string Sanitized phone
     */
    public static function sanitizePhone(string $phone): string
    {
        // Keep only digits and + sign
        return preg_replace('/[^\d+]/', '', $phone);
    }
    
    /**
     * Parse and validate children ages
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
            $age = (int) $age;
            if (self::isValidChildAge($age)) {
                $validated[] = $age;
            }
        }
        
        // Pad with 0s if needed
        while (count($validated) < $expectedCount) {
            $validated[] = 0;
        }
        
        return array_slice($validated, 0, $expectedCount ?: count($validated));
    }
    
    /**
     * Format date for display
     * 
     * @param string $date Date (YYYY-MM-DD)
     * @param string $format Output format
     * @param string $locale Locale
     * @return string Formatted date
     */
    public static function formatDate(string $date, string $format = 'd M Y', string $locale = 'ro_RO'): string
    {
        if (!self::isValidDate($date)) {
            return $date;
        }
        
        $timestamp = strtotime($date);
        
        // Use IntlDateFormatter if available for better locale support
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
     * Format price for display
     * 
     * @param float $price Price
     * @param string $currency Currency code
     * @return string Formatted price
     */
    public static function formatPrice(float $price, ?string $currency = null): string
    {
        // Use CS-Cart's primary currency as default
        if ($currency === null) {
            $currency = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'EUR';
        }
        
        // Try to use CS-Cart's currency settings
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
        
        // Fallback if CS-Cart currencies not available
        $formatted = number_format($price, 2, ',', ' ');
        
        return $formatted . ' ' . $currency;
    }
}
