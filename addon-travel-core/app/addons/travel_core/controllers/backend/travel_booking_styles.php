<?php
declare(strict_types=1);
/**
 * Travel Core - Booking Form Appearance Settings Controller
 *
 * Dedicated admin page for customizing the React booking engine colors.
 * Color values are stored as travel_core addon settings (defined in addon.xml
 * under the 'appearance' section) and injected at runtime via CSS custom
 * properties in booking_engine.tpl.
 *
 * Uses the CS-Cart Settings API (Settings::instance()->updateValue()) for
 * saves — this handles both the database update and cache invalidation.
 *
 * @package TravelCore
 * @since   1.2.0
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Settings;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Color setting definitions: setting_id => [CSS variable, default].
 * Empty default = inherited from LESS/theme.
 */
function _travel_styles_color_map(): array
{
    return [
        'color_primary'         => ['--nvt-primary',           '#003580'],
        'color_accent'          => ['--nvt-accent',            '#febb02'],
        'color_text'            => ['--nvt-text',              ''],
        'color_text_light'      => ['--nvt-text-light',        ''],
        'color_bg'              => ['--nvt-bg',                ''],
        'color_border'          => ['--nvt-border',            ''],
        'color_search_btn_bg'   => ['--nvt-search-btn-bg',     '#006ce4'],
        'color_search_btn_hover'=> ['--nvt-search-btn-hover',  '#0057b8'],
        'color_search_btn_text' => ['--nvt-search-btn-text',   '#ffffff'],
        'color_cal_cheapest'    => ['--nvt-cal-cheapest-color', '#2e7d32'],
        'color_cal_price'       => ['--nvt-cal-price-color',   '#4B5563'],
        'color_danger'          => ['--nvt-danger',            ''],
    ];
}

// ── POST: Save ──

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'save') {
        $submitted = $_REQUEST['appearance'] ?? [];
        $colorMap = _travel_styles_color_map();
        $errors = [];
        $saved = 0;

        foreach ($colorMap as $settingName => $info) {
            $value = trim((string) ($submitted[$settingName] ?? ''));

            // Validate: empty or valid hex color
            if ($value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $errors[] = __('travel_core.invalid_color_value', ['[setting]' => __('travel_core.' . $settingName)]);
                continue;
            }

            // Normalize to lowercase hex
            $value = ($value !== '') ? strtolower($value) : '';

            // Use CS-Cart's Settings API — handles DB write + cache invalidation
            Settings::instance()->updateValue($settingName, $value, 'travel_core');
            $saved++;
        }

        if (!empty($errors)) {
            foreach ($errors as $err) {
                fn_set_notification('E', __('error'), $err);
            }
        } else {
            fn_set_notification('N', __('notice'), __('travel_core.colors_saved'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'travel_booking_styles.manage'];
    }
}

// ── GET: Manage ──

if ($mode === 'manage') {
    $colorMap = _travel_styles_color_map();

    // Read current values via Registry (reliable — Settings API manages cache)
    $tc = Registry::get('addons.travel_core') ?: [];

    $color_groups = [
        'base' => [
            'title' => __('travel_core.appearance_base_header'),
            'colors' => [],
        ],
        'button' => [
            'title' => __('travel_core.appearance_button_header'),
            'colors' => [],
        ],
        'calendar' => [
            'title' => __('travel_core.appearance_calendar_header'),
            'colors' => [],
        ],
        'status' => [
            'title' => __('travel_core.appearance_status_header'),
            'colors' => [],
        ],
    ];

    // Group mapping
    $groupOf = [
        'color_primary' => 'base', 'color_accent' => 'base', 'color_text' => 'base',
        'color_text_light' => 'base', 'color_bg' => 'base', 'color_border' => 'base',
        'color_search_btn_bg' => 'button', 'color_search_btn_hover' => 'button',
        'color_search_btn_text' => 'button',
        'color_cal_cheapest' => 'calendar', 'color_cal_price' => 'calendar',
        'color_danger' => 'status',
    ];

    foreach ($colorMap as $id => [$cssVar, $default]) {
        $group = $groupOf[$id] ?? 'base';
        $color_groups[$group]['colors'][] = [
            'id'      => $id,
            'var'     => $cssVar,
            'default' => $default,
            'value'   => $tc[$id] ?? '',
            'label'   => __('travel_core.' . $id),
        ];
    }

    Tygh::$app['view']->assign('color_groups', $color_groups);
}
