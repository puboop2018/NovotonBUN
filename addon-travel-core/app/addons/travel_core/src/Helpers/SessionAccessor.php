<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * Designated boundary for reading/writing CS-Cart session state.
 *
 * Injects into service classes that previously reached into
 * `\Tygh\Tygh::$app['session'][...]` directly. Stops the pattern from
 * spreading further; the session access is kept confined to this single
 * class.
 *
 * CS-Cart's session data lives in the `$_SESSION` superglobal — that is the
 * authoritative store (`$_SESSION['auth']`, `$_SESSION['cart']`, …). The
 * `\Tygh\Tygh::$app['session']` container binding is a frozen Pimple service,
 * so reassigning it (`$app['session'] = …`) throws a FrozenServiceException.
 * Reading and writing `$_SESSION` directly is both safe and consistent: one
 * backing store for every accessor method.
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
        return TypeCoerce::toStringMap($_SESSION['auth'] ?? null);
    }

    /**
     * Read the current cart. Always returns an array.
     *
     * @return array<string, mixed>
     */
    public function cart(): array
    {
        return TypeCoerce::toStringMap($_SESSION['cart'] ?? null);
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
