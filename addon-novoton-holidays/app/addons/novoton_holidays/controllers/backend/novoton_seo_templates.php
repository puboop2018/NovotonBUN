<?php
declare(strict_types=1);
/**
 * Novoton Holidays — SEO Templates Admin Page
 *
 * Dedicated admin page for managing SEO template strings that are applied
 * to Novoton hotel products when they are created or bulk-updated.
 *
 * Modes:
 *   - manage    (GET):  Render the form with current settings
 *   - save      (POST): Persist via CS-Cart Settings API (handles cache)
 *   - bulk_apply (POST): Re-apply templates to all existing Novoton products
 *
 * Settings are stored under addons.novoton_holidays.seo_* keys. The runtime
 * template engine (fn_travel_core_apply_seo_fields) reads them directly
 * from the Registry — no DB schema changes.
 *
 * @package NovotonHolidays
 * @since   3.4.0
 */

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Registry;
use Tygh\Settings;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/**
 * Canonical list of SEO setting keys + their defaults.
 * Single source of truth for both read and save paths.
 *
 * @return array<string, string>
 */
function _novoton_seo_setting_defaults(): array
{
    return [
        'seo_overwrite_mode'          => 'override_all',
        'seo_product_name'            => '{{name}}',
        'seo_page_title'              => '{{name}} - {{city}}, {{country}} {{year}}',
        'seo_meta_description'        => 'Book {{name}} in {{city}}, {{country}}. {{star_rating}}-star hotel with {{facilities}}.',
        'seo_meta_keywords'           => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{star_rating}} star',
        'seo_name_slug'               => '{{name}}-{{city}}-{{country}}',
        'seo_full_description'        => '',
        'seo_field_product_name'      => 'Y',
        'seo_field_page_title'        => 'Y',
        'seo_field_meta_description'  => 'Y',
        'seo_field_meta_keywords'     => 'Y',
        'seo_field_name_slug'         => 'Y',
        'seo_field_full_description'  => 'Y',
    ];
}

// ── POST handlers ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'save') {
        $submitted = $_REQUEST['seo'] ?? [];
        $defaults  = _novoton_seo_setting_defaults();
        $settings  = Settings::instance();

        $toSave = [];
        foreach ($defaults as $key => $default) {
            if (str_starts_with($key, 'seo_field_')) {
                $toSave[$key] = !empty($submitted[$key]) ? 'Y' : 'N';
            } else {
                $toSave[$key] = trim((string) ($submitted[$key] ?? ''));
            }
        }

        foreach ($toSave as $key => $value) {
            $settings->updateValue($key, $value, 'novoton_holidays', true);
        }

        $existing = \Tygh\Registry::get('addons.novoton_holidays');
        \Tygh\Registry::set('addons.novoton_holidays', array_merge(is_array($existing) ? $existing : [], $toSave));

        fn_set_notification('N', __('notice'),
            __('travel_core.seo_templates_saved',
                ['[default]' => 'SEO templates saved.']));

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_seo_templates.manage'];
    }

    if ($mode === 'bulk_apply') {
        $hotelRepo = Container::getInstance()->hotelRepository();
        $fetcher = static fn(int $offset, int $batch): array =>
            $hotelRepo->findLinkedForSeo($offset, $batch);

        $builder = static fn(array $hotel): array =>
            \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::buildNovotonPlaceholders(
                $hotel, $hotel['hotel_name'] ?? ''
            );

        return fn_travel_core_run_long_task(
            __('travel_core.seo_bulk_apply_progress'),
            static fn() => fn_travel_core_seo_bulk_apply('novoton_holidays', $fetcher, $builder),
            'novoton_seo_templates.manage',
            static function (array $result) {
                fn_set_notification('N', __('notice'),
                    str_replace(['[updated]', '[total]'], [$result['updated'], $result['total']],
                        __('travel_core.seo_bulk_apply_done')));
            }
        );
    }
}

// ── GET handlers ────────────────────────────────────────────────────────────

if ($mode === 'manage' || $mode === '') {
    $defaults = _novoton_seo_setting_defaults();
    $current  = Registry::get('addons.novoton_holidays') ?: [];

    $values = [];
    foreach ($defaults as $key => $default) {
        $stored = $current[$key] ?? null;
        $values[$key] = ($stored === null || $stored === '') && !str_starts_with($key, 'seo_field_')
            ? $default
            : ($stored ?? $default);
    }

    Tygh::$app['view']->assign('seo_values', $values);
}
