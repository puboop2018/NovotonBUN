<?php
/**
 * Novoton Holidays - Auth Hook Functions
 *
 * Responsible for:
 *   - user_login_post: Link session bookings to user account on login
 *   - create_user_post: Link bookings by email on registration
 *
 * When a guest creates a booking and later logs in (or registers), these
 * hooks ensure the booking rows are associated with the authenticated user.
 *
 * @package NovotonHolidays
 * @since   3.0.0
 */

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Hook: After user login - link session bookings to user account.
 *
 * Links by session_id first (exact match for current browser session),
 * then by email (catches bookings from other devices).
 *
 * @param array $user_data User profile data
 * @param array $auth      Authentication context
 */
function fn_novoton_holidays_user_login_post($user_data, $auth)
{
    if (empty($auth['user_id'])) {
        return;
    }

    $user_id    = intval($auth['user_id']);
    $session_id = session_id();

    // Link by session
    if (!empty($session_id)) {
        $updated = db_query(
            "UPDATE ?:novoton_bookings
             SET user_id = ?i
             WHERE session_id = ?s
             AND user_id = 0
             AND order_id = 0",
            $user_id,
            $session_id
        );

        if ($updated > 0) {
            fn_log_event('general', 'runtime', [
                'message'         => 'Novoton: Linked session bookings to user on login',
                'user_id'         => $user_id,
                'session_id'      => $session_id,
                'bookings_linked' => $updated,
            ]);
        }
    }

    // Link by email
    if (!empty($user_data['email'])) {
        $updated_by_email = db_query(
            "UPDATE ?:novoton_bookings
             SET user_id = ?i
             WHERE guest_email = ?s
             AND user_id = 0",
            $user_id,
            $user_data['email']
        );

        if ($updated_by_email > 0) {
            fn_log_event('general', 'runtime', [
                'message'         => 'Novoton: Linked email bookings to user on login',
                'user_id'         => $user_id,
                'email'           => $user_data['email'],
                'bookings_linked' => $updated_by_email,
            ]);
        }
    }
}

/**
 * Hook: After user registration - link bookings by email and session.
 *
 * @param array $user_data Newly created user data (includes user_id, email)
 */
function fn_novoton_holidays_create_user_post($user_data)
{
    if (empty($user_data['user_id']) || empty($user_data['email'])) {
        return;
    }

    $user_id = intval($user_data['user_id']);

    // Link by email
    $updated = db_query(
        "UPDATE ?:novoton_bookings
         SET user_id = ?i
         WHERE guest_email = ?s
         AND user_id = 0",
        $user_id,
        $user_data['email']
    );

    // Link by current session
    $session_id = session_id();
    if (!empty($session_id)) {
        db_query(
            "UPDATE ?:novoton_bookings
             SET user_id = ?i
             WHERE session_id = ?s
             AND user_id = 0",
            $user_id,
            $session_id
        );
    }

    if ($updated > 0) {
        fn_log_event('general', 'runtime', [
            'message'         => 'Novoton: Linked bookings to new user account',
            'user_id'         => $user_id,
            'email'           => $user_data['email'],
            'bookings_linked' => $updated,
        ]);
    }
}
