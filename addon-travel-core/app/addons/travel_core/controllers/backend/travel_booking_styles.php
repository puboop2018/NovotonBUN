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

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Get (or create) the section_id for the 'appearance' tab under the travel_core addon settings.
 *
 * The addon.xml doesn't declare an 'appearance' section or color_* items, so CS-Cart
 * never creates them on install.  This function self-bootstraps: if the section or any
 * color settings are missing, it creates them on the fly.
 */
function _travel_styles_get_section_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $parentId = (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = 'travel_core' AND type = 'ADDON'"
    );

    if ($parentId <= 0) {
        $id = 0;
        return 0;
    }

    $id = (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = 'appearance' AND parent_id = ?i",
        $parentId
    );

    // Self-bootstrap: create the 'appearance' section if it doesn't exist
    if ($id <= 0) {
        db_query("INSERT INTO ?:settings_sections ?e", [
            'name'        => 'appearance',
            'parent_id'   => $parentId,
            'edition_type' => 'ROOT',
            'type'        => 'TAB',
            'position'    => 100,
            'is_optional' => 'N',
        ]);
        $id = (int) db_get_field("SELECT LAST_INSERT_ID()");
    }

    // Ensure color_* setting rows exist (seed with defaults on first use)
    if ($id > 0) {
        $existingNames = db_get_fields(
            "SELECT name FROM ?:settings_objects WHERE section_id = ?i AND name LIKE 'color_%'", $id
        );
        $existingSet = array_flip($existingNames ?: []);

        foreach (_travel_styles_color_map() as $name => [$cssVar, $default]) {
            if (!isset($existingSet[$name])) {
                db_query("INSERT INTO ?:settings_objects ?e", [
                    'name'           => $name,
                    'section_id'     => $id,
                    'section_tab_id' => 0,
                    'type'           => 'I',
                    'value'          => '',
                    'edition_type'   => 'ROOT',
                    'handler'        => '',
                    'parent_id'      => 0,
                    'is_global'      => 'N',
                    'position'       => 0,
                ]);
            }
        }
    }

    return $id;
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

        // Validate all submitted values first
        $clean = [];
        foreach ($colorMap as $settingId => $info) {
            $value = trim((string) ($submitted[$settingId] ?? ''));

            if ($value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $errors[] = __('travel_core.invalid_color_value', ['[setting]' => __('travel_core.' . $settingId)]);
                continue;
            }

            $clean[$settingId] = ($value !== '') ? strtolower($value) : '';
        }

        // _travel_styles_get_section_id() guarantees section + rows exist
        $sectionId = _travel_styles_get_section_id();
        if ($sectionId > 0 && !empty($clean)) {
            // 1 query: fetch all existing object_ids
            $existing = db_get_hash_single_array(
                "SELECT name, object_id FROM ?:settings_objects WHERE section_id = ?i AND name IN (?a)",
                ['name', 'object_id'],
                $sectionId, array_keys($clean)
            );

            foreach ($clean as $settingId => $value) {
                if (!empty($existing[$settingId])) {
                    db_query("UPDATE ?:settings_objects SET value = ?s WHERE object_id = ?i",
                        $value, (int) $existing[$settingId]);
                    $saved++;
                }
            }
        }

        // Clear ALL caches so color changes appear on the frontend immediately.
        // Registry::del() only clears in-memory state for the current request;
        // CS-Cart uses a persistent file-based settings cache that must also be cleared.
        Registry::del('addons.travel_core');
        Registry::del('settings');
        if (function_exists('fn_clear_cache')) {
            fn_clear_cache('registry');
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
    $sectionId = _travel_styles_get_section_id();
    $currentValues = [];
    if ($sectionId > 0) {
        $rows = db_get_hash_single_array(
            "SELECT name, value FROM ?:settings_objects WHERE section_id = ?i AND name LIKE 'color_%'",
            ['name', 'value'],
            $sectionId
        );
        $currentValues = is_array($rows) ? $rows : [];
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
