<?php
/**
 * Travel SEO Templates — Admin page for managing SEO templates across all travel addons.
 *
 * Provides a unified interface with placeholder/modifier reference sidebar,
 * similar to CS-Cart's native SEO Templates page.
 */

declare(strict_types=1);

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Read current SEO template values for an addon, with sensible defaults.
 */
function _travel_seo_read_settings(string $addonName): array
{
    // Read from CS-Cart's settings registry (authoritative after Settings API writes)
    $addonSettings = Registry::get('addons.' . $addonName) ?: [];
    $values = [];
    foreach ($addonSettings as $key => $val) {
        if (str_starts_with($key, 'seo_')) {
            $values[$key] = $val;
        }
    }

    // Defaults per addon — ensures textareas always have a value
    $defaults = [
        'novoton_holidays' => [
            'seo_product_name'          => '{{name}}',
            'seo_page_title'            => '{{name}} - {{city}}, {{country}} {{year}}',
            'seo_meta_description'      => 'Book {{name}} in {{city}}, {{country}}. {{star_rating}}-star hotel with {{facilities}}.',
            'seo_meta_keywords'         => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{star_rating}} star',
            'seo_name_slug'             => '{{name}}-{{city}}-{{country}}',
            'seo_full_description'      => '',
            'seo_overwrite_mode'        => 'override_all',
            'seo_field_product_name'    => 'Y',
            'seo_field_page_title'      => 'Y',
            'seo_field_meta_description'=> 'Y',
            'seo_field_meta_keywords'   => 'Y',
            'seo_field_name_slug'       => 'Y',
            'seo_field_full_description'=> 'Y',
        ],
        'sphinx_holidays' => [
            'seo_product_name'          => '{{name}}',
            'seo_page_title'            => '{{name}} {{classification}}* - {{city}}, {{country}}',
            'seo_meta_description'      => 'Book {{name}} in {{city}}, {{country}}. {{classification}}-star {{property_type}} with {{facilities}}.',
            'seo_meta_keywords'         => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{classification}} star',
            'seo_name_slug'             => '{{name}}-{{city}}-{{country}}',
            'seo_full_description'      => '',
            'seo_overwrite_mode'        => 'override_all',
            'seo_field_product_name'    => 'Y',
            'seo_field_page_title'      => 'Y',
            'seo_field_meta_description'=> 'Y',
            'seo_field_meta_keywords'   => 'Y',
            'seo_field_name_slug'       => 'Y',
            'seo_field_full_description'=> 'Y',
        ],
    ];

    $addonDefaults = $defaults[$addonName] ?? [];
    foreach ($addonDefaults as $key => $default) {
        if (!isset($values[$key])) {
            // Key not in DB at all — use default
            $values[$key] = $default;
        }
    }

    return $values;
}

/**
 * Ensure seo_* setting rows exist in the DB for the given addon.
 *
 * The addon.xml files only define a seo_templates_redirect_info item — the
 * actual seo_product_name, seo_page_title, etc. are NOT in addon.xml.
 * Settings::instance()->updateValue() silently does nothing if the setting
 * row doesn't exist, so we must create them on first use.
 *
 * This runs once per request (static guard) and only INSERTs missing rows.
 */
function _travel_seo_ensure_settings_exist(string $addonName): void
{
    static $ensured = [];
    if (isset($ensured[$addonName])) {
        return;
    }
    $ensured[$addonName] = true;

    // Find the seo_templates section under this addon
    $parentId = (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = ?s AND type = 'ADDON'",
        $addonName
    );
    if ($parentId <= 0) {
        return;
    }

    $sectionId = (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = 'seo_templates' AND parent_id = ?i",
        $parentId
    );

    // Create section if missing (addon upgraded without reinstall)
    if ($sectionId <= 0) {
        db_query("INSERT INTO ?:settings_sections ?e", [
            'name'         => 'seo_templates',
            'parent_id'    => $parentId,
            'edition_type' => 'ROOT',
            'type'         => 'TAB',
            'position'     => 200,
            'is_optional'  => 'N',
        ]);
        $sectionId = (int) db_get_field("SELECT LAST_INSERT_ID()");
    }

    if ($sectionId <= 0) {
        return;
    }

    // Setting definitions: name => type
    $requiredSettings = [
        'seo_product_name'          => 'I',
        'seo_page_title'            => 'I',
        'seo_meta_description'      => 'T',
        'seo_meta_keywords'         => 'I',
        'seo_name_slug'             => 'I',
        'seo_full_description'      => 'T',
        'seo_overwrite_mode'        => 'S',
        'seo_field_product_name'    => 'C',
        'seo_field_page_title'      => 'C',
        'seo_field_meta_description'=> 'C',
        'seo_field_meta_keywords'   => 'C',
        'seo_field_name_slug'       => 'C',
        'seo_field_full_description'=> 'C',
    ];

    // Check which already exist
    $existing = db_get_fields(
        "SELECT name FROM ?:settings_objects WHERE section_id = ?i AND name IN (?a)",
        $sectionId, array_keys($requiredSettings)
    );
    $existingSet = array_flip($existing ?: []);

    // INSERT only missing rows
    foreach ($requiredSettings as $name => $type) {
        if (!isset($existingSet[$name])) {
            db_query("INSERT INTO ?:settings_objects ?e", [
                'name'           => $name,
                'section_id'     => $sectionId,
                'section_tab_id' => 0,
                'type'           => $type,
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

/**
 * Save SEO template values for an addon.
 *
 * Uses direct SQL because Settings::instance()->updateValue() cannot find
 * settings that were created via raw SQL INSERT (it uses its own internal
 * registry keyed by addon.xml definitions). Since seo_* settings are NOT
 * in addon.xml, we must manage them directly.
 *
 * Flow: ensure rows exist (INSERT if missing) → UPDATE values → clear cache.
 */
function _travel_seo_save_settings(string $addonName, array $values): int
{
    // Allowed keys — only these are persisted
    $allowedKeys = [
        'seo_product_name', 'seo_page_title', 'seo_meta_description',
        'seo_meta_keywords', 'seo_name_slug', 'seo_full_description',
        'seo_overwrite_mode',
        'seo_field_product_name', 'seo_field_page_title', 'seo_field_meta_description',
        'seo_field_meta_keywords', 'seo_field_name_slug', 'seo_field_full_description',
    ];

    $values = array_intersect_key($values, array_flip($allowedKeys));
    if (empty($values)) {
        return 0;
    }

    // Ensure setting rows exist in DB (creates section + rows if missing)
    _travel_seo_ensure_settings_exist($addonName);

    // Get the section_id so we can target the correct rows
    $parentId = (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = ?s AND type = 'ADDON'",
        $addonName
    );
    $sectionId = ($parentId > 0)
        ? (int) db_get_field(
            "SELECT section_id FROM ?:settings_sections WHERE name = 'seo_templates' AND parent_id = ?i",
            $parentId
        )
        : 0;

    if ($sectionId <= 0) {
        return 0;
    }

    // Fetch object_ids for all settings in one query
    $existing = db_get_hash_single_array(
        "SELECT name, object_id FROM ?:settings_objects WHERE section_id = ?i AND name IN (?a)",
        ['name', 'object_id'],
        $sectionId, array_keys($values)
    );

    $saved = 0;
    foreach ($values as $key => $value) {
        $value = trim((string) $value);
        if (!empty($existing[$key])) {
            db_query("UPDATE ?:settings_objects SET value = ?s WHERE object_id = ?i",
                $value, (int) $existing[$key]);
            $saved++;
        }
    }

    // Clear settings cache so changes appear immediately
    Registry::del('addons.' . $addonName);
    Registry::del('settings');

    return $saved;
}

/**
 * Placeholder definitions per addon — keys + descriptions.
 */
function _travel_seo_placeholders(): array
{
    return [
        'novoton_holidays' => [
            'name'          => 'Hotel display name',
            'raw_name'      => 'Original hotel name (untranslated)',
            'city'          => 'City / resort name',
            'country'       => 'Country name',
            'region'        => 'Region name',
            'star_rating'   => 'Star rating (number)',
            'stars_emoji'   => 'Star rating as stars (e.g. ★★★★)',
            'hotel_type'    => 'Hotel type',
            'property_type' => 'Property type (hotel, apartment, etc.)',
            'year'          => 'Current year',
            'description'   => 'Hotel description text',
            'facilities'    => 'Top 3 facilities (comma-separated)',
            'min_price'     => 'Lowest package price',
            'latitude'      => 'GPS latitude',
            'longitude'     => 'GPS longitude',
        ],
        'sphinx_holidays' => [
            'name'           => 'Hotel name',
            'classification' => 'Star classification (number)',
            'stars_emoji'    => 'Star rating as stars (e.g. ★★★★)',
            'city'           => 'Destination / city name',
            'country'        => 'Country name',
            'region'         => 'Region name',
            'property_type'  => 'Property type (hotel, apartment, etc.)',
            'description'    => 'Hotel description text',
            'rating'         => 'Review rating score',
            'facilities'     => 'Top 3 facilities (comma-separated)',
            'boards'         => 'Board types (comma-separated)',
            'board_types'    => 'Board types (alias for boards)',
            'year'           => 'Current year',
            'image_url'      => 'Main image URL',
            'address'        => 'Street address',
            'phone'          => 'Phone number',
            'email'          => 'Email address',
            'website'        => 'Website URL',
            'latitude'       => 'GPS latitude',
            'longitude'      => 'GPS longitude',
        ],
    ];
}

/**
 * Modifier definitions — name + description.
 */
function _travel_seo_modifiers(): array
{
    return [
        'lower'      => 'Converts to lowercase',
        'upper'      => 'Converts to UPPERCASE',
        'title'      => 'Converts to Title Case',
        'capitalize' => 'Capitalizes first letter',
        'trim'       => 'Strips whitespace',
        'slug'       => 'URL-safe slug',
        'first'      => 'First character only',
        'last'       => 'Last character only',
        'abs'        => 'Absolute value (numbers)',
        'round'      => 'Rounds a number',
        'strip_tags' => 'Removes HTML tags',
    ];
}

// ============================================================================
// POST: Save
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'save') {
        $totalSaved = 0;

        // Save Novoton templates
        if (!empty($_REQUEST['novoton_holidays'])) {
            $totalSaved += _travel_seo_save_settings('novoton_holidays', $_REQUEST['novoton_holidays']);
        }

        // Save Sphinx templates
        if (!empty($_REQUEST['sphinx_holidays'])) {
            $totalSaved += _travel_seo_save_settings('sphinx_holidays', $_REQUEST['sphinx_holidays']);
        }

        if ($totalSaved > 0) {
            fn_set_notification('N', __('notice'), __('travel_core.seo_templates_saved'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'travel_seo_templates.manage'];
    }

    if ($mode === 'bulk_apply') {
        $addon_id = $_REQUEST['addon_id'] ?? '';
        if (!in_array($addon_id, ['novoton_holidays', 'sphinx_holidays'], true)) {
            fn_set_notification('E', __('error'), __('travel_core.invalid_addon', ['[default]' => 'Invalid addon.']));
            return [CONTROLLER_STATUS_REDIRECT, 'travel_seo_templates.manage'];
        }

        fn_set_progress('init', __('travel_core.seo_bulk_apply_progress'));

        $result = fn_travel_core_seo_bulk_apply($addon_id);

        fn_set_progress('finish');
        fn_set_notification('N', __('notice'),
            str_replace(['[updated]', '[total]'], [$result['updated'], $result['total']],
                __('travel_core.seo_bulk_apply_done'))
        );

        return [CONTROLLER_STATUS_REDIRECT, 'travel_seo_templates.manage&tab=' . $addon_id];
    }
}

// ============================================================================
// GET: Manage
// ============================================================================

if ($mode === 'manage' || empty($mode)) {
    // One-time fix: language variable contained raw <title> which broke page rendering.
    // Update cached DB values that still have unescaped HTML tags.
    db_query(
        "UPDATE ?:language_values SET value = REPLACE(value, '<title>', '&lt;title&gt;') WHERE name = 'travel_core.seo_page_title_desc' AND value LIKE '%<title>%'"
    );

    $view = Tygh::$app['view'];

    // Detect which addons are installed
    $addons = [];

    $novotonActive = Registry::get('addons.novoton_holidays.status') === 'A';
    $sphinxActive  = Registry::get('addons.sphinx_holidays.status') === 'A';

    if ($novotonActive) {
        $addons['novoton_holidays'] = [
            'label'        => 'Novoton Holidays',
            'settings'     => _travel_seo_read_settings('novoton_holidays'),
            'placeholders' => _travel_seo_placeholders()['novoton_holidays'],
        ];
    }

    if ($sphinxActive) {
        $addons['sphinx_holidays'] = [
            'label'        => 'Sphinx Holidays',
            'settings'     => _travel_seo_read_settings('sphinx_holidays'),
            'placeholders' => _travel_seo_placeholders()['sphinx_holidays'],
        ];
    }

    $view->assign('seo_addons', $addons);
    $view->assign('seo_modifiers', _travel_seo_modifiers());

    // Default tab: first addon
    $view->assign('active_tab', !empty($_REQUEST['tab']) ? $_REQUEST['tab'] : (key($addons) ?: ''));
}
