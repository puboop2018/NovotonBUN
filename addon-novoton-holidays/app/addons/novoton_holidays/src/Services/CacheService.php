<?php
declare(strict_types=1);
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

use Tygh\Addons\NovotonHolidays\Repository\CacheRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Repository\CacheRepository;

class CacheService implements CacheServiceInterface
{
    private const MEMORY_CACHE_MAX_SIZE = 500;

    private string $storage = 'file';
    private string $cache_dir;
    private int $default_ttl = 300;
    /** @var array<string, mixed> */
    private static array $memory_cache = [];
    private bool $debug = false;
    private CacheRepositoryInterface $cacheRepo;

    /**
     * Constructor
     *
     * @param string $storage Storage type ('file' or 'database')
     */
    public function __construct(string $storage = 'file', ?CacheRepositoryInterface $cacheRepo = null)
    {
        $this->storage = $storage;
        $this->cache_dir = DIR_ROOT . '/var/cache/novoton/';
        $this->debug = ConfigProvider::isDebugLogging();
        $this->cacheRepo = $cacheRepo ?? new CacheRepository();
        
        // Ensure cache directory exists
        if ($this->storage === 'file' && !is_dir($this->cache_dir)) {
            if (!mkdir($this->cache_dir, 0755, true) && !is_dir($this->cache_dir)) {
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
    public function get(string $key): mixed
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
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->default_ttl;
        $expires = time() + $ttl;
        
        // Evict oldest entries if memory cache is at capacity (FIFO eviction)
        if (count(self::$memory_cache) >= self::MEMORY_CACHE_MAX_SIZE && !isset(self::$memory_cache[$key])) {
            reset(self::$memory_cache);
            unset(self::$memory_cache[key(self::$memory_cache)]);
        }

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
                if (str_starts_with($key, $prefix)) {
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
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        // Check memory cache with array_key_exists to support null values
        if (array_key_exists($key, self::$memory_cache)) {
            $item = self::$memory_cache[$key];
            if ($item['expires'] > time()) {
                return $item['data'];
            }
        }

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
    private function getFromFile(string $key): mixed
    {
        $file = $this->getCacheFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['expires']) || !isset($data['data'])) {
            // Invalid or legacy serialized format — delete and treat as cache miss
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
        
        $data = json_encode([
            'data' => $value,
            'expires' => $expires,
            'created' => time()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($data === false) {
            return false;
        }

        return file_put_contents($file, $data, LOCK_EX) !== false;
    }
    
    /**
     * Convert SimpleXMLElement and other non-serializable types to arrays
     *
     * @param mixed $data Data to convert
     * @param int $depth Current recursion depth (prevents runaway nesting)
     * @return mixed Serializable data
     */
    private function convertToSerializable($data, int $depth = 0)
    {
        if ($depth > 64) {
            return is_scalar($data) ? $data : null;
        }

        // Handle SimpleXMLElement via dedicated traversal (avoids json_decode(json_encode()) roundtrip)
        if ($data instanceof \SimpleXMLElement) {
            return $this->simpleXmlToArray($data, $depth);
        }

        // Handle arrays recursively
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->convertToSerializable($value, $depth + 1);
            }
            return $result;
        }

        // Handle objects with SimpleXMLElement properties
        if (is_object($data)) {
            $array = [];
            foreach (get_object_vars($data) as $key => $value) {
                $array[$key] = $this->convertToSerializable($value, $depth + 1);
            }
            return $array;
        }

        return $data;
    }

    /**
     * Convert a SimpleXMLElement to a plain array without json_encode/decode roundtrip.
     *
     * @param \SimpleXMLElement $xml The element to convert
     * @param int $depth Current recursion depth
     * @return mixed Array representation, or string for leaf text nodes
     */
    private function simpleXmlToArray(\SimpleXMLElement $xml, int $depth = 0)
    {
        if ($depth > 64) {
            return (string)$xml;
        }

        $result = [];

        // Attributes
        foreach ($xml->attributes() as $attrName => $attrValue) {
            $result['@attributes'][(string)$attrName] = (string)$attrValue;
        }

        // Child elements
        foreach ($xml->children() as $childName => $child) {
            $value = $this->simpleXmlToArray($child, $depth + 1);

            if (isset($result[$childName])) {
                // Multiple children with the same name → collect into indexed array
                if (!is_array($result[$childName]) || !isset($result[$childName][0])) {
                    $result[$childName] = [$result[$childName]];
                }
                $result[$childName][] = $value;
            } else {
                $result[$childName] = $value;
            }
        }

        // If no children and no attributes, return text content
        if (empty($result)) {
            return trim((string)$xml);
        }

        // If has text content alongside children/attributes
        $text = trim((string)$xml);
        if ($text !== '') {
            $result['@text'] = $text;
        }

        return $result;
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

        $files = glob($this->cache_dir . '*/*.cache') ?: [];

        $safe_prefix = ($prefix !== null) ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix) : null;
        foreach ($files as $file) {
            $filename = basename($file, '.cache');
            if ($safe_prefix === null || str_starts_with($filename, $safe_prefix)) {
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
        // Shard into subdirectories using first 2 chars to avoid flat directory degradation
        $shard = substr($safe_key, 0, 2) ?: '__';
        $dir = $this->cache_dir . $shard . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . $safe_key . '.cache';
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
        $row = $this->cacheRepo->findByKey($key);

        if (!$row) {
            return null;
        }

        // Check expiration (expires_at is INT UNSIGNED unix timestamp)
        if ((int) $row['expires_at'] < time()) {
            $this->deleteFromDatabase($key);
            return null;
        }

        $data = json_decode($row['cache_data'], true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // Invalid or legacy serialized format — treat as cache miss
            $this->deleteFromDatabase($key);
            return null;
        }

        // Store in memory
        self::$memory_cache[$key] = [
            'data' => $data,
            'expires' => (int) $row['expires_at']
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
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->cacheRepo->upsert($key, $encoded ?: '', $expires);
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
        $this->cacheRepo->deleteByKey($key);
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
        $result = $this->cacheRepo->deleteAll($prefix);
        
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
            $files = glob($this->cache_dir . '*/*.cache') ?: [];
            $now = time();
            // Fast-path heuristic: files older than this are definitely expired.
            // All cache TTLs in this addon are ≤600s; 86400s (24h) is a safe
            // upper bound that avoids reading files that are obviously stale.
            $maxTtl = 86400;
            foreach ($files as $file) {
                $mtime = file_exists($file) ? filemtime($file) : false;
                if ($mtime !== false && ($mtime + $maxTtl) < $now) {
                    // File is older than max possible TTL — definitely expired
                    if (unlink($file)) {
                        $count++;
                    }
                    continue;
                }
                // Uncertain — read and check actual expiration
                $content = file_get_contents($file);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if (!is_array($data) || !isset($data['expires'])) {
                        // Invalid or legacy serialized format — delete it
                        if (unlink($file)) {
                            $count++;
                        }
                    } elseif ($data['expires'] < $now) {
                        if (unlink($file)) {
                            $count++;
                        }
                    }
                }
            }
        } else {
            // Clean expired database cache
            $count = $this->cacheRepo->deleteExpired(
            );
        }
        
        $this->log('Cache cleanup', ['removed' => $count]);
        
        return $count;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array<string, mixed> Statistics
     */
    public function getStats(): array
    {
        $stats = [
            'memory_items' => count(self::$memory_cache),
            'storage' => $this->storage,
        ];
        
        if ($this->storage === 'file') {
            $files = glob($this->cache_dir . '*/*.cache') ?: [];
            $stats['persistent_items'] = count($files);
            $stats['total_size'] = !empty($files) ? array_sum(array_map('filesize', $files)) : 0;
        } else {
            $stats['persistent_items'] = $this->cacheRepo->countAll();
            $stats['expired_items'] = $this->cacheRepo->countExpired();
        }
        
        return $stats;
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Message
     * @param array<string, mixed> $context Context
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