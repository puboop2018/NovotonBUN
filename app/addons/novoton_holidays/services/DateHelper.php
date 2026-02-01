<?php
/**
 * Novoton Date Helper
 * 
 * Date formatting and calculation utilities with Romanian locale support.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

class DateHelper
{
    /** @var array Romanian month names */
    private static $roMonths = [
        1 => 'ianuarie', 2 => 'februarie', 3 => 'martie', 4 => 'aprilie',
        5 => 'mai', 6 => 'iunie', 7 => 'iulie', 8 => 'august',
        9 => 'septembrie', 10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie'
    ];
    
    /** @var array Romanian short month names */
    private static $roMonthsShort = [
        1 => 'ian', 2 => 'feb', 3 => 'mar', 4 => 'apr',
        5 => 'mai', 6 => 'iun', 7 => 'iul', 8 => 'aug',
        9 => 'sep', 10 => 'oct', 11 => 'noi', 12 => 'dec'
    ];
    
    /** @var array Romanian day names */
    private static $roDays = [
        0 => 'duminică', 1 => 'luni', 2 => 'marți', 3 => 'miercuri',
        4 => 'joi', 5 => 'vineri', 6 => 'sâmbătă'
    ];
    
    /**
     * Format date in Romanian
     * 
     * @param string $date Date (YYYY-MM-DD)
     * @param string $format Format: 'full', 'medium', 'short', 'day_month'
     * @return string Formatted date
     */
    public static function formatRomanian(string $date, string $format = 'medium'): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }
        
        $day = (int) date('j', $timestamp);
        $month = (int) date('n', $timestamp);
        $year = date('Y', $timestamp);
        $dayOfWeek = (int) date('w', $timestamp);
        
        switch ($format) {
            case 'full':
                // "luni, 15 ianuarie 2025"
                return self::$roDays[$dayOfWeek] . ', ' . $day . ' ' . self::$roMonths[$month] . ' ' . $year;
            
            case 'medium':
                // "15 ianuarie 2025"
                return $day . ' ' . self::$roMonths[$month] . ' ' . $year;
            
            case 'short':
                // "15 ian 2025"
                return $day . ' ' . self::$roMonthsShort[$month] . ' ' . $year;
            
            case 'day_month':
                // "15 ianuarie"
                return $day . ' ' . self::$roMonths[$month];
            
            case 'day_month_short':
                // "15 ian"
                return $day . ' ' . self::$roMonthsShort[$month];
            
            default:
                return date('d.m.Y', $timestamp);
        }
    }
    
    /**
     * Format date range in Romanian
     * 
     * @param string $checkIn Check-in date
     * @param string $checkOut Check-out date
     * @return string Formatted range
     */
    public static function formatRangeRomanian(string $checkIn, string $checkOut): string
    {
        $inTimestamp = strtotime($checkIn);
        $outTimestamp = strtotime($checkOut);
        
        if ($inTimestamp === false || $outTimestamp === false) {
            return $checkIn . ' - ' . $checkOut;
        }
        
        $inMonth = (int) date('n', $inTimestamp);
        $outMonth = (int) date('n', $outTimestamp);
        $inYear = date('Y', $inTimestamp);
        $outYear = date('Y', $outTimestamp);
        
        $inDay = (int) date('j', $inTimestamp);
        $outDay = (int) date('j', $outTimestamp);
        
        // Same month and year: "15 - 22 ianuarie 2025"
        if ($inMonth === $outMonth && $inYear === $outYear) {
            return $inDay . ' - ' . $outDay . ' ' . self::$roMonths[$outMonth] . ' ' . $outYear;
        }
        
        // Same year: "28 ianuarie - 4 februarie 2025"
        if ($inYear === $outYear) {
            return $inDay . ' ' . self::$roMonths[$inMonth] . ' - ' . 
                   $outDay . ' ' . self::$roMonths[$outMonth] . ' ' . $outYear;
        }
        
        // Different years: full format for both
        return self::formatRomanian($checkIn, 'medium') . ' - ' . self::formatRomanian($checkOut, 'medium');
    }
    
    /**
     * Get nights text in Romanian (with proper plural)
     * 
     * @param int $nights Number of nights
     * @return string "X noapte" or "X nopți"
     */
    public static function nightsTextRomanian(int $nights): string
    {
        if ($nights === 1) {
            return '1 noapte';
        }
        
        if ($nights >= 2 && $nights <= 19) {
            return $nights . ' nopți';
        }
        
        return $nights . ' de nopți';
    }
    
    /**
     * Calculate nights between dates
     * 
     * @param string $checkIn Check-in date
     * @param string $checkOut Check-out date
     * @return int Number of nights
     */
    public static function calculateNights(string $checkIn, string $checkOut): int
    {
        $diff = strtotime($checkOut) - strtotime($checkIn);
        return max(0, (int) floor($diff / 86400));
    }
    
    /**
     * Get check-out date from check-in and nights
     * 
     * @param string $checkIn Check-in date
     * @param int $nights Number of nights
     * @return string Check-out date
     */
    public static function getCheckOutDate(string $checkIn, int $nights): string
    {
        return date('Y-m-d', strtotime($checkIn . ' + ' . $nights . ' days'));
    }
    
    /**
     * Check if date is in the past
     * 
     * @param string $date Date
     * @return bool Is past
     */
    public static function isPast(string $date): bool
    {
        return strtotime($date) < strtotime('today');
    }
    
    /**
     * Check if date is today
     * 
     * @param string $date Date
     * @return bool Is today
     */
    public static function isToday(string $date): bool
    {
        return date('Y-m-d', strtotime($date)) === date('Y-m-d');
    }
    
    /**
     * Get minimum check-in date (today or tomorrow based on cutoff)
     * 
     * @param int $hoursCutoff Hours before midnight to switch to next day
     * @return string Minimum check-in date
     */
    public static function getMinCheckIn(int $hoursCutoff = 0): string
    {
        $now = time();
        $today = strtotime('today');
        $hoursRemaining = ($today + 86400 - $now) / 3600;
        
        if ($hoursRemaining < $hoursCutoff) {
            return date('Y-m-d', strtotime('tomorrow'));
        }
        
        return date('Y-m-d');
    }
    
    /**
     * Parse date from various formats
     * 
     * @param string $date Date string
     * @return string|null Date in YYYY-MM-DD or null
     */
    public static function parseDate(string $date): ?string
    {
        // Already in correct format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // DD/MM/YYYY or DD.MM.YYYY
        if (preg_match('/^(\d{2})[\/\.](\d{2})[\/\.](\d{4})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        
        // Try strtotime
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }
    
    /**
     * Get season for date (based on Novoton seasons)
     * 
     * @param string $date Date
     * @param array $seasons Array of seasons with date_from/date_to
     * @return int|null Season number or null
     */
    public static function getSeasonForDate(string $date, array $seasons): ?int
    {
        $timestamp = strtotime($date);
        
        foreach ($seasons as $season) {
            $from = strtotime($season['date_from']);
            $to = strtotime($season['date_to']);
            
            if ($timestamp >= $from && $timestamp <= $to) {
                return (int) $season['season_number'];
            }
        }
        
        return null;
    }
    
    /**
     * Format API date (for Novoton API)
     * 
     * @param string $date Date
     * @return string Date in DD.MM.YYYY format
     */
    public static function formatForApi(string $date): string
    {
        return date('d.m.Y', strtotime($date));
    }
    
    /**
     * Parse API date (from Novoton API)
     * 
     * @param string $date Date in DD.MM.YYYY format
     * @return string Date in YYYY-MM-DD format
     */
    public static function parseFromApi(string $date): string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return $date;
    }
}
