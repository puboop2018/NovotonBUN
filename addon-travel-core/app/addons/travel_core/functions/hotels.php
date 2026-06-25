<?php

declare(strict_types=1);

/**
 * Travel Core - Hotel Utility Functions
 *
 * Shared hotel-related utility functions used by all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Render the React booking engine mount-point HTML entirely in PHP.
 *
 * This replaces $view->fetch('booking_engine.tpl') and Smarty {include} for
 * search result pages. The Smarty template (booking_engine.tpl) is still used
 * for product detail pages and homepage blocks.
 *
 * IMPORTANT: If you add data-attributes, colors, or translations here,
 * also update design/themes/responsive/templates/addons/travel_core/blocks/booking_engine.tpl
 * to keep both implementations in sync.
 *
 * The booking engine is a stateless "empty shell" — just
 * a <div> with data-* attributes, a skeleton loader, and two <script> tags.
 * Building it in PHP avoids Smarty 5's scope chain traversal that causes
 * 256MB OOM (Data.php:265) when parent scope contains large result arrays.
 *
 * The Smarty template (booking_engine.tpl) is still used for product detail
 * pages and the homepage block where scope chain is not an issue.
 *
 * @param array<string, mixed> $params {
 * @type string $provider        'novoton' or 'sphinx'
 * @type string $search_dispatch 'novoton_booking.search' or 'sphinx_booking.search'
 * @type string $mode            'search' (default) or 'product'
 * @type array $search_params   Search params for pre-filling (check_in, check_out, etc.)
 * @type string $calendar_prices_json  Optional JSON with per-day prices
 * @type string $calendar_prices_currency  Currency code for calendar prices
 *              }
 * @return string Complete HTML string (safe for {$var nofilter} output)
 */
function fn_travel_core_render_booking_engine(array $params = []): string
{
    $vh = \Tygh\Addons\TravelCore\Helpers\ValidationHelpers::class;
    $provider = $vh::toString($params['provider'] ?? '');
    $searchDispatch = $vh::toString($params['search_dispatch'] ?? '');
    $mode = $vh::toString($params['mode'] ?? 'search');
    /** @var array<string, mixed> $sp */
    $sp = is_array($params['search_params'] ?? null) ? $params['search_params'] : [];
    $calPricesJson = $vh::toString($params['calendar_prices_json'] ?? '');
    $calPricesCurr = $vh::toString($params['calendar_prices_currency'] ?? '');

    // Colors from addon settings (bypasses Smarty completely)
    /** @var array<string, mixed> $tc */
    $tc = is_array(\Tygh\Registry::get('addons.travel_core')) ? \Tygh\Registry::get('addons.travel_core') : [];
    $colors = json_encode([
        'primary' => $vh::toString($tc['color_primary'] ?? ''),
        'accent' => $vh::toString($tc['color_accent'] ?? ''),
        'text' => $vh::toString($tc['color_text'] ?? ''),
        'textLight' => $vh::toString($tc['color_text_light'] ?? ''),
        'bg' => $vh::toString($tc['color_bg'] ?? ''),
        'border' => $vh::toString($tc['color_border'] ?? ''),
        'btnBg' => $vh::toString($tc['color_search_btn_bg'] ?? ''),
        'btnHover' => $vh::toString($tc['color_search_btn_hover'] ?? ''),
        'btnText' => $vh::toString($tc['color_search_btn_text'] ?? ''),
        'calCheapest' => $vh::toString($tc['color_cal_cheapest'] ?? ''),
        'calPrice' => $vh::toString($tc['color_cal_price'] ?? ''),
        'danger' => $vh::toString($tc['color_danger'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);

    // Translations (50+ keys — built via PHP __() instead of Smarty {__()})
    $translationKeys = [
        'availability', 'checkInDate' => 'check_in_date', 'checkOutDate' => 'check_out_date',
        'checkIn' => 'check_in', 'checkOut' => 'check_out',
        'selectDatesMessage' => 'select_dates_message',
        'search', 'changeSearch' => 'change_search', 'applyChanges' => 'apply_changes',
        'adult', 'adults', 'child', 'children', 'rooms', 'room', 'done',
        'addRoom' => 'add_room', 'adultsLabel' => 'adults_label',
        'childrenLabel' => 'children_label',
        'nightsStay' => 'nights_stay', 'nightStay' => 'night_stay',
        'night', 'nights',
        'childrenAges' => 'childrens_ages', 'childAge' => 'child_age',
        'childNAge' => 'child_n_age',
        'selectAge' => 'select_age', 'yearsOld' => 'years_old', 'yearOld' => 'year_old',
        'selected', 'selectedSingular' => 'selected_singular',
        'selectCheckOut' => 'select_check_out',
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
        'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
        'remove',
        'pleaseEnterDates' => 'please_enter_dates',
        'selectCheckIn' => 'select_check_in',
        'selectMissingAges' => 'select_missing_ages',
        'selectAgeForOneChild' => 'select_age_for_one_child',
        'selectAgeForChildren' => 'select_age_for_children',
    ];

    $translations = [];
    foreach ($translationKeys as $jsKey => $langSuffix) {
        if (is_int($jsKey)) {
            $jsKey = $langSuffix;
        }
        $translations[$jsKey] = __('travel_core.' . $langSuffix);
    }
    // Special key with default fallback
    $calFooter = __('travel_core.calendar_price_footer');
    if (empty($calFooter) || $calFooter === 'travel_core.calendar_price_footer') {
        $calFooter = 'Approximate prices in %s for a 1-night stay';
    }
    $translations['calendarPriceFooter'] = $calFooter;

    $translationsJson = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Resolve IDs
    $hotelId = htmlspecialchars($vh::toString($sp['hotel_id'] ?? ''), ENT_QUOTES);
    $productId = htmlspecialchars($vh::toString($sp['product_id'] ?? ''), ENT_QUOTES);
    $lang = $vh::toString(defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en');
    $cacheVer = $vh::toString(defined('TRAVEL_CACHE_VER') ? TRAVEL_CACHE_VER : '1');
    $baseUrl = $vh::toString(\Tygh\Registry::get('config.current_location') ?: '');

    // Build data attributes for search mode
    $searchAttrs = '';
    if ($mode === 'search' && !empty($sp)) {
        $searchAttrs = sprintf(
            ' data-check-in="%s" data-check-out="%s" data-adults="%s" data-children="%s"'
            . ' data-children-ages="%s" data-rooms="%s" data-rooms-data=\'%s\'',
            htmlspecialchars($vh::toString($sp['check_in'] ?? ''), ENT_QUOTES),
            htmlspecialchars($vh::toString($sp['check_out'] ?? ''), ENT_QUOTES),
            $vh::toInt($sp['adults'] ?? 2),
            $vh::toInt($sp['children_count'] ?? $sp['children'] ?? 0),
            htmlspecialchars($vh::toString($sp['children_ages'] ?? $sp['children_ages_str'] ?? ''), ENT_QUOTES),
            $vh::toInt($sp['num_rooms'] ?? $sp['rooms'] ?? 1),
            htmlspecialchars($vh::toString($sp['rooms_data_json'] ?? '[]'), ENT_QUOTES),
        );
    }

    // Calendar prices
    $calAttrs = '';
    if (!empty($calPricesJson) && $calPricesJson !== '{}') {
        $calAttrs = sprintf(
            ' data-calendar-prices=\'%s\' data-calendar-prices-currency="%s"',
            $calPricesJson,
            htmlspecialchars($calPricesCurr, ENT_QUOTES),
        );
    }

    // Defense-in-depth: escape JSON strings for safe HTML attribute embedding.
    // Prevents attribute injection if admin-controlled values ever contain quotes.
    $colorsAttr = htmlspecialchars((string) $colors, ENT_QUOTES, 'UTF-8');
    $translationsAttr = htmlspecialchars((string) $translationsJson, ENT_QUOTES, 'UTF-8');

    return <<<HTML
        <div id="travel-booking-root"
             data-travel-booking
             data-colors='{$colorsAttr}'
             data-search-dispatch="{$searchDispatch}"
             data-provider="{$provider}"
             data-hotel-id="{$hotelId}"
             data-product-id="{$productId}"
             data-debug="false"
             data-mode="{$mode}"
             data-lang="{$lang}"
             {$searchAttrs}
             {$calAttrs}
             data-translations='{$translationsAttr}'>
            <div class="travel-loading-state">
                <div class="nvt-skeleton-row">
                    <div class="nvt-skeleton-field nvt-skeleton-field--wide"></div>
                    <div class="nvt-skeleton-field"></div>
                    <div class="nvt-skeleton-field nvt-skeleton-field--btn"></div>
                </div>
            </div>
        </div>
        <script src="{$baseUrl}/js/addons/travel_core/react-vendor.js?v={$cacheVer}" defer></script>
        <script src="{$baseUrl}/js/addons/travel_core/react19-bundle.js?v={$cacheVer}" defer></script>
        HTML;
}

/**
 * Get or create a nested CS-Cart category tree from a path string.
 *
 * Example: "Hotels/Greece/Crete" creates/reuses three nested categories.
 * Idempotent — reuses existing categories by name match at each level.
 *
 * @param string $path Forward-slash-delimited category path
 * @return int Leaf category_id, or 0 on failure
 */
function fn_travel_core_get_or_create_category(string $path): int
{
    $parts = array_filter(array_map('trim', explode('/', $path)));
    if (empty($parts)) {
        return 0;
    }

    $parent_id = 0;

    foreach ($parts as $part) {
        $category_id = TypeCoerce::toInt(db_get_field(
            'SELECT c.category_id FROM ?:categories c
             JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
             WHERE c.parent_id = ?i AND cd.category = ?s
             LIMIT 1',
            CART_LANGUAGE,
            $parent_id,
            $part,
        ));

        if ($category_id > 0) {
            $parent_id = $category_id;
            continue;
        }

        // Inherit company_id from parent category (required in frontend/cron context
        // where Registry::get('runtime.company_id') may not be set)
        $company_id = ($parent_id > 0)
            ? TypeCoerce::toInt(db_get_field('SELECT company_id FROM ?:categories WHERE category_id = ?i', $parent_id))
            : 0;

        // Create new category
        $category_data = [
            'category' => $part,
            'parent_id' => $parent_id,
            'company_id' => $company_id,
            'status' => 'A',
        ];

        $category_id = TypeCoerce::toInt(fn_update_category($category_data, 0, CART_LANGUAGE));

        if ($category_id <= 0) {
            fn_log_event('general', 'runtime', [
                'message' => "travel_core: fn_update_category() returned 0 for part='{$part}', parent_id={$parent_id}, company_id={$company_id}, path='{$path}'",
            ]);
            return 0;
        }

        // Ensure descriptions exist for all active languages
        $languages = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
        foreach ($languages as $lang_code) {
            if ($lang_code === CART_LANGUAGE) {
                continue; // Already created by fn_update_category
            }
            db_query(
                'INSERT INTO ?:category_descriptions (category_id, lang_code, category)
                 VALUES (?i, ?s, ?s)
                 ON DUPLICATE KEY UPDATE category = ?s',
                $category_id,
                $lang_code,
                $part,
                $part,
            );
        }

        $parent_id = $category_id;
    }

    return $parent_id;
}

/**
 * Get or create a single child category under a given parent.
 *
 * Uses parent_id directly — no path parsing needed.
 * Idempotent: reuses an existing category if name matches under parent.
 *
 * @param int $parent_id Parent category_id (must already exist)
 * @param string $name Category name (e.g. "Turkey")
 * @return int The child category_id, or 0 on failure
 */
function fn_travel_core_get_or_create_child_category(int $parent_id, string $name): int
{
    $name = trim($name);
    if ($name === '' || $parent_id <= 0) {
        return 0;
    }

    // Look for existing category by name under this parent
    $category_id = TypeCoerce::toInt(db_get_field(
        'SELECT c.category_id FROM ?:categories c
         JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
         WHERE c.parent_id = ?i AND cd.category = ?s
         LIMIT 1',
        CART_LANGUAGE,
        $parent_id,
        $name,
    ));

    if ($category_id > 0) {
        return $category_id;
    }

    // Inherit company_id from parent category (required in frontend/cron context
    // where Registry::get('runtime.company_id') may not be set)
    $company_id = TypeCoerce::toInt(db_get_field(
        'SELECT company_id FROM ?:categories WHERE category_id = ?i',
        $parent_id,
    ));

    // Create new category under parent
    $category_data = [
        'category' => $name,
        'parent_id' => $parent_id,
        'company_id' => $company_id,
        'status' => 'A',
    ];

    $category_id = TypeCoerce::toInt(fn_update_category($category_data, 0, CART_LANGUAGE));

    if ($category_id <= 0) {
        fn_log_event('general', 'runtime', [
            'message' => "travel_core: fn_update_category() returned 0 for name='{$name}', parent_id={$parent_id}, company_id={$company_id}",
        ]);
        return 0;
    }

    // Ensure descriptions exist for all active languages
    $languages = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
    foreach ($languages as $lang_code) {
        if ($lang_code === CART_LANGUAGE) {
            continue;
        }
        db_query(
            'INSERT INTO ?:category_descriptions (category_id, lang_code, category)
             VALUES (?i, ?s, ?s)
             ON DUPLICATE KEY UPDATE category = ?s',
            $category_id,
            $lang_code,
            $name,
            $name,
        );
    }

    return $category_id;
}

/**
 * Render an SEO template by replacing {{placeholder}} tokens with data values.
 *
 * Supports scalar values and arrays (arrays are joined as comma-separated,
 * limited to the first 3 items). Leftover unreplaced tokens are removed.
/**
 * Apply a text modifier to a value.
 *
 * Supported modifiers: lower, upper, title, capitalize, trim, slug,
 * first, last, abs, round, strip_tags.
 *
 * Usage in templates: {{name|upper}}, {{price|round}}, {{city|title}}
 *
 * @param string $value The raw placeholder value
 * @param string $modifier Modifier name (case-insensitive)
 * @return string Modified value
 */
function fn_travel_core_apply_modifier(string $value, string $modifier): string
{
    return match (strtolower($modifier)) {
        'lower' => mb_strtolower($value, 'UTF-8'),
        'upper' => mb_strtoupper($value, 'UTF-8'),
        'title' => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
        'capitalize' => mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8'),
        'trim' => trim($value),
        'slug' => TypeCoerce::toString(function_exists('fn_generate_seo_name') ? fn_generate_seo_name($value) : preg_replace('/-{2,}/', '-', trim((string) preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower($value, 'UTF-8')), '-'))),
        'first' => mb_substr($value, 0, 1, 'UTF-8'),
        'last' => mb_substr($value, -1, 1, 'UTF-8'),
        'abs' => (string) abs((float) $value),
        'round' => (string) round((float) $value),
        'strip_tags' => strip_tags($value),
        default => $value,
    };
}

/**
 * Truncate a string at a word boundary, appending ellipsis if needed.
 *
 * @param string $value The string to truncate
 * @param int $maxLength Maximum length (0 = no limit)
 * @param string $ellipsis Suffix when truncated (default: empty)
 * @return string Truncated string
 */
function fn_travel_core_truncate_seo(string $value, int $maxLength, string $ellipsis = ''): string
{
    if ($maxLength <= 0 || mb_strlen($value, 'UTF-8') <= $maxLength) {
        return $value;
    }

    $cut = mb_substr($value, 0, $maxLength - mb_strlen($ellipsis, 'UTF-8'), 'UTF-8');
    // Find last space to avoid cutting mid-word
    $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($lastSpace !== false && $lastSpace > $maxLength * 0.6) {
        $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
    }

    return rtrim($cut, ' .,;:-') . $ellipsis;
}

/**
 * Build a star rating emoji string (e.g., 4 → "★★★★").
 *
 * @param int $stars Number of stars (0-5)
 * @return string Unicode star characters
 */
function fn_travel_core_build_star_emoji(int $stars): string
{
    return str_repeat('★', max(0, min(5, $stars)));
}

/**
 * Render an SEO template by replacing {{placeholder}} tokens with values.
 *
 * Supports pipe modifiers: {{name|upper}}, {{city|lower}}, {{price|round}}.
 * Arrays are joined as comma-separated (first 3 items).
 * Leftover unreplaced tokens are removed.
 * Dangling separators are cleaned up.
 * Extra spaces are collapsed.
 *
 * @param string $pattern Template string with {{placeholder}} tokens
 * @param array<string, mixed> $placeholders Key => value map (keys without braces)
 * @return string Rendered string, trimmed
 */
function fn_travel_core_render_seo_template(string $pattern, array $placeholders): string
{
    if ($pattern === '') {
        return '';
    }

    // Resolve array placeholders to strings upfront
    $resolved = [];
    foreach ($placeholders as $key => $value) {
        if (is_array($value)) {
            $resolved[$key] = implode(', ', array_slice(array_filter(array_map(
                static fn ($item): string => trim(TypeCoerce::toString($item)),
                $value,
            )), 0, 3));
        } else {
            $resolved[$key] = TypeCoerce::toString($value);
        }
    }

    // Replace {{key}} and {{key|modifier}} in one pass
    $result = (string) preg_replace_callback(
        '/\{\{([a-z_][a-z0-9_]*)(?:\|([a-z_]+))?}}/',
        function ($m) use ($resolved) {
            $value = $resolved[$m[1]] ?? '';
            if (isset($m[2])) {
                $value = fn_travel_core_apply_modifier($value, $m[2]);
            }
            return $value;
        },
        $pattern,
    );

    // Clean up dangling separators left by empty placeholders
    $result = (string) preg_replace('/,\s*,/', ',', $result);           // collapse double commas
    $result = (string) preg_replace('/\s*-\s*,/', ',', $result);        // "- ," → ","
    $result = (string) preg_replace('/,\s*-\s*/', ' - ', $result);      // ", -" → " - "
    $result = (string) preg_replace('/^\s*[-,]\s*/', '', $result);       // leading separator
    $result = (string) preg_replace('/\s*[-,]\s*$/', '', $result);       // trailing separator
    $result = (string) preg_replace('/\(\s*\)/', '', $result);           // empty parentheses
    $result = (string) preg_replace('/\s*-\s*-\s*/', ' - ', $result);   // double dashes

    // Collapse multiple spaces and trim
    return trim((string) preg_replace('/\s{2,}/', ' ', $result));
}

/**
 * Render an SEO template and convert the result to a URL-safe slug.
 *
 * @param string $pattern Template string with {{placeholder}} tokens
 * @param array<string, mixed> $placeholders Key => value map (keys without braces)
 * @return string URL-safe slug
 */
function fn_travel_core_render_seo_slug(string $pattern, array $placeholders): string
{
    $rendered = fn_travel_core_render_seo_template($pattern, $placeholders);
    if ($rendered === '') {
        return '';
    }

    // Use CS-Cart's built-in SEO name generator if available
    if (function_exists('fn_generate_seo_name')) {
        return TypeCoerce::toString(fn_generate_seo_name($rendered));
    }

    // Fallback: basic slug generation
    $slug = mb_strtolower($rendered, 'UTF-8');
    $slug = (string) preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = (string) preg_replace('/-{2,}/', '-', $slug); // collapse multiple dashes
    return trim($slug, '-');
}

// ============================================================================
// SEO Field Application — shared by all provider addons
// ============================================================================

/**
 * Field mapping: setting key → [template registry key, product_data key].
 */
/** @return array<string, array{0: string, 1: string}> */
function _travel_core_seo_field_map(): array
{
    return [
        'seo_field_product_name' => ['seo_product_name',     'product'],
        'seo_field_page_title' => ['seo_page_title',       'page_title'],
        'seo_field_meta_description' => ['seo_meta_description', 'meta_description'],
        'seo_field_meta_keywords' => ['seo_meta_keywords',    'meta_keywords'],
        'seo_field_name_slug' => ['seo_name_slug',        'seo_name'],
        'seo_field_full_description' => ['seo_full_description', 'full_description'],
    ];
}

/**
 * Apply SEO template fields to a product, respecting overwrite mode and field toggles.
 *
 * Returns only the product_data keys that should be written — callers merge
 * this into their own product data array before calling fn_update_product().
 *
 * @param string $addonName 'novoton_holidays' or 'sphinx_holidays'
 * @param array<string, mixed> $placeholders Key => value map for template rendering
 * @param int $productId 0 = new product (all enabled fields applied), >0 = existing
 * @param string|null $hotelId For unique slug generation (SphinxProductFactory pattern)
 * @return array<string, mixed> Product data keys to merge into fn_update_product()
 */
function fn_travel_core_apply_seo_fields(string $addonName, array $placeholders, int $productId = 0, ?string $hotelId = null): array
{
    $settings = TypeCoerce::toStringMap(\Tygh\Registry::get('addons.' . $addonName));

    // Built-in template defaults exposed by the provider addon's func.php
    // (fn_<addon>_seo_defaults). func.php is loaded in every AREA — including
    // the storefront cron context that creates products — so these defaults are
    // available even when the seo_* settings were never persisted to the DB.
    // Used only as a fallback for blank/absent settings; an admin-saved
    // template always takes precedence.
    $defaults = [];
    $defaultsFn = 'fn_' . $addonName . '_seo_defaults';
    if (function_exists($defaultsFn)) {
        $resolved = $defaultsFn();
        if (is_array($resolved)) {
            $defaults = $resolved;
        }
    }

    $overwriteMode = \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::tryFrom(
        TypeCoerce::toString(($settings['seo_overwrite_mode'] ?? '') ?: (($defaults['seo_overwrite_mode'] ?? '') ?: 'override_all')),
    ) ?? \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::OverrideAll;
    $fillIfEmpty = ($overwriteMode === \Tygh\Addons\TravelCore\Enums\SeoOverwriteMode::FillIfEmpty) && ($productId > 0);

    // Load current product values once (only when needed for fill_if_empty)
    $current = [];
    $currentSlug = '';
    if ($fillIfEmpty) {
        $current = TypeCoerce::toStringMap(db_get_row(
            'SELECT product, page_title, meta_description, meta_keywords, full_description
             FROM ?:product_descriptions
             WHERE product_id = ?i AND lang_code = ?s',
            $productId,
            CART_LANGUAGE,
        ));
        $currentSlug = TypeCoerce::toString(db_get_field(
            "SELECT name FROM ?:seo_names WHERE object_id = ?i AND type = 'p' LIMIT 1",
            $productId,
        ));
    }

    $result = [];
    $fieldMap = _travel_core_seo_field_map();

    foreach ($fieldMap as $toggleKey => [$templateKey, $productKey]) {
        // Check field toggle (default Y for backward compat)
        $enabled = ($settings[$toggleKey] ?? '') ?: (($defaults[$toggleKey] ?? '') ?: 'Y');
        if ($enabled !== 'Y') {
            continue;
        }

        // Fill-if-empty: skip if existing value is non-empty
        if ($fillIfEmpty) {
            $existingValue = ($productKey === 'seo_name')
                ? $currentSlug
                : TypeCoerce::toString($current[$productKey] ?? '');
            if (trim($existingValue) !== '') {
                continue;
            }
        }

        // Read template pattern from addon settings, falling back to the
        // addon's built-in default when the stored value is blank/absent.
        $template = TypeCoerce::toString($settings[$templateKey] ?? '');
        if ($template === '' && isset($defaults[$templateKey]) && is_string($defaults[$templateKey])) {
            $template = $defaults[$templateKey];
        }

        // Render the field
        if ($productKey === 'seo_name') {
            $rendered = fn_travel_core_render_seo_slug($template, $placeholders);
            // Ensure uniqueness for existing or new products
            if ($hotelId !== null && function_exists('fn_generate_seo_name')) {
                // Check for duplicates (append hotel_id suffix if needed)
                $existing = db_get_field(
                    "SELECT object_id FROM ?:seo_names WHERE name = ?s AND type = 'p' AND object_id != ?i LIMIT 1",
                    $rendered,
                    $productId,
                );
                if ($existing) {
                    $rendered .= '-' . preg_replace('/[^a-z0-9]/', '', strtolower($hotelId));
                }
            }
            $result[$productKey] = $rendered;
        } elseif ($productKey === 'full_description') {
            // Special: if template is empty, fall back to raw description placeholder
            if ($template !== '') {
                $result[$productKey] = fn_travel_core_render_seo_template($template, $placeholders);
            } else {
                $result[$productKey] = TypeCoerce::toString($placeholders['description'] ?? '');
            }
        } else {
            // Skip empty templates — don't write blank strings that would erase
            // values an admin or a previous run already populated.
            if ($template === '') {
                continue;
            }
            $result[$productKey] = fn_travel_core_render_seo_template($template, $placeholders);
        }
    }

    return $result;
}

/**
 * Bulk-apply SEO templates to all existing hotel products for an addon.
 *
 * Respects overwrite mode and field toggles. Uses fn_set_progress() for
 * CS-Cart's native progress bar in the admin panel.
 *
 * Provider addons supply their own data-fetching and placeholder-building
 * callables, keeping travel_core free of addon-specific SQL and class refs.
 *
 * @param string $addonName 'novoton_holidays' or 'sphinx_holidays'
 * @param callable $hotelFetcher fn(int $offset, int $batchSize): array — returns hotel rows
 * @param callable $placeholderBuilder fn(array $hotel): array — returns placeholder map
 * @return array{updated: int, skipped: int, total: int}
 */
function fn_travel_core_seo_bulk_apply(string $addonName, callable $hotelFetcher, callable $placeholderBuilder): array
{
    $updated = 0;
    $skipped = 0;
    $total = 0;
    $batchSize = 200;
    $offset = 0;

    // Apply to every installed storefront language so RO and EN (or whatever
    // is configured) both get the rendered SEO data — not just whatever the
    // admin happens to have selected in the top-right language dropdown.
    $languages = [CART_LANGUAGE];
    if (function_exists('fn_get_translation_languages')) {
        $allLangs = fn_get_translation_languages();
        if (is_array($allLangs) && !empty($allLangs)) {
            $languages = array_keys($allLangs);
        }
    }

    while (true) {
        $hotels = TypeCoerce::toRowList($hotelFetcher($offset, $batchSize));

        if (empty($hotels)) {
            break;
        }

        foreach ($hotels as $hotel) {
            $total++;
            $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
            $placeholders = TypeCoerce::toStringMap($placeholderBuilder($hotel));
            $hotelId = isset($hotel['hotel_id']) ? TypeCoerce::toString($hotel['hotel_id']) : null;

            $seoFields = fn_travel_core_apply_seo_fields($addonName, $placeholders, $productId, $hotelId);

            if (empty($seoFields)) {
                $skipped++;
            } else {
                foreach ($languages as $lang) {
                    fn_update_product($seoFields, $productId, $lang);
                }
                $updated++;
            }

            if (function_exists('fn_set_progress')) {
                fn_set_progress('echo', TypeCoerce::toString($hotel['hotel_name'] ?? $hotel['name'] ?? $hotel['hotel_id'] ?? '') . ' — ' . ($seoFields !== [] ? 'updated' : 'skipped'));
            }
        }

        $offset += $batchSize;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'total' => $total];
}

/**
 * Run a long-running admin task with progress bar and redirect.
 *
 * Wraps the common controller boilerplate: set_time_limit, progress init/finish,
 * notification, and redirect. Returns a CS-Cart controller redirect array.
 *
 * @param string $progressLabel Translation key for the progress bar
 * @param callable $task fn(): mixed — the work to execute
 * @param string $redirectUrl CS-Cart dispatch URL to redirect to after completion
 * @param callable|null $onResult fn(mixed $result): void — optional post-task notification
 * @return array{0: string, 1: string} CS-Cart [CONTROLLER_STATUS_REDIRECT, $url]
 */
function fn_travel_core_run_long_task(string $progressLabel, callable $task, string $redirectUrl, ?callable $onResult = null): array
{
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    fn_set_progress('init', $progressLabel);
    $result = $task();
    fn_set_progress('finish');

    if ($onResult !== null) {
        $onResult($result);
    }

    return [TypeCoerce::toString(CONTROLLER_STATUS_REDIRECT), $redirectUrl];
}

/**
 * Attach one or more public image URLs to a CS-Cart product via fn_update_product.
 *
 * CS-Cart's pipeline (fn_attach_image_pairs → fn_filter_uploaded_data → fn_get_url_data)
 * downloads, resizes, and stores each image through Storage::instance('images')->put().
 * No temp-file management or session shim required.
 *
 * Use this for all publicly accessible image URLs (no auth headers needed).
 * For auth-required downloads (e.g. Sphinx API-hosted), use fn_travel_core_attach_product_image.
 *
 * @param int          $productId    CS-Cart product ID
 * @param list<string> $urls         List of image URLs. Index 0 = main image when $firstIsMain is true.
 * @param bool         $firstIsMain  True to set URL[0] as the main (M) product image
 * @return int  Number of images actually attached (images_links rows added). 0 means
 *              nothing was stored — fn_update_product reports no error for unreachable
 *              URLs, so the row-count delta is the only reliable success signal.
 */
function fn_travel_core_attach_images_from_urls(int $productId, array $urls, bool $firstIsMain = true): int
{
    if ($productId <= 0 || empty($urls)) {
        return 0;
    }

    $before = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $productId,
    ));

    // Build the request payload in local arrays first, then assign each $_REQUEST key
    // once. Writing nested offsets directly onto $_REQUEST (mixed) is not statically
    // analysable; whole-array assignment to a single key is.
    $mainData = $mainType = $mainFile = [];
    $addData  = $addType  = $addFile  = [];

    $addIdx = 0;
    foreach ($urls as $i => $url) {
        if ($url === '') {
            continue;
        }

        if ($firstIsMain && $i === 0) {
            $mainData[0] = ['type' => 'M', 'object_id' => $productId, 'position' => 0];
            $mainType[0] = 'url';
            $mainFile[0] = $url;
        } else {
            $addData[$addIdx] = ['type' => 'A', 'object_id' => $productId, 'position' => $addIdx];
            $addType[$addIdx] = 'url';
            $addFile[$addIdx] = $url;
            $addIdx++;
        }
    }

    if ($mainData !== []) {
        $_REQUEST['product_main_image_data']          = $mainData;
        $_REQUEST['type_product_main_image_detailed'] = $mainType;
        $_REQUEST['file_product_main_image_detailed'] = $mainFile;
    }
    if ($addData !== []) {
        $_REQUEST['product_add_additional_image_data']          = $addData;
        $_REQUEST['type_product_add_additional_image_detailed'] = $addType;
        $_REQUEST['file_product_add_additional_image_detailed'] = $addFile;
    }

    fn_update_product([], $productId, CART_LANGUAGE);

    $after = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt(db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $productId,
    ));
    $attached = max(0, $after - $before);

    unset(
        $_REQUEST['product_main_image_data'],
        $_REQUEST['type_product_main_image_detailed'],
        $_REQUEST['file_product_main_image_detailed'],
        $_REQUEST['product_add_additional_image_data'],
        $_REQUEST['type_product_add_additional_image_detailed'],
        $_REQUEST['file_product_add_additional_image_detailed'],
    );

    // Only record the success breadcrumb if rows were actually added — fn_update_product
    // silently no-ops on unreachable URLs, so a path marker without a delta would lie.
    if ($attached > 0) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachPath = 'url/fn_update_product';
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::log(
            'image attach OK [url/fn_update_product]',
            ['product_id' => $productId, 'requested' => count($urls), 'attached' => $attached],
        );
    }

    return $attached;
}

/**
 * Attach a pre-downloaded image temp file to a CS-Cart product.
 *
 * Use this only when the image requires custom auth headers and must be downloaded
 * manually via cURL before attachment (e.g. Sphinx API-hosted images).
 * For public URLs, prefer fn_travel_core_attach_images_from_urls().
 *
 * @param int    $productId CS-Cart product ID
 * @param string $tempFile  Path to already-downloaded temp file (will be unlinked)
 * @param string $prefix    Filename prefix (e.g. 'sphinx')
 * @param bool   $isMain    True for main product image, false for additional
 * @return bool True on success
 */
function fn_travel_core_attach_product_image(int $productId, string $tempFile, string $prefix, bool $isMain = false): bool
{
    \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = '';

    if ($productId <= 0) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = 'attach: invalid product_id';
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        return false;
    }
    if (!file_exists($tempFile)) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = 'attach: temp file missing';
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        return false;
    }
    $tempSize = (int) filesize($tempFile);
    if ($tempSize < 1000) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = "attach: file too small ({$tempSize} bytes)";
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        unlink($tempFile);
        return false;
    }

    try {
        $imageInfo = getimagesize($tempFile);
    } catch (\Throwable) {
        $imageInfo = false;
    }

    if ($imageInfo === false) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = 'attach: getimagesize failed (not a valid image)';
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        unlink($tempFile);
        return false;
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $ext = $mimeToExt[$imageInfo['mime']] ?? 'jpg';
    $filename = "{$prefix}_hotel_{$productId}_" . time() . '_' . random_int(100, 999) . ".{$ext}";

    $existingPairs = TypeCoerce::toInt(db_get_field(
        "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product'",
        $productId,
    ));

    // fn_update_image_pairs: canonical import/API attach path. Does not touch $_FILES or
    // session. The loop is driven by $pairsData (arg 3) — both $detailed and $pairsData
    // must share the same key (0) or the loop runs zero times and returns [].
    // fn_update_image() reads 'path' (absolute) + 'name' via Storage::put() — no is_uploaded_file().
    $detailed = [
        0 => [
            'name' => $filename,
            'path' => $tempFile,
            'size' => $tempSize,
            'type' => $imageInfo['mime'],
        ],
    ];
    $pairsData = [
        0 => [
            'pair_id'  => 0,
            'type'     => $isMain ? 'M' : 'A',
            'position' => $existingPairs,
        ],
    ];

    // $icons (arg 1) is empty — CS-Cart auto-generates the thumbnail from $detailed.
    $pairIds = fn_update_image_pairs([], $detailed, $pairsData, $productId, 'product');

    // fn_update_image_pairs copies the source via Storage::put() and leaves it in place,
    // so the temp file is still present here — remove it unconditionally.
    unlink($tempFile);

    if (empty($pairIds)) {
        \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError = "attach: fn_update_image_pairs returned no pair (object_id={$productId}, size={$tempSize}, mime={$imageInfo['mime']})";
        fn_log_event('general', 'runtime', ['message' => \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError]);
        return false;
    }

    \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachPath = 'curl/fn_update_image_pairs';
    \Tygh\Addons\TravelCore\Helpers\DebugLogger::log(
        'image attach OK [curl/fn_update_image_pairs]',
        ['product_id' => $productId, 'type' => $isMain ? 'M' : 'A', 'size' => $tempSize],
    );

    return true;
}
