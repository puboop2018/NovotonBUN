<?php
declare(strict_types=1);
/**
 * Travel Core - Booking Form Appearance Settings Controller
 *
 * Dedicated admin page for customizing the React booking engine colors.
 * Color values are stored as travel_core addon settings (hidden type in
 * addon.xml) and injected at runtime via CSS custom properties in
 * booking_engine.tpl.
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

        foreach ($colorMap as $settingId => $info) {
            $value = trim((string) ($submitted[$settingId] ?? ''));

            // Validate: empty or valid hex color
            if ($value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $errors[] = __('travel_core.invalid_color_value', ['[setting]' => __('travel_core.' . $settingId)]);
                continue;
            }

            // Normalize to lowercase hex
            if ($value !== '') {
                $value = strtolower($value);
            }

            // Use CS-Cart's Settings API — finds the setting by name within addon
            $objectId = (int) db_get_field(
                "SELECT object_id FROM ?:settings_objects WHERE name = ?s AND section_id IN (
                    SELECT object_id FROM ?:settings_sections WHERE name = 'appearance'
                    AND parent_id IN (SELECT object_id FROM ?:settings_sections WHERE name = 'travel_core' AND type = 'ADDON')
                )",
                $settingId
            );

            if ($objectId > 0) {
                db_query("UPDATE ?:settings_objects SET value = ?s WHERE object_id = ?i", $value, $objectId);
                $saved++;
            }
        }

        // Clear ALL settings caches so changes appear immediately
        Registry::del('addons.travel_core');
        Registry::del('settings');
        if (class_exists('Tygh\\Settings')) {
            Settings::instance()->clearCache();
        }

        // Force reload from DB
        $addon_scheme = Registry::get('addons.travel_core');
        if ($addon_scheme === null) {
            // Rebuild addon settings cache
            fn_get_addon_data('travel_core');
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

    // Read current values directly from DB to avoid stale cache
    $currentValues = [];
    foreach (array_keys($colorMap) as $settingId) {
        $val = db_get_field(
            "SELECT value FROM ?:settings_objects WHERE name = ?s AND section_id IN (
                SELECT object_id FROM ?:settings_sections WHERE name = 'appearance'
                AND parent_id IN (SELECT object_id FROM ?:settings_sections WHERE name = 'travel_core' AND type = 'ADDON')
            )",
            $settingId
        );
        $currentValues[$settingId] = ($val !== false) ? (string) $val : '';
    }

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
            'value'   => $currentValues[$id] ?? '',
            'label'   => __('travel_core.' . $id),
        ];
    }

    Tygh::$app['view']->assign('color_groups', $color_groups);
}
