<?php
/**
 * Novoton Cache Service
 * 
 * Provides caching layer for API responses and computed data.
 * Supports file-based and database caching with TTL.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Registry;

class CacheService
{
    /** @var string Cache storage type: 'file' or 'database' */
    private $storage = 'file';
    
    /** @var string Cache directory for file storage */
    private $cache_dir;
    
    /** @var int Default TTL in seconds (5 minutes) */
    private $default_ttl = 300;
    
    /** @var array In-memory cache for current request */
    private static $memory_cache = [];
    
    /** @var bool Debug mode */
    private $debug = false;
    
    /**
     * Constructor
     * 
     * @param string $storage Storage type ('file' or 'database')
     */
    public function __construct(string $storage = 'file')
    {
        $this->storage = $storage;
        $this->cache_dir = DIR_ROOT . '/var/cache/novoton/';
        $this->debug = (Registry::get(\Tygh\Addons\NovotonHolidays\Constants::SETTING_DEBUG_LOGGING) ?? 'N') === 'Y';
        
        // Ensure cache directory exists
        if ($this->storage === 'file' && !is_dir($this->cache_dir)) {
            if (!mkdir($this->cache_dir, 0777, true) && !is_dir($this->cache_dir)) {
                fn_log_event('general', 'warning', ['message' => 'Novoton CacheService: Failed to create cache directory', 'dir' => $this->cache_dir]);
            }
        }
    }
    
    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public function get(string $key)
    {
        // Check memory cache first (fastest)
        if (isset(self::$memory_cache[$key])) {
            $item = self::$memory_cache[$key];
            if ($item['expires'] > time()) {
                return $item['data'];
            }
            unset(self::$memory_cache[$key]);
        }
        
        // Check persistent cache
        if ($this->storage === 'file') {
            return $this->getFromFile($key);
        } else {
            return $this->getFromDatabase($key);
        }
    }
    
    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->default_ttl;
        $expires = time() + $ttl;
        
        // Store in memory cache
        self::$memory_cache[$key] = [
            'data' => $value,
            'expires' => $expires
        ];
        
        // Store in persistent cache
        if ($this->storage === 'file') {
            return $this->setToFile($key, $value, $expires);
        } else {
            return $this->setToDatabase($key, $value, $expires);
        }
    }
    
    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete(string $key): bool
    {
        unset(self::$memory_cache[$key]);
        
        if ($this->storage === 'file') {
            return $this->deleteFromFile($key);
        } else {
            return $this->deleteFromDatabase($key);
        }
    }
    
    /**
     * Clear all cache or by prefix
     * 
     * @param string|null $prefix Key prefix to clear (null for all)
     * @return int Number of items cleared
     */
    public function clear(?string $prefix = null): int
    {
        $count = 0;
        
        // Clear memory cache
        if ($prefix === null) {
            $count += count(self::$memory_cache);
            self::$memory_cache = [];
        } else {
            foreach (array_keys(self::$memory_cache) as $key) {
                if (strpos($key, $prefix) === 0) {
                    unset(self::$memory_cache[$key]);
                    $count++;
                }
            }
        }
        
        // Clear persistent cache
        if ($this->storage === 'file') {
            $count += $this->clearFileCache($prefix);
        } else {
            $count += $this->clearDatabaseCache($prefix);
        }
        
        return $count;
    }
    
    /**
     * Get or set cached value (convenience method)
     * 
     * @param string $key Cache key
     * @param callable $callback Function to generate value if not cached
     * @param int|null $ttl Time to live in seconds
     * @return mixed Cached or generated value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    // ========== File Storage Methods ==========
    
    /**
     * Get value from file cache
     * 
     * @param string $key Cache key
     * @return mixed|null
     */
    private function getFromFile(string $key)
    {
        $file = $this->getCacheFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = unserialize($content, ['allowed_classes' => false]);
        if ($data === false || !isset($data['expires']) || !isset($data['data'])) {
            unlink($file);
            return null;
        }

        // Check expiration
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        
        // Store in memory for future requests
        self::$memory_cache[$key] = $data;
        
        return $data['data'];
    }
    
    /**
     * Set value to file cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expires Expiration timestamp
     * @return bool Success
     */
    private function setToFile(string $key, $value, int $expires): bool
    {
        $file = $this->getCacheFilePath($key);
        
        // Convert SimpleXMLElement to array to allow serialization
        $value = $this->convertToSerializable($value);
        
        $data = serialize([
            'data' => $value,
            'expires' => $expires,
            'created' => time()
        ]);
        
        return file_put_contents($file, $data, LOCK_EX) !== false;
    }
    
    /**
     * Convert SimpleXMLElement and other non-serializable types to arrays
     * 
     * @param mixed $data Data to convert
     * @return mixed Serializable data
     */
    private function convertToSerializable($data)
    {
        // Handle SimpleXMLElement
        if ($data instanceof \SimpleXMLElement) {
            return json_decode(json_encode($data), true);
        }
        
        // Handle arrays recursively
        if (is_array($data)) {
            return array_map([$this, 'convertToSerializable'], $data);
        }
        
        // Handle objects with SimpleXMLElement properties
        if (is_object($data)) {
            $array = [];
            foreach (get_object_vars($data) as $key => $value) {
                $array[$key] = $this->convertToSerializable($value);
            }
            return $array;
        }
        
        return $data;
    }
    
    /**
     * Delete value from file cache
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    private function deleteFromFile(string $key): bool
    {
        $file = $this->getCacheFilePath($key);
        
        if (file_exists($file)) {
            return unlink($file);
        }
        
        return true;
    }
    
    /**
     * Clear file cache
     * 
     * @param string|null $prefix Key prefix
     * @return int Number cleared
     */
    private function clearFileCache(?string $prefix = null): int
    {
        $count = 0;
        
        if (!is_dir($this->cache_dir)) {
            return 0;
        }
        
        $files = glob($this->cache_dir . '*.cache') ?: [];
        
        foreach ($files as $file) {
            $filename = basename($file, '.cache');
            
            if ($prefix === null || strpos($filename, $prefix) === 0) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
    
    /**
     * Get file path for cache key
     * 
     * @param string $key Cache key
     * @return string File path
     */
    private function getCacheFilePath(string $key): string
    {
        // Sanitize key for filename
        $safe_key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cache_dir . $safe_key . '.cache';
    }
    
    // ========== Database Storage Methods ==========
    
    /**
     * Get value from database cache
     * 
     * @param string $key Cache key
     * @return mixed|null
     */
    private function getFromDatabase(string $key)
    {
        $row = db_get_row(
            "SELECT cache_data, expires_at FROM ?:novoton_cache WHERE cache_key = ?s",
            $key
        );
        
        if (!$row) {
            return null;
        }
        
        // Check expiration
        if (strtotime($row['expires_at']) < time()) {
            $this->deleteFromDatabase($key);
            return null;
        }
        
        $data = unserialize($row['cache_data'], ['allowed_classes' => false]);
        
        // Store in memory
        self::$memory_cache[$key] = [
            'data' => $data,
            'expires' => strtotime($row['expires_at'])
        ];
        
        return $data;
    }
    
    /**
     * Set value to database cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expires Expiration timestamp
     * @return bool Success
     */
    private function setToDatabase(string $key, $value, int $expires): bool
    {
        $data = [
            'cache_key' => $key,
            'cache_data' => serialize($value),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Use REPLACE to handle both insert and update
        db_query(
            "REPLACE INTO ?:novoton_cache SET ?u",
            $data
        );
        
        return true;
    }
    
    /**
     * Delete value from database cache
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    private function deleteFromDatabase(string $key): bool
    {
        db_query("DELETE FROM ?:novoton_cache WHERE cache_key = ?s", $key);
        return true;
    }
    
    /**
     * Clear database cache
     * 
     * @param string|null $prefix Key prefix
     * @return int Number cleared
     */
    private function clearDatabaseCache(?string $prefix = null): int
    {
        if ($prefix === null) {
            $result = db_query("DELETE FROM ?:novoton_cache");
        } else {
            $result = db_query(
                "DELETE FROM ?:novoton_cache WHERE cache_key LIKE ?l",
                $prefix . '%'
            );
        }
        
        return $result;
    }
    
    // ========== Maintenance Methods ==========
    
    /**
     * Clean up expired cache entries
     * 
     * @return int Number of entries cleaned
     */
    public function cleanup(): int
    {
        $count = 0;
        
        if ($this->storage === 'file') {
            // Clean expired file cache
            $files = glob($this->cache_dir . '*.cache') ?: [];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content !== false) {
                    $data = unserialize($content, ['allowed_classes' => false]);
                    if ($data !== false && isset($data['expires']) && $data['expires'] < time()) {
                        if (unlink($file)) {
                            $count++;
                        }
                    }
                }
            }
        } else {
            // Clean expired database cache
            $count = db_query(
                "DELETE FROM ?:novoton_cache WHERE expires_at < NOW()"
            );
        }
        
        $this->log('Cache cleanup', ['removed' => $count]);
        
        return $count;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Statistics
     */
    public function getStats(): array
    {
        $stats = [
            'memory_items' => count(self::$memory_cache),
            'storage' => $this->storage,
        ];
        
        if ($this->storage === 'file') {
            $files = glob($this->cache_dir . '*.cache') ?: [];
            $stats['persistent_items'] = count($files);
            $stats['total_size'] = array_sum(array_map('filesize', $files));
        } else {
            $stats['persistent_items'] = db_get_field(
                "SELECT COUNT(*) FROM ?:novoton_cache"
            );
            $stats['expired_items'] = db_get_field(
                "SELECT COUNT(*) FROM ?:novoton_cache WHERE expires_at < NOW()"
            );
        }
        
        return $stats;
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Message
     * @param array $context Context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->debug) {
            fn_log_event('general', 'runtime', array_merge(
                ['message' => 'NovotonCache: ' . $message],
                $context
            ));
        }
    }
}
