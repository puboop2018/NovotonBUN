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

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

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
 * @param array $params {
 *     @type string $provider        'novoton' or 'sphinx'
 *     @type string $search_dispatch 'novoton_booking.search' or 'sphinx_booking.search'
 *     @type string $mode            'search' (default) or 'product'
 *     @type array  $search_params   Search params for pre-filling (check_in, check_out, etc.)
 *     @type string $calendar_prices_json  Optional JSON with per-day prices
 *     @type string $calendar_prices_currency  Currency code for calendar prices
 * }
 * @return string Complete HTML string (safe for {$var nofilter} output)
 */
function fn_travel_core_render_booking_engine(array $params = []): string
{
    $provider       = $params['provider'] ?? '';
    $searchDispatch = $params['search_dispatch'] ?? '';
    $mode           = $params['mode'] ?? 'search';
    $sp             = $params['search_params'] ?? [];
    $calPricesJson  = $params['calendar_prices_json'] ?? '';
    $calPricesCurr  = $params['calendar_prices_currency'] ?? '';

    // Colors from addon settings (bypasses Smarty completely)
    $tc = \Tygh\Registry::get('addons.travel_core') ?: [];
    $colors = json_encode([
        'primary'      => $tc['color_primary'] ?? '',
        'accent'       => $tc['color_accent'] ?? '',
        'text'         => $tc['color_text'] ?? '',
        'textLight'    => $tc['color_text_light'] ?? '',
        'bg'           => $tc['color_bg'] ?? '',
        'border'       => $tc['color_border'] ?? '',
        'btnBg'        => $tc['color_search_btn_bg'] ?? '',
        'btnHover'     => $tc['color_search_btn_hover'] ?? '',
        'btnText'      => $tc['color_search_btn_text'] ?? '',
        'calCheapest'  => $tc['color_cal_cheapest'] ?? '',
        'calPrice'     => $tc['color_cal_price'] ?? '',
        'danger'       => $tc['color_danger'] ?? '',
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
    $hotelId   = htmlspecialchars($sp['hotel_id'] ?? '', ENT_QUOTES);
    $productId = htmlspecialchars((string)($sp['product_id'] ?? ''), ENT_QUOTES);
    $lang      = defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en';
    $cacheVer  = defined('TRAVEL_CACHE_VER') ? TRAVEL_CACHE_VER : '1';
    $baseUrl   = \Tygh\Registry::get('config.current_location') ?: '';

    // Build data attributes for search mode
    $searchAttrs = '';
    if ($mode === 'search' && !empty($sp)) {
        $searchAttrs = sprintf(
            ' data-check-in="%s" data-check-out="%s" data-adults="%s" data-children="%s"'
            . ' data-children-ages="%s" data-rooms="%s" data-rooms-data=\'%s\'',
            htmlspecialchars($sp['check_in'] ?? '', ENT_QUOTES),
            htmlspecialchars($sp['check_out'] ?? '', ENT_QUOTES),
            (int)($sp['adults'] ?? 2),
            (int)($sp['children_count'] ?? $sp['children'] ?? 0),
            htmlspecialchars($sp['children_ages'] ?? $sp['children_ages_str'] ?? '', ENT_QUOTES),
            (int)($sp['num_rooms'] ?? $sp['rooms'] ?? 1),
            htmlspecialchars($sp['rooms_data_json'] ?? '[]', ENT_QUOTES)
        );
    }

    // Calendar prices
    $calAttrs = '';
    if (!empty($calPricesJson) && $calPricesJson !== '{}') {
        $calAttrs = sprintf(
            ' data-calendar-prices=\'%s\' data-calendar-prices-currency="%s"',
            $calPricesJson,
            htmlspecialchars($calPricesCurr, ENT_QUOTES)
        );
    }

    // Defense-in-depth: escape JSON strings for safe HTML attribute embedding.
    // Prevents attribute injection if admin-controlled values ever contain quotes.
    $colorsAttr = htmlspecialchars($colors, ENT_QUOTES, 'UTF-8');
    $translationsAttr = htmlspecialchars($translationsJson, ENT_QUOTES, 'UTF-8');

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
        $category_id = (int) db_get_field(
            "SELECT c.category_id FROM ?:categories c
             JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
             WHERE c.parent_id = ?i AND cd.category = ?s
             LIMIT 1",
            CART_LANGUAGE, $parent_id, $part
        );

        if ($category_id > 0) {
            $parent_id = $category_id;
            continue;
        }

        // Inherit company_id from parent category (required in frontend/cron context
        // where Registry::get('runtime.company_id') may not be set)
        $company_id = ($parent_id > 0)
            ? (int) db_get_field("SELECT company_id FROM ?:categories WHERE category_id = ?i", $parent_id)
            : 0;

        // Create new category
        $category_data = [
            'category'   => $part,
            'parent_id'  => $parent_id,
            'company_id' => $company_id,
            'status'     => 'A',
        ];

        $category_id = (int) fn_update_category($category_data, 0, CART_LANGUAGE);

        if ($category_id <= 0) {
            fn_log_event('general', 'runtime', [
                'message' => "travel_core: fn_update_category() returned 0 for part='{$part}', parent_id={$parent_id}, company_id={$company_id}, path='{$path}'",
            ]);
            return 0;
        }

        // Ensure descriptions exist for all active languages
        $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
        foreach ($languages as $lang_code) {
            if ($lang_code === CART_LANGUAGE) {
                continue; // Already created by fn_update_category
            }
            db_query(
                "INSERT INTO ?:category_descriptions (category_id, lang_code, category)
                 VALUES (?i, ?s, ?s)
                 ON DUPLICATE KEY UPDATE category = ?s",
                $category_id, $lang_code, $part, $part
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
 * @param int    $parent_id Parent category_id (must already exist)
 * @param string $name      Category name (e.g. "Turkey")
 * @return int              The child category_id, or 0 on failure
 */
function fn_travel_core_get_or_create_child_category(int $parent_id, string $name): int
{
    $name = trim($name);
    if ($name === '' || $parent_id <= 0) {
        return 0;
    }

    // Look for existing category by name under this parent
    $category_id = (int) db_get_field(
        "SELECT c.category_id FROM ?:categories c
         JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s
         WHERE c.parent_id = ?i AND cd.category = ?s
         LIMIT 1",
        CART_LANGUAGE, $parent_id, $name
    );

    if ($category_id > 0) {
        return $category_id;
    }

    // Inherit company_id from parent category (required in frontend/cron context
    // where Registry::get('runtime.company_id') may not be set)
    $company_id = (int) db_get_field(
        "SELECT company_id FROM ?:categories WHERE category_id = ?i",
        $parent_id
    );

    // Create new category under parent
    $category_data = [
        'category'   => $name,
        'parent_id'  => $parent_id,
        'company_id' => $company_id,
        'status'     => 'A',
    ];

    $category_id = (int) fn_update_category($category_data, 0, CART_LANGUAGE);

    if ($category_id <= 0) {
        fn_log_event('general', 'runtime', [
            'message' => "travel_core: fn_update_category() returned 0 for name='{$name}', parent_id={$parent_id}, company_id={$company_id}",
        ]);
        return 0;
    }

    // Ensure descriptions exist for all active languages
    $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
    foreach ($languages as $lang_code) {
        if ($lang_code === CART_LANGUAGE) {
            continue;
        }
        db_query(
            "INSERT INTO ?:category_descriptions (category_id, lang_code, category)
             VALUES (?i, ?s, ?s)
             ON DUPLICATE KEY UPDATE category = ?s",
            $category_id, $lang_code, $name, $name
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
 * @param string $value    The raw placeholder value
 * @param string $modifier Modifier name (case-insensitive)
 * @return string Modified value
 */
function fn_travel_core_apply_modifier(string $value, string $modifier): string
{
    return match (strtolower($modifier)) {
        'lower'      => mb_strtolower($value, 'UTF-8'),
        'upper'      => mb_strtoupper($value, 'UTF-8'),
        'title'      => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
        'capitalize' => mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8'),
        'trim'       => trim($value),
        'slug'       => function_exists('fn_generate_seo_name') ? fn_generate_seo_name($value) : preg_replace('/-{2,}/', '-', trim(preg_replace('/[^a-z0-9\-]+/', '-', mb_strtolower($value, 'UTF-8')), '-')),
        'first'      => mb_substr($value, 0, 1, 'UTF-8'),
        'last'       => mb_substr($value, -1, 1, 'UTF-8'),
        'abs'        => (string) abs((float) $value),
        'round'      => (string) round((float) $value),
        'strip_tags' => strip_tags($value),
        default      => $value,
    };
}

/**
 * Truncate a string at a word boundary, appending ellipsis if needed.
 *
 * @param string $value     The string to truncate
 * @param int    $maxLength Maximum length (0 = no limit)
 * @param string $ellipsis  Suffix when truncated (default: empty)
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
 * @param string $pattern       Template string with {{placeholder}} tokens
 * @param array  $placeholders  Key => value map (keys without braces)
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
            $resolved[$key] = implode(', ', array_slice(array_filter(array_map('trim', $value)), 0, 3));
        } else {
            $resolved[$key] = (string) $value;
        }
    }

    // Replace {{key}} and {{key|modifier}} in one pass
    $result = preg_replace_callback(
        '/\{\{([a-z_][a-z0-9_]*)(?:\|([a-z_]+))?\}\}/',
        function ($m) use ($resolved) {
            $value = $resolved[$m[1]] ?? '';
            if (isset($m[2]) && $m[2] !== '') {
                $value = fn_travel_core_apply_modifier($value, $m[2]);
            }
            return $value;
        },
        $pattern
    );

    // Clean up dangling separators left by empty placeholders
    $result = preg_replace('/,\s*,/', ',', $result);           // collapse double commas
    $result = preg_replace('/\s*-\s*,/', ',', $result);        // "- ," → ","
    $result = preg_replace('/,\s*-\s*/', ' - ', $result);      // ", -" → " - "
    $result = preg_replace('/^\s*[-,]\s*/', '', $result);       // leading separator
    $result = preg_replace('/\s*[-,]\s*$/', '', $result);       // trailing separator
    $result = preg_replace('/\(\s*\)/', '', $result);           // empty parentheses
    $result = preg_replace('/\s*-\s*-\s*/', ' - ', $result);   // double dashes

    // Collapse multiple spaces and trim
    return trim(preg_replace('/\s{2,}/', ' ', $result));
}

/**
 * Render an SEO template and convert the result to a URL-safe slug.
 *
 * @param string $pattern       Template string with {{placeholder}} tokens
 * @param array  $placeholders  Key => value map (keys without braces)
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
        return fn_generate_seo_name($rendered);
    }

    // Fallback: basic slug generation
    $slug = mb_strtolower($rendered, 'UTF-8');
    $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug);
    $slug = preg_replace('/-{2,}/', '-', $slug); // collapse multiple dashes
    return trim($slug, '-');
}

// ============================================================================
// SEO Field Application — shared by all provider addons
// ============================================================================

/**
 * Field mapping: setting key → [template registry key, product_data key].
 */
function _travel_core_seo_field_map(): array
{
    return [
        'seo_field_product_name'     => ['seo_product_name',     'product'],
        'seo_field_page_title'       => ['seo_page_title',       'page_title'],
        'seo_field_meta_description' => ['seo_meta_description', 'meta_description'],
        'seo_field_meta_keywords'    => ['seo_meta_keywords',    'meta_keywords'],
        'seo_field_name_slug'        => ['seo_name_slug',        'seo_name'],
        'seo_field_full_description' => ['seo_full_description', 'full_description'],
    ];
}

/**
 * Apply SEO template fields to a product, respecting overwrite mode and field toggles.
 *
 * Returns only the product_data keys that should be written — callers merge
 * this into their own product data array before calling fn_update_product().
 *
 * @param string      $addonName    'novoton_holidays' or 'sphinx_holidays'
 * @param array       $placeholders Key => value map for template rendering
 * @param int         $productId    0 = new product (all enabled fields applied), >0 = existing
 * @param string|null $hotelId      For unique slug generation (SphinxProductFactory pattern)
 * @return array Product data keys to merge into fn_update_product()
 */
function fn_travel_core_apply_seo_fields(string $addonName, array $placeholders, int $productId = 0, ?string $hotelId = null): array
{
    $settings = \Tygh\Registry::get('addons.' . $addonName) ?: [];

    $overwriteMode = ($settings['seo_overwrite_mode'] ?? '') ?: 'override_all';
    $fillIfEmpty = ($overwriteMode === 'fill_if_empty') && ($productId > 0);

    // Load current product values once (only when needed for fill_if_empty)
    $current = [];
    $currentSlug = '';
    if ($fillIfEmpty) {
        $current = db_get_row(
            "SELECT product, page_title, meta_description, meta_keywords, full_description
             FROM ?:product_descriptions
             WHERE product_id = ?i AND lang_code = ?s",
            $productId, CART_LANGUAGE
        ) ?: [];
        $currentSlug = (string) db_get_field(
            "SELECT name FROM ?:seo_names WHERE object_id = ?i AND type = 'p' LIMIT 1",
            $productId
        );
    }

    $result = [];
    $fieldMap = _travel_core_seo_field_map();

    foreach ($fieldMap as $toggleKey => [$templateKey, $productKey]) {
        // Check field toggle (default Y for backward compat)
        $enabled = ($settings[$toggleKey] ?? '') ?: 'Y';
        if ($enabled !== 'Y') {
            continue;
        }

        // Fill-if-empty: skip if existing value is non-empty
        if ($fillIfEmpty) {
            $existingValue = ($productKey === 'seo_name')
                ? $currentSlug
                : ($current[$productKey] ?? '');
            if (trim($existingValue) !== '') {
                continue;
            }
        }

        // Read template pattern from addon settings
        $template = (string) ($settings[$templateKey] ?? '');

        // Render the field
        if ($productKey === 'seo_name') {
            $rendered = fn_travel_core_render_seo_slug($template, $placeholders);
            // Ensure uniqueness for existing or new products
            if ($hotelId !== null && function_exists('fn_generate_seo_name')) {
                // Check for duplicates (append hotel_id suffix if needed)
                $existing = db_get_field(
                    "SELECT object_id FROM ?:seo_names WHERE name = ?s AND type = 'p' AND object_id != ?i LIMIT 1",
                    $rendered, $productId
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
                $result[$productKey] = (string) ($placeholders['description'] ?? '');
            }
        } else {
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
 * @param string $addonName 'novoton_holidays' or 'sphinx_holidays'
 * @return array{updated: int, skipped: int, total: int}
 */
function fn_travel_core_seo_bulk_apply(string $addonName): array
{
    $updated = 0;
    $skipped = 0;
    $total = 0;
    $batchSize = 200;
    $offset = 0;

    while (true) {
        if ($addonName === 'novoton_holidays') {
            $hotels = db_get_array(
                "SELECT hotel_id, product_id, hotel_name, city, country, region,
                        star_rating, hotel_type, property_type, latitude, longitude
                 FROM ?:novoton_hotels
                 WHERE product_id IS NOT NULL AND product_id > 0
                 LIMIT ?i, ?i",
                $offset, $batchSize
            );
        } elseif ($addonName === 'sphinx_holidays') {
            $hotels = db_get_array(
                "SELECT h.hotel_id, h.product_id, h.name, h.classification, h.property_type,
                        h.description, h.rating, h.facilities_json, h.boards_json,
                        h.latitude, h.longitude, h.image_url, h.address, h.phone, h.email, h.website,
                        h.destination_name, h.country_name, h.region_name
                 FROM ?:sphinx_hotels h
                 WHERE h.product_id IS NOT NULL AND h.product_id > 0
                   AND h.sync_status = 'active'
                 LIMIT ?i, ?i",
                $offset, $batchSize
            );
        } else {
            break;
        }

        if (empty($hotels)) {
            break;
        }

        foreach ($hotels as $hotel) {
            $total++;
            $productId = (int) $hotel['product_id'];

            // Build placeholders per addon
            if ($addonName === 'novoton_holidays') {
                $displayName = $hotel['hotel_name'] ?? '';
                $placeholders = \Tygh\Addons\NovotonHolidays\Helpers\ProductFactory::buildNovotonPlaceholders($hotel, $displayName);
            } else {
                $placeholders = \Tygh\Addons\SphinxHolidays\Helpers\SphinxProductFactory::buildPlaceholders($hotel, [
                    'city'    => $hotel['destination_name'] ?? '',
                    'country' => $hotel['country_name'] ?? '',
                    'region'  => $hotel['region_name'] ?? '',
                ]);
            }

            $seoFields = fn_travel_core_apply_seo_fields($addonName, $placeholders, $productId, $hotel['hotel_id']);

            if (empty($seoFields)) {
                $skipped++;
            } else {
                fn_update_product($seoFields, $productId, CART_LANGUAGE);
                $updated++;
            }

            if (function_exists('fn_set_progress')) {
                fn_set_progress('echo', ($hotel['hotel_name'] ?? $hotel['name'] ?? $hotel['hotel_id']) . ' — ' . ($seoFields ? 'updated' : 'skipped'));
            }
        }

        $offset += $batchSize;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'total' => $total];
}
