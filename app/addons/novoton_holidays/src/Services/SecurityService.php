<?php
declare(strict_types=1);
/**
 * Novoton Security Service
 * 
 * Handles input validation, CSRF protection, rate limiting,
 * and secure data handling for the addon.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;

class SecurityService implements SecurityServiceInterface
{
    /** @var int Rate limit window in seconds */
    private const RATE_LIMIT_WINDOW = 60;
    
    /** @var int Max requests per window (per IP for API calls) */
    private const RATE_LIMIT_MAX = 100;
    
    /** @var int Max booking attempts per hour (very high - essentially disabled) */
    private const BOOKING_LIMIT_HOUR = 500;
    
    /**
     * Validate booking data
     * 
     * @param array $data Booking data
     * @return array [valid => bool, errors => array]
     */
    public function validateBookingData(array $data): array
    {
        $errors = [];
        
        // Required fields
        $required = ['hotel_id', 'check_in', 'check_out', 'adults'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate hotel_id format
        if (!empty($data['hotel_id']) && !$this->isValidHotelId($data['hotel_id'])) {
            $errors[] = 'Invalid hotel ID format';
        }
        
        // Validate dates
        if (!empty($data['check_in'])) {
            if (!$this->isValidDate($data['check_in'])) {
                $errors[] = 'Invalid check-in date format';
            } elseif (strtotime($data['check_in']) < strtotime('today')) {
                $errors[] = 'Check-in date cannot be in the past';
            }
        }
        
        if (!empty($data['check_out'])) {
            if (!$this->isValidDate($data['check_out'])) {
                $errors[] = 'Invalid check-out date format';
            } elseif (!empty($data['check_in']) && strtotime($data['check_out']) <= strtotime($data['check_in'])) {
                $errors[] = 'Check-out must be after check-in';
            }
        }
        
        // Validate adults/children
        if (isset($data['adults'])) {
            $adults = (int) $data['adults'];
            if ($adults < 1 || $adults > Constants::MAX_ADULTS) {
                $errors[] = 'Adults must be between 1 and ' . Constants::MAX_ADULTS;
            }
        }
        
        if (isset($data['children'])) {
            $children = (int) $data['children'];
            if ($children < 0 || $children > Constants::MAX_CHILDREN) {
                $errors[] = 'Children must be between 0 and ' . Constants::MAX_CHILDREN;
            }
        }
        
        // Validate children ages
        if (!empty($data['children_ages'])) {
            $ages = is_array($data['children_ages']) 
                ? $data['children_ages'] 
                : explode(',', $data['children_ages']);
            
            foreach ($ages as $age) {
                $age = (int) $age;
                if ($age < Constants::MIN_CHILD_AGE || $age > Constants::MAX_CHILD_AGE) {
                    $errors[] = 'Child age must be between ' . Constants::MIN_CHILD_AGE . ' and ' . Constants::MAX_CHILD_AGE;
                    break;
                }
            }
        }
        
        // Validate price (prevent manipulation)
        if (isset($data['total_price'])) {
            $price = (float) $data['total_price'];
            if ($price < 0 || $price > 100000) {
                $errors[] = 'Invalid price value';
            }
        }
        
        // Validate guest names (basic XSS prevention)
        $nameFields = ['holder_name', 'guest_name'];
        foreach ($nameFields as $field) {
            if (!empty($data[$field]) && !$this->isValidName($data[$field])) {
                $errors[] = "Invalid characters in {$field}";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Validate search parameters
     * 
     * @param array $params Search parameters
     * @return array [valid => bool, errors => array, sanitized => array]
     */
    public function validateSearchParams(array $params): array
    {
        $sanitized = [];

        // Sanitize and validate check_in
        if (!empty($params['check_in'])) {
            if ($this->isValidDate($params['check_in'])) {
                $sanitized['check_in'] = $params['check_in'];
            }
        }

        // Sanitize and validate check_out
        if (!empty($params['check_out'])) {
            if ($this->isValidDate($params['check_out'])) {
                $sanitized['check_out'] = $params['check_out'];
            }
        }

        // Sanitize nights
        $sanitized['nights'] = max(1, min(Constants::MAX_NIGHTS, (int) ($params['nights'] ?? Constants::DEFAULT_NIGHTS)));

        // Sanitize adults
        $sanitized['adults'] = max(1, min(Constants::MAX_ADULTS, (int) ($params['adults'] ?? Constants::DEFAULT_ADULTS)));

        // Sanitize children
        $sanitized['children'] = max(0, min(Constants::MAX_CHILDREN, (int) ($params['children'] ?? Constants::DEFAULT_CHILDREN)));

        // Sanitize rooms
        $sanitized['rooms'] = max(1, min(Constants::MAX_ROOMS, (int) ($params['rooms'] ?? Constants::DEFAULT_ROOMS)));

        // Sanitize destination (alphanumeric, spaces, common punctuation)
        if (!empty($params['destination'])) {
            $sanitized['destination'] = $this->sanitizeString($params['destination'], 100);
        }

        // Sanitize hotel_id
        if (!empty($params['hotel_id'])) {
            $sanitized['hotel_id'] = $this->sanitizeHotelId($params['hotel_id']);
        }

        // Pass through product_id (integer)
        if (!empty($params['product_id'])) {
            $sanitized['product_id'] = (int) $params['product_id'];
        }

        // Pass through children_ages (comma-separated integers)
        if (!empty($params['children_ages']) && is_string($params['children_ages'])) {
            // Only allow digits and commas
            $sanitized['children_ages'] = preg_replace('/[^0-9,]/', '', $params['children_ages']);
        }

        // Pass through rooms_data (JSON string - will be decoded by controller)
        if (!empty($params['rooms_data'])) {
            $sanitized['rooms_data'] = is_string($params['rooms_data']) ? $params['rooms_data'] : json_encode($params['rooms_data']);
        }
        if (!empty($params['room_data'])) {
            $sanitized['room_data'] = is_string($params['room_data']) ? $params['room_data'] : json_encode($params['room_data']);
        }

        // Pass through meal_plan
        if (!empty($params['meal_plan'])) {
            $sanitized['meal_plan'] = preg_replace('/[^a-zA-Z0-9_ &+]/', '', substr($params['meal_plan'], 0, 50));
        }

        // Pass through flex_days (integer)
        if (!empty($params['flex_days'])) {
            $sanitized['flex_days'] = max(0, min(30, (int) $params['flex_days']));
        }

        // Pass through search query
        if (!empty($params['q'])) {
            $sanitized['q'] = $this->sanitizeString($params['q'], 200);
        }

        // Note: debug mode is gated by server-side ConfigProvider::isDebugLogging(),
        // not by URL parameters. The 'debug' and 'reset_circuit' URL params are no longer accepted.

        // Legacy child_age_N parameters
        for ($i = 1; $i <= 6; $i++) {
            $key = 'child_age_' . $i;
            if (isset($params[$key])) {
                $sanitized[$key] = max(0, min(17, (int) $params[$key]));
            }
        }

        return $sanitized;
    }
    
    /**
     * Validate and sanitize guest data
     * 
     * @param array $guests Guest data
     * @return array Sanitized guest data
     */
    public function sanitizeGuestData(array $guests): array
    {
        $sanitized = [];
        
        foreach ($guests as $key => $guest) {
            if (!is_array($guest)) continue;
            
            $sanitized[$key] = [
                'first_name' => $this->sanitizeName($guest['first_name'] ?? ''),
                'last_name' => $this->sanitizeName($guest['last_name'] ?? ''),
                'name' => $this->sanitizeName($guest['name'] ?? ''),
                'api_name' => $this->sanitizeName($guest['api_name'] ?? ''),
                'type' => in_array($guest['type'] ?? '', ['adult', 'child']) ? $guest['type'] : 'adult',
                'age' => isset($guest['age']) ? max(0, min(99, (int) $guest['age'])) : null,
                'room' => max(1, min(5, (int) ($guest['room'] ?? 1))),
                'is_holder' => !empty($guest['is_holder']),
            ];
            
            // Validate birthday format
            if (!empty($guest['birthday'])) {
                if ($this->isValidDate($guest['birthday'])) {
                    $sanitized[$key]['birthday'] = $guest['birthday'];
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Check CSRF token
     * 
     * @param string $token Token to verify
     * @return bool Is valid
     */
    public function verifyCsrfToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }
        
        // CS-Cart's built-in CSRF check
        if (defined('CSRF_TOKEN_NAME')) {
            return fn_csrf_validate_request([CSRF_TOKEN_NAME => $token]);
        }
        
        // Fallback: check session token
        $session_token = $_SESSION['nvt_csrf_token'] ?? '';
        return hash_equals($session_token, $token);
    }
    
    /**
     * Generate CSRF token
     * 
     * @return string Token
     */
    public function generateCsrfToken(): string
    {
        if (!isset($_SESSION['nvt_csrf_token'])) {
            $_SESSION['nvt_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['nvt_csrf_token'];
    }
    
    /**
     * Check rate limit
     * 
     * @param string $key Rate limit key (e.g., IP, user_id)
     * @param int $maxRequests Max requests per window
     * @param int $window Window in seconds
     * @return array [allowed => bool, remaining => int, reset => int]
     */
    public function checkRateLimit(string $key, ?int $maxRequests = null, ?int $window = null): array
    {
        $maxRequests = $maxRequests ?? self::RATE_LIMIT_MAX;
        $window = $window ?? self::RATE_LIMIT_WINDOW;
        
        $cacheKey = 'nvt_rate_' . md5($key);
        $now = time();
        
        // Get current count
        $data = $this->getRateLimitData($cacheKey);
        
        // Reset if window expired
        if ($data['reset'] <= $now) {
            $data = [
                'count' => 0,
                'reset' => $now + $window
            ];
        }
        
        // Check if allowed
        $allowed = $data['count'] < $maxRequests;
        
        // Increment counter
        if ($allowed) {
            $data['count']++;
            $this->setRateLimitData($cacheKey, $data);
        }
        
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $maxRequests - $data['count']),
            'reset' => $data['reset']
        ];
    }
    
    /**
     * Check booking rate limit (stricter)
     * 
     * @param string $identifier User ID or session ID
     * @return bool Is allowed
     */
    public function checkBookingRateLimit(string $identifier): bool
    {
        $result = $this->checkRateLimit(
            'booking_' . $identifier,
            self::BOOKING_LIMIT_HOUR,
            3600
        );
        
        return $result['allowed'];
    }
    
    /**
     * Encrypt sensitive data
     * 
     * @param string $data Data to encrypt
     * @return string Encrypted data
     */
    public function encrypt(string $data): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        
        $encrypted = openssl_encrypt(
            $data,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     * 
     * @param string $data Encrypted data
     * @return string|null Decrypted data or null on failure
     */
    public function decrypt(string $data): ?string
    {
        $key = $this->getEncryptionKey();
        $data = base64_decode($data);
        
        if (strlen($data) < 17) {
            return null;
        }
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        return $decrypted !== false ? $decrypted : null;
    }
    
    /**
     * Sanitize output for HTML
     * 
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    public function escapeHtml(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Log security event
     * 
     * @param string $event Event type
     * @param array $data Event data
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $logData = array_merge([
            'event' => $event,
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
        ], $data);
        
        fn_log_event('general', 'runtime', [
            'message' => 'NovotonSecurity: ' . $event,
            'data' => $logData
        ]);
    }
    
    // ========== Private Helper Methods ==========
    
    /**
     * Check if string is valid date
     */
    private function isValidDate(string $date): bool
    {
        // Accept YYYY-MM-DD format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        
        list($year, $month, $day) = explode('-', $date);
        return checkdate((int)$month, (int)$day, (int)$year);
    }
    
    /**
     * Check if hotel ID is valid format
     */
    private function isValidHotelId(string $hotelId): bool
    {
        // Allow alphanumeric, hyphens, underscores (typical ID formats)
        return (bool) preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $hotelId);
    }
    
    /**
     * Check if name contains only valid characters
     */
    private function isValidName(string $name): bool
    {
        // Allow letters (including accented), spaces, hyphens, apostrophes
        return (bool) preg_match('/^[\p{L}\s\'-]{1,100}$/u', $name);
    }
    
    /**
     * Sanitize a name string
     */
    private function sanitizeName(string $name): string
    {
        // Remove anything that's not a letter, space, hyphen, or apostrophe
        $name = preg_replace('/[^\p{L}\s\'-]/u', '', $name) ?? $name;
        return mb_substr(trim($name), 0, 100);
    }
    
    /**
     * Sanitize general string
     */
    private function sanitizeString(string $string, int $maxLength = 255): string
    {
        // Remove potential XSS
        $string = strip_tags($string);
        $string = htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
        return mb_substr($string, 0, $maxLength);
    }
    
    /**
     * Sanitize hotel ID
     */
    private function sanitizeHotelId(string $hotelId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', substr($hotelId, 0, 50));
    }
    
    /**
     * Get rate limit data from cache
     */
    private function getRateLimitData(string $key): array
    {
        $data = db_get_field(
            "SELECT cache_data FROM ?:novoton_cache WHERE cache_key = ?s AND expires_at > NOW()",
            $key
        );
        
        if ($data) {
            $unserialized = unserialize($data, ['allowed_classes' => false]);
            return is_array($unserialized) ? $unserialized : ['count' => 0, 'reset' => time() + self::RATE_LIMIT_WINDOW];
        }
        
        return ['count' => 0, 'reset' => time() + self::RATE_LIMIT_WINDOW];
    }
    
    /**
     * Set rate limit data in cache
     */
    private function setRateLimitData(string $key, array $data): void
    {
        db_query(
            "REPLACE INTO ?:novoton_cache SET cache_key = ?s, cache_data = ?s, expires_at = ?s, created_at = NOW()",
            $key,
            serialize($data),
            date('Y-m-d H:i:s', $data['reset'] + 60)
        );
    }
    
    /**
     * Get encryption key
     */
    private function getEncryptionKey(): string
    {
        // Use CS-Cart's crypt key or generate one
        $key = Registry::get('config.crypt_key');
        
        if (empty($key)) {
            $key = ConfigProvider::getApiKey();
        }

        if (empty($key)) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton SecurityService: no encryption key available — set config.crypt_key or addon api_key'
            ]);
            $key = hash('sha256', __DIR__ . php_uname('n'), false);
        }
        
        return hash('sha256', $key, true);
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
}
