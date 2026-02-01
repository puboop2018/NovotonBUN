<?php
/**
 * Novoton Logger Trait
 * 
 * Provides consistent logging functionality across all services.
 * Supports PSR-3-like log levels.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;

trait LoggerTrait
{
    /** @var bool Debug mode enabled */
    private $debugEnabled;
    
    /** @var string Service name for log prefix */
    private $logPrefix;
    
    /**
     * Initialize logger
     * 
     * @param string $prefix Log prefix (service name)
     */
    protected function initLogger(string $prefix = ''): void
    {
        $this->debugEnabled = (Registry::get('addons.novoton_holidays.debug_logging') ?? 'N') === 'Y';
        $this->logPrefix = $prefix ?: (new \ReflectionClass($this))->getShortName();
    }
    
    /**
     * Log debug message (only when debug enabled)
     * 
     * @param string $message Message
     * @param array $context Additional context
     */
    protected function debug(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->writeLog('DEBUG', $message, $context);
        }
    }
    
    /**
     * Log info message
     * 
     * @param string $message Message
     * @param array $context Additional context
     */
    protected function info(string $message, array $context = []): void
    {
        $this->writeLog('INFO', $message, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Message
     * @param array $context Additional context
     */
    protected function warning(string $message, array $context = []): void
    {
        $this->writeLog('WARNING', $message, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Message
     * @param array $context Additional context
     */
    protected function error(string $message, array $context = []): void
    {
        $this->writeLog('ERROR', $message, $context);
    }
    
    /**
     * Log critical message
     * 
     * @param string $message Message
     * @param array $context Additional context
     */
    protected function critical(string $message, array $context = []): void
    {
        $this->writeLog('CRITICAL', $message, $context);
    }
    
    /**
     * Write log entry
     * 
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     */
    private function writeLog(string $level, string $message, array $context): void
    {
        $prefix = $this->logPrefix ?? 'Novoton';
        
        // Build log message
        $logMessage = "[{$level}] {$prefix}: {$message}";
        
        // Format context for logging
        if (!empty($context)) {
            // Sanitize sensitive data
            $context = $this->sanitizeLogContext($context);
        }
        
        // Use CS-Cart's logging
        fn_log_event('general', 'runtime', array_merge(
            ['message' => $logMessage],
            $context
        ));
    }
    
    /**
     * Sanitize sensitive data from log context
     * 
     * @param array $context Context data
     * @return array Sanitized context
     */
    private function sanitizeLogContext(array $context): array
    {
        $sensitiveKeys = [
            'password', 'api_password', 'psw', 'card', 'credit_card',
            'cvv', 'pin', 'secret', 'token', 'auth'
        ];
        
        foreach ($context as $key => $value) {
            $lowerKey = strtolower($key);
            
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $context[$key] = '***REDACTED***';
                    break;
                }
            }
            
            // Recursively sanitize arrays
            if (is_array($value)) {
                $context[$key] = $this->sanitizeLogContext($value);
            }
        }
        
        return $context;
    }
    
    /**
     * Start timing for performance logging
     * 
     * @param string $label Timer label
     */
    protected function startTimer(string $label): void
    {
        if (!isset($this->timers)) {
            $this->timers = [];
        }
        $this->timers[$label] = microtime(true);
    }
    
    /**
     * End timing and log duration
     * 
     * @param string $label Timer label
     * @param string $message Optional message
     */
    protected function endTimer(string $label, string $message = ''): void
    {
        if (!isset($this->timers[$label])) {
            return;
        }
        
        $duration = round((microtime(true) - $this->timers[$label]) * 1000, 2);
        $message = $message ?: "{$label} completed";
        
        $this->debug($message, ['duration_ms' => $duration]);
        
        unset($this->timers[$label]);
    }
}
