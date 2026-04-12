<?php
declare(strict_types=1);
/**
 * Novoton Error Handler
 * 
 * Centralized error handling for consistent error responses
 * across the addon.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Registry;

class ErrorHandler
{
    /** @var array<string, mixed> Collected errors */
    private static $errors = [];

    /** @var bool Debug mode */
    private static $debug = false;

    /** @var bool Whether debug has been auto-initialized from settings */
    private static $initialized = false;
    
    /** @var array<string, mixed> Error messages (translatable) */
    private static $messages = [
        Constants::ERROR_INVALID_DATA => 'Invalid data provided',
        Constants::ERROR_API_FAILURE => 'API communication error',
        Constants::ERROR_NOT_AVAILABLE => 'Room not available for selected dates',
        Constants::ERROR_PRICE_CHANGED => 'Price has changed since your selection',
        Constants::ERROR_BOOKING_FAILED => 'Booking could not be completed',
        Constants::ERROR_RATE_LIMITED => 'Too many requests, please wait',
        Constants::ERROR_UNAUTHORIZED => 'Authentication failed',
    ];
    
    /** @var array<string, mixed> Romanian error messages */
    private static $messagesRo = [
        Constants::ERROR_INVALID_DATA => 'Date invalide furnizate',
        Constants::ERROR_API_FAILURE => 'Eroare de comunicare cu serverul',
        Constants::ERROR_NOT_AVAILABLE => 'Camera nu este disponibilă pentru datele selectate',
        Constants::ERROR_PRICE_CHANGED => 'Prețul s-a modificat de la ultima verificare',
        Constants::ERROR_BOOKING_FAILED => 'Rezervarea nu a putut fi finalizată',
        Constants::ERROR_RATE_LIMITED => 'Prea multe solicitări, vă rugăm așteptați',
        Constants::ERROR_UNAUTHORIZED => 'Autentificare eșuată',
    ];
    
    /**
     * Initialize error handler
     *
     * @param bool $debug Enable debug mode
     */
    public static function init(bool $debug = false): void
    {
        self::$debug = $debug;
        self::$errors = [];
        self::$initialized = true;
    }

    /**
     * Auto-initialize from addon settings if not explicitly initialized
     */
    private static function ensureInitialized(): void
    {
        if (!self::$initialized) {
            self::$debug = (Registry::get(Constants::SETTING_DEBUG_LOGGING) ?? 'N') === 'Y';
            self::$initialized = true;
        }
    }

    /**
     * Add an error
     *
     * @param string $code Error code
     * @param string $message Custom message (optional)
     * @param array<string, mixed> $context Additional context
     */
    public static function addError(string $code, string $message = '', array $context = []): void
    {
        self::ensureInitialized();

        self::$errors[] = [
            'code' => $code,
            'message' => $message ?: self::getMessage($code),
            'context' => self::$debug ? $context : [],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Log error if debug enabled
        if (self::$debug) {
            self::logError($code, $message, $context);
        }
    }
    
    /**
     * Check if there are errors
     * 
     * @return bool Has errors
     */
    public static function hasErrors(): bool
    {
        return !empty(self::$errors);
    }
    
    /**
     * Get all errors
     * 
     * @return array<string, mixed> Errors
     */
    public static function getErrors(): array
    {
        return self::$errors;
    }
    
    /**
     * Get first error
     * 
     * @return array<string, mixed>|null First error or null
     */
    public static function getFirstError(): ?array
    {
        return self::$errors[0] ?? null;
    }
    
    /**
     * Clear all errors
     */
    public static function clear(): void
    {
        self::$errors = [];
    }
    
    /**
     * Get error message for code
     * 
     * @param string $code Error code
     * @param string $lang Language (en/ro)
     * @return string Message
     */
    public static function getMessage(string $code, string $lang = 'en'): string
    {
        if ($lang === 'ro' && isset(self::$messagesRo[$code])) {
            return self::$messagesRo[$code];
        }
        
        return self::$messages[$code] ?? 'An error occurred';
    }
    
    /**
     * Create error response for AJAX
     * 
     * @param string $code Error code
     * @param string $message Custom message
     * @param array<string, mixed> $data Additional data
     * @return array<string, mixed> Response array
     */
    public static function createResponse(string $code, string $message = '', array $data = []): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message ?: self::getMessage($code),
            ],
            'data' => $data,
        ];
    }
    
    /**
     * Create success response for AJAX
     * 
     * @param array<string, mixed> $data Response data
     * @param string $message Success message
     * @return array<string, mixed> Response array
     */
    public static function createSuccessResponse(array $data = [], string $message = ''): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }
    
    /**
     * Handle exception
     * 
     * @param \Throwable $e Exception
     * @param string $context Where the exception occurred
     * @return array<string, mixed> Error response
     */
    public static function handleException(\Throwable $e, string $context = ''): array
    {
        $code = Constants::ERROR_API_FAILURE;
        $message = self::$debug ? $e->getMessage() : self::getMessage($code);
        
        self::addError($code, $e->getMessage(), [
            'context' => $context,
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::$debug ? $e->getTraceAsString() : null,
        ]);
        
        return self::createResponse($code, $message);
    }
    
    /**
     * Log error to CS-Cart log
     * 
     * @param string $code Error code
     * @param string $message Message
     * @param array<string, mixed> $context Context
     */
    private static function logError(string $code, string $message, array $context): void
    {
        fn_log_event('general', 'runtime', [
            'message' => "[NovotonError] {$code}: {$message}",
            'context' => $context,
        ]);
    }
    
    /**
     * Validate required fields
     * 
     * @param array<string, mixed> $data Data to validate
     * @param array<string, mixed> $required Required field names
     * @return array<string, mixed> [valid => bool, missing => array]
     */
    public static function validateRequired(array $data, array $required): array
    {
        $missing = [];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        
        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
    }
    
    /**
     * Format validation errors as user-friendly message
     * 
     * @param array<string, mixed> $errors Validation errors
     * @param string $lang Language
     * @return string Formatted message
     */
    public static function formatValidationErrors(array $errors, string $lang = 'en'): string
    {
        if (empty($errors)) {
            return '';
        }
        
        $prefix = $lang === 'ro' ? 'Câmpuri lipsă: ' : 'Missing fields: ';
        return $prefix . implode(', ', $errors);
    }
    
    /**
     * Check API response for errors
     * 
     * @param mixed $response API response
     * @return bool Has error
     */
    public static function checkApiResponse($response): bool
    {
        if ($response === null || $response === false) {
            self::addError(Constants::ERROR_API_FAILURE, 'Empty API response');
            return true;
        }
        
        if (is_array($response) && isset($response['error'])) {
            self::addError(Constants::ERROR_API_FAILURE, $response['error']);
            return true;
        }
        
        return false;
    }
}