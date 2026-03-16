<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller
 * Path: app/addons/sphinx_holidays/controllers/frontend/sphinx_booking.php
 *
 * Mode dispatcher for Sphinx hotel booking flow.
 * Follows the same pattern as novoton_booking.php.
 *
 * Modes:
 *   sphinx_booking/search.php              - Hotel availability search (polling)
 *   sphinx_booking/booking_form.php        - Verify offer, show guest form
 *   sphinx_booking/add_to_cart.php         - Create booking, add to cart
 *   sphinx_booking/ajax_recalculate_price.php - AJAX price re-verification
 *   sphinx_booking/cache_deals.php         - AJAX cached deals for widgets
 *   sphinx_booking/circuit_search.php      - Circuit tour search
 *   sphinx_booking/circuit_booking_form.php - Circuit quote & guest form
 *   sphinx_booking/circuit_add_to_cart.php  - Circuit booking, add to cart
 *   sphinx_booking/experience_search.php   - Experience/activity search
 *   sphinx_booking/experience_booking_form.php - Experience quote & participant form
 *   sphinx_booking/experience_add_to_cart.php  - Experience booking, add to cart
 *   sphinx_booking/package_search.php         - Package search (polling)
 *   sphinx_booking/package_booking_form.php   - Verify package, show guest form
 *   sphinx_booking/package_add_to_cart.php    - Package booking, add to cart
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

//=============================================================================
// SANITIZE $_REQUEST / $_GET — prevent "Array to string conversion" warnings
//=============================================================================
$_sphinx_array_params = ['children_ages', 'ages'];
foreach ($_sphinx_array_params as $_sphinx_param) {
    foreach ([&$_REQUEST, &$_GET] as &$_sphinx_superglobal) {
        if (isset($_sphinx_superglobal[$_sphinx_param]) && is_array($_sphinx_superglobal[$_sphinx_param])) {
            $_sphinx_superglobal[$_sphinx_param] = implode(',', array_map('intval', $_sphinx_superglobal[$_sphinx_param]));
        }
    }
    unset($_sphinx_superglobal);
}
unset($_sphinx_array_params, $_sphinx_param);

//=============================================================================
// UTILITY HELPERS
//=============================================================================

/**
 * Parse and validate guest data from form submission.
 */
if (!function_exists('_sphinx_parse_and_validate_guests')) {
function _sphinx_parse_and_validate_guests(array $guests, string $check_in = '', int $booking_id = 0, string $cart_id = '') {
    $guest_names = [];
    $guests_data = [];

    foreach ($guests as $key => $guest) {
        $first_name = trim($guest['first_name'] ?? '');
        $last_name = trim($guest['last_name'] ?? '');
        $name = trim($guest['name'] ?? '');

        $birthday = _sphinx_parse_dob($guest);

        if (!empty($birthday)) {
            $dob_timestamp = strtotime($birthday);
            $today_midnight = strtotime('today midnight');
            if ($dob_timestamp && $dob_timestamp > $today_midnight) {
                $birthday = '';
            }

            $guest_type = strtolower($guest['type'] ?? '');
            $is_child_guest = (strpos($key, 'child') !== false || $guest_type === 'child');
            if ($dob_timestamp && $is_child_guest && !empty($check_in)) {
                try {
                    $dob_date = new \DateTime($birthday);
                    $check_in_date = new \DateTime($check_in);
                    $age_at_checkin = $dob_date->diff($check_in_date)->y;
                    if ($age_at_checkin >= 18) {
                        fn_set_notification('E', __('error'), __('sphinx_holidays.child_must_be_under_18',
                            ['[default]' => 'Child must be under 18 years old at check-in date.']));
                        return false;
                    }
                } catch (\Exception $e) {
                    $birthday = '';
                }
            }
        }

        if (!empty($last_name) || !empty($first_name)) {
            if (!empty($last_name) && !empty($first_name)) {
                $display_name = $last_name . ', ' . $first_name;
                $api_name = $first_name . ' ' . $last_name;
            } elseif (!empty($last_name)) {
                $display_name = $last_name;
                $api_name = $last_name;
            } else {
                $display_name = $first_name;
                $api_name = $first_name;
            }
            $guest_names[] = $display_name;

            $guest_age = (int)($guest['age'] ?? 0);
            if (!empty($birthday)) {
                try {
                    $dob_date = new \DateTime($birthday);
                    $guest_age = $dob_date->diff(new \DateTime())->y;
                } catch (\Exception $e) {}
            }

            $guests_data[$key] = [
                'name' => $display_name,
                'api_name' => $api_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'type' => $guest['type'] ?? 'adult',
                'age' => $guest_age,
                'birthday' => $birthday,
                'dob' => !empty($birthday) ? date('d/m/Y', strtotime($birthday)) : '',
                'room' => (int)($guest['room'] ?? 1),
                'is_holder' => !empty($guest['is_holder']) ? 1 : 0
            ];
        } elseif (!empty($name)) {
            $guest_names[] = $name;
            $guest_age = (int)($guest['age'] ?? 0);
            if (!empty($birthday)) {
                try {
                    $dob_date = new \DateTime($birthday);
                    $guest_age = $dob_date->diff(new \DateTime())->y;
                } catch (\Exception $e) {}
            }
            $guests_data[$key] = [
                'name' => $name,
                'api_name' => $name,
                'first_name' => '',
                'last_name' => '',
                'type' => $guest['type'] ?? 'adult',
                'age' => $guest_age,
                'birthday' => $birthday,
                'dob' => !empty($birthday) ? date('d/m/Y', strtotime($birthday)) : '',
                'room' => (int)($guest['room'] ?? 1),
                'is_holder' => !empty($guest['is_holder']) ? 1 : 0
            ];
        }
    }

    // Resolve holder: prefer guest with is_holder flag, fallback to first guest
    $holder_name = $guest_names[0] ?? '';
    foreach ($guests_data as $g) {
        if (!empty($g['is_holder']) && !empty($g['name'])) {
            $holder_name = $g['name'];
            break;
        }
    }

    return [
        'guests_data' => $guests_data,
        'guest_names' => $guest_names,
        'guest_list' => implode(', ', $guest_names),
        'holder_name' => $holder_name,
    ];
}
}

/**
 * Parse DOB from various form formats.
 */
if (!function_exists('_sphinx_parse_dob')) {
function _sphinx_parse_dob(array $guest): string {
    $birthday = '';
    if (!empty($guest['dob'])) {
        $dob_value = trim($guest['dob']);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob_value, $matches)) {
            $d = (int)$matches[1]; $m = (int)$matches[2]; $y = (int)$matches[3];
            if ($d >= 1 && $d <= 31 && $m >= 1 && $m <= 12 && $y >= 1925 && $y <= (int)date('Y') && checkdate($m, $d, $y)) {
                $birthday = sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob_value)) {
            $parts = explode('-', $dob_value);
            if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                $birthday = $dob_value;
            }
        }
    } elseif (!empty($guest['dob_day']) && !empty($guest['dob_month']) && !empty($guest['dob_year'])) {
        $d = (int)$guest['dob_day']; $m = (int)$guest['dob_month']; $y = (int)$guest['dob_year'];
        if ($d >= 1 && $d <= 31 && $m >= 1 && $m <= 12 && $y >= 1925 && $y <= (int)date('Y') && checkdate($m, $d, $y)) {
            $birthday = sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
    } elseif (!empty($guest['birthday'])) {
        $raw = trim($guest['birthday']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parts = explode('-', $raw);
            if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                $birthday = $raw;
            }
        }
    }
    return $birthday;
}
}

//=============================================================================
// MODE DISPATCHER
//=============================================================================

$_sphinx_mode_dir = __DIR__ . '/sphinx_booking';

if ($mode == 'search') {
    $__sphinx_result = include($_sphinx_mode_dir . '/search.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'booking_form') {
    $__sphinx_result = include($_sphinx_mode_dir . '/booking_form.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'add_to_cart') {
    $__sphinx_result = include($_sphinx_mode_dir . '/add_to_cart.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'ajax_recalculate_price') {
    include($_sphinx_mode_dir . '/ajax_recalculate_price.php');

} elseif ($mode == 'cache_deals') {
    include($_sphinx_mode_dir . '/cache_deals.php');

} elseif ($mode == 'circuit_search') {
    $__sphinx_result = include($_sphinx_mode_dir . '/circuit_search.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'circuit_booking_form') {
    $__sphinx_result = include($_sphinx_mode_dir . '/circuit_booking_form.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'circuit_add_to_cart') {
    $__sphinx_result = include($_sphinx_mode_dir . '/circuit_add_to_cart.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'experience_search') {
    $__sphinx_result = include($_sphinx_mode_dir . '/experience_search.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'experience_booking_form') {
    $__sphinx_result = include($_sphinx_mode_dir . '/experience_booking_form.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'experience_add_to_cart') {
    $__sphinx_result = include($_sphinx_mode_dir . '/experience_add_to_cart.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'package_search') {
    $__sphinx_result = include($_sphinx_mode_dir . '/package_search.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'package_booking_form') {
    $__sphinx_result = include($_sphinx_mode_dir . '/package_booking_form.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;

} elseif ($mode == 'package_add_to_cart') {
    $__sphinx_result = include($_sphinx_mode_dir . '/package_add_to_cart.php');
    if ($__sphinx_result !== 1) return $__sphinx_result;
}
