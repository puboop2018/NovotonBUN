<?php
declare(strict_types=1);
/**
 * PHPUnit bootstrap for Novoton Holidays unit tests.
 *
 * Provides minimal CS-Cart function stubs so that addon classes can be
 * instantiated without the full CS-Cart framework.
 *
 * @package NovotonHolidays\Tests
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ── CS-Cart function stubs ──────────────────────────────────────────────────
// Only define stubs for functions that are referenced at class-load time
// or in the specific methods under test. Tests that need different behaviour
// should use function mocking or override via Closure.

if (!function_exists('fn_log_event')) {
    function fn_log_event(string $type, string $action, array $data = []): void
    {
        // no-op in tests
    }
}

if (!function_exists('fn_csrf_validate_request')) {
    function fn_csrf_validate_request(array $params): bool
    {
        return false;
    }
}

if (!function_exists('db_get_field')) {
    function db_get_field(string $query, ...$params)
    {
        return null;
    }
}

if (!function_exists('db_query')) {
    function db_query(string $query, ...$params)
    {
        return 0;
    }
}

// ── CS-Cart Registry stub ───────────────────────────────────────────────────
if (!class_exists(\Tygh\Registry::class)) {
    // Minimal stub — tests that need specific registry values should
    // set them via Registry::set() before exercising the SUT.
    eval('
    namespace Tygh;
    class Registry {
        private static array $data = [];
        public static function get(string $key) {
            return self::$data[$key] ?? null;
        }
        public static function set(string $key, $value): void {
            self::$data[$key] = $value;
        }
    }
    ');
}

// ── ConfigProvider stub ─────────────────────────────────────────────────────
// SecurityService::getEncryptionKey() calls ConfigProvider::getApiKey().
// Provide a minimal stub if the real class isn't autoloaded.
if (!class_exists(\Tygh\Addons\NovotonHolidays\Services\ConfigProvider::class)) {
    eval('
    namespace Tygh\Addons\NovotonHolidays\Services;
    class ConfigProvider {
        public static function getApiKey(): string { return "test-key-for-unit-tests"; }
        public static function isDebugLogging(): bool { return false; }
    }
    ');
}
