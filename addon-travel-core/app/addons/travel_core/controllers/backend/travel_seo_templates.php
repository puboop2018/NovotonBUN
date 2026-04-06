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
 * Get section_id for a specific addon's seo_templates section.
 */
function _travel_seo_get_section_id(string $addonName, string $sectionName = 'seo_templates'): int
{
    $parentId = (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = ?s AND type = 'ADDON'",
        $addonName
    );

    if ($parentId <= 0) {
        return 0;
    }

    return (int) db_get_field(
        "SELECT section_id FROM ?:settings_sections WHERE name = ?s AND parent_id = ?i",
        $sectionName, $parentId
    );
}

/**
 * Read current SEO template values for an addon.
 */
function _travel_seo_read_settings(string $addonName): array
{
    $sectionId = _travel_seo_get_section_id($addonName);
    if ($sectionId <= 0) {
        return [];
    }

    $rows = db_get_hash_single_array(
        "SELECT name, value FROM ?:settings_objects WHERE section_id = ?i AND name LIKE 'seo_%'",
        ['name', 'value'],
        $sectionId
    );

    return is_array($rows) ? $rows : [];
}

/**
 * Save SEO template values for an addon.
 */
function _travel_seo_save_settings(string $addonName, array $values): int
{
    $sectionId = _travel_seo_get_section_id($addonName);
    if ($sectionId <= 0) {
        return 0;
    }

    $saved = 0;
    $allowedKeys = ['seo_product_name', 'seo_page_title', 'seo_meta_description', 'seo_meta_keywords', 'seo_name_slug'];

    foreach ($values as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) {
            continue;
        }

        $value = trim((string) $value);

        $objectId = (int) db_get_field(
            "SELECT object_id FROM ?:settings_objects WHERE name = ?s AND section_id = ?i",
            $key, $sectionId
        );

        if ($objectId > 0) {
            db_query("UPDATE ?:settings_objects SET value = ?s WHERE object_id = ?i", $value, $objectId);
            $saved++;
        }
    }

    // Clear settings cache
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
}

// ============================================================================
// GET: Manage
// ============================================================================

if ($mode === 'manage' || empty($mode)) {
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
