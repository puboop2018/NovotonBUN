<?php
/**
 * Travel SEO Templates — Admin page for managing SEO templates across all travel addons.
 *
 * Settings are declared in each provider addon's addon.xml under the seo_templates
 * section. This controller uses CS-Cart's native Settings API for reads and writes:
 *   - Read:  Registry::get('addons.{addon_id}')
 *   - Write: Settings::instance()->updateValue($key, $value, $addon_id)
 *
 * No raw SQL, no self-bootstrap — CS-Cart handles DB rows and cache automatically.
 *
 * @package TravelCore
 * @since   1.0.0
 */

declare(strict_types=1);

use Tygh\Registry;
use Tygh\Settings;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Read current SEO template values for an addon from the Registry.
 *
 * Settings are declared in addon.xml, so CS-Cart creates the DB rows on install
 * and loads them into the Registry at boot. Registry::get() is an in-memory
 * read — zero SQL queries per page load.
 */
function _travel_seo_read_settings(string $addonName): array
{
    $addonSettings = Registry::get('addons.' . $addonName) ?: [];

    // Extract only seo_* keys
    $values = [];
    foreach ($addonSettings as $key => $val) {
        if (str_starts_with($key, 'seo_')) {
            $values[$key] = $val;
        }
    }

    // Defaults — used only if a setting has no value yet (e.g. fresh install)
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
        if (!isset($values[$key]) || $values[$key] === '') {
            $values[$key] = $default;
        }
    }

    return $values;
}

/**
 * Save SEO template values for a provider addon using the Settings API.
 *
 * Settings::instance()->updateValue() handles the DB write AND cache
 * invalidation in one call. Works because settings are declared in addon.xml.
 */
function _travel_seo_save_settings(string $addonName, array $values): int
{
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

    $settings = Settings::instance();
    $saved = 0;

    foreach ($values as $key => $value) {
        $value = trim((string) $value);
        $settings->updateValue($key, $value, $addonName);
        $saved++;
    }

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

        if (!empty($_REQUEST['novoton_holidays'])) {
            $totalSaved += _travel_seo_save_settings('novoton_holidays', $_REQUEST['novoton_holidays']);
        }

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
    $view = Tygh::$app['view'];

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
    $view->assign('active_tab', !empty($_REQUEST['tab']) ? $_REQUEST['tab'] : (key($addons) ?: ''));
}
