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

        // Create new category
        $category_data = [
            'category'  => $part,
            'parent_id' => $parent_id,
            'status'    => 'A',
        ];

        $category_id = (int) fn_update_category($category_data, 0, CART_LANGUAGE);

        if ($category_id <= 0) {
            fn_log_event('general', 'runtime', [
                'message' => "travel_core: fn_update_category() returned 0 for part='{$part}', parent_id={$parent_id}, path='{$path}'",
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

    // Create new category under parent
    $category_data = [
        'category'  => $name,
        'parent_id' => $parent_id,
        'status'    => 'A',
    ];

    $category_id = (int) fn_update_category($category_data, 0, CART_LANGUAGE);

    if ($category_id <= 0) {
        fn_log_event('general', 'runtime', [
            'message' => "travel_core: fn_update_category() returned 0 for name='{$name}', parent_id={$parent_id}",
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

    $search = [];
    $replace = [];

    foreach ($placeholders as $key => $value) {
        $search[] = '{{' . $key . '}}';
        if (is_array($value)) {
            $replace[] = implode(', ', array_slice(array_filter(array_map('trim', $value)), 0, 3));
        } else {
            $replace[] = (string) $value;
        }
    }

    $result = str_replace($search, $replace, $pattern);

    // Remove any leftover unreplaced {{...}} tokens
    $result = preg_replace('/\{\{[a-z_]+\}\}/', '', $result);

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
    return trim($slug, '-');
}
