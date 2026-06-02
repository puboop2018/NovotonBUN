<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Designated boundary for reading/writing CS-Cart session state.
 *
 * Injects into service classes that previously reached into
 * `\Tygh\Tygh::$app['session'][...]` directly. Stops the pattern from
 * spreading further; the static-property access is kept confined to
 * this single class and is allowlisted in `phpstan-disallowed-calls.neon`.
 *
 * For reference-based mutation flows that pass the live `$cart` / `$auth`
 * into CS-Cart's procedural API (e.g. `fn_add_product_to_cart`), see the
 * `fn_{addon}_add_to_session_cart()` helpers in the respective addons'
 * `functions/helpers.php` files — references can't cross a normal
 * accessor method boundary cleanly, so they stay in the procedural
 * boundary where they already belong.
 */
final class SessionAccessor
{
    /**
     * Read the logged-in user / auth context. Always returns an array —
     * empty map when no session value is present or the value is not an
     * array (e.g. during bootstrap).
     *
     * @return array<string, mixed>
     */
    public function auth(): array
    {
        return TypeCoerce::toStringMap($this->session()['auth'] ?? null);
    }

    /**
     * Read the current cart. Always returns an array.
     *
     * @return array<string, mixed>
     */
    public function cart(): array
    {
        return TypeCoerce::toStringMap($this->session()['cart'] ?? null);
    }

    public function get(string $key): mixed
    {
        return $this->session()[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        // CS-Cart's $app['session'] service is a reference to $_SESSION.
        // Writing to the container binding directly throws Pimple's
        // FrozenServiceException, so write to $_SESSION which is the same
        // underlying storage.
        $_SESSION[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    private function session(): array
    {
        return TypeCoerce::toStringMap(\Tygh\Tygh::$app['session']);
    }
}
