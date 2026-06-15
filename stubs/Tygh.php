<?php

/**
 * Minimal stubs for CS-Cart core classes referenced from this project.
 *
 * NOT loaded at runtime (stubs live outside the autoload path). PHPStan
 * scans this file purely for symbol discovery — specifically so the
 * disallowed-calls rule (Wave 1 PR 2) can resolve `Registry::get()` /
 * `Tygh::$app[...]` to their fully-qualified class names and match the
 * forbidden-calls config.
 *
 * Bodies are empty stubs; types approximate CS-Cart behaviour.
 *
 * @see /home/user/NovotonBUN/phpstan-disallowed-calls.neon
 */

// phpcs:disable PSR1.Files.SideEffects

namespace {
    // CS-Cart global DB helper — formats a parameterised query fragment and returns the result as a string.
    function db_quote(string $pattern, mixed ...$args): string
    {
        return '';
    }
}

namespace Tygh {
    class Registry
    {
        /** @return mixed */
        public static function get(string $key)
        {
            return null;
        }

        public static function set(string $key, mixed $value, bool $overwrite = true): void
        {
        }

        /** @return array<string, mixed> */
        public static function getAll(): array
        {
            return [];
        }

        public static function cleanup(?string $key = null): void
        {
        }

        public static function del(string $key): void
        {
        }
    }

    class Tygh
    {
        /** @var array<string, mixed> */
        public static array $app = [];
    }
}
