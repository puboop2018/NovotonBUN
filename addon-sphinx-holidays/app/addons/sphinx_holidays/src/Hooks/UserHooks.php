<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Hooks;

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * User-lifecycle hook bodies (login / registration).
 *
 * Bookings created before authentication are owned by the PHP session;
 * once the visitor logs in or registers, those rows get their user_id so
 * the account's booking history is complete. The func.php shells
 * (fn_sphinx_holidays_user_login_post / _create_user_post) delegate here.
 */
final class UserHooks
{
    /**
     * Link session-owned sphinx bookings to a user.
     *
     * @param mixed $userId User id from the hook (mixed — hooks are untyped)
     * @param string|null $sessionId Session override for tests; null = current session
     */
    public static function linkSessionBookings(mixed $userId, ?string $sessionId = null): void
    {
        $uid = TypeCoerce::toInt($userId);
        if ($uid <= 0) {
            return;
        }

        $sessionId ??= (string) session_id();
        if ($sessionId === '') {
            return;
        }

        Container::getBookingRepository()->linkToUserBySession($uid, $sessionId);
    }
}
