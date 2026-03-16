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
