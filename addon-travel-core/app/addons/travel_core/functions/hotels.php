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
    switch (strtolower($modifier)) {
        case 'lower':      return mb_strtolower($value, 'UTF-8');
        case 'upper':      return mb_strtoupper($value, 'UTF-8');
        case 'title':      return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        case 'capitalize': return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
        case 'trim':       return trim($value);
        case 'slug':       return function_exists('fn_generate_seo_name') ? fn_generate_seo_name($value) : preg_replace('/-{2,}/', '-', trim(preg_replace('/[^a-z0-9\-]+/', '-', mb_strtolower($value, 'UTF-8')), '-'));
        case 'first':      return mb_substr($value, 0, 1, 'UTF-8');
        case 'last':       return mb_substr($value, -1, 1, 'UTF-8');
        case 'abs':        return (string) abs((float) $value);
        case 'round':      return (string) round((float) $value);
        case 'strip_tags': return strip_tags($value);
        default:           return $value;
    }
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
