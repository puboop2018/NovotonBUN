<?php

declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/functions/seo.php                    *
 *                                                                          *
 ***************************************************************************/

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * SEO template subsystem — placeholder rendering, per-language template
 * resolution, and the per-product / bulk write paths. Shared by both
 * provider addons; extracted from functions/hotels.php (god-file ratchet).
 */

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
 * Resolve the template pattern for one field in one language.
 *
 * Templates are per-language: an admin-saved language override
 * (seo_page_title__ro) wins, then the language-less legacy/shared setting,
 * then the addon's built-in per-language default (seo_page_title__ro in
 * fn_<addon>_seo_defaults()), then the built-in base default. This is what
 * lets RO and EN storefronts carry DIFFERENT copy while stores that never
 * saved per-language templates keep exactly their old behaviour.
 *
 * @param array<mixed> $settings
 * @param array<mixed> $defaults
 */
function _travel_core_seo_template_for(array $settings, array $defaults, string $templateKey, string $langCode): string
{
    foreach ([$settings, $defaults] as $source) {
        foreach ([$templateKey . '__' . $langCode, $templateKey] as $key) {
            $value = $source[$key] ?? '';
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Apply SEO template fields to a product, respecting overwrite mode and field toggles.
 *
 * Returns only the product_data keys that should be written — callers merge
 * this into their own product data array before calling fn_update_product()
 * FOR THE SAME LANGUAGE they rendered for.
 *
 * @param string $addonName 'novoton_holidays' or 'sphinx_holidays'
 * @param array<string, mixed> $placeholders Key => value map for template rendering
 * @param int $productId 0 = new product (all enabled fields applied), >0 = existing
 * @param string|null $hotelId For unique slug generation (SphinxProductFactory pattern)
 * @param string $langCode Storefront language to render for ('' = CART_LANGUAGE)
 * @return array<string, mixed> Product data keys to merge into fn_update_product()
 */
function fn_travel_core_apply_seo_fields(string $addonName, array $placeholders, int $productId = 0, ?string $hotelId = null, string $langCode = ''): array
{
    if ($langCode === '') {
        $langCode = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en';
    }
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
            $langCode,
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

        // Per-language pattern: stored language override → stored shared
        // setting → built-in language default → built-in base default.
        $template = _travel_core_seo_template_for($settings, $defaults, $templateKey, $langCode);

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
 * The six template setting keys (without toggles/mode) — the per-language
 * dimension applies exactly to these.
 *
 * @return list<string>
 */
function _travel_core_seo_template_keys(): array
{
    return array_map(
        static fn (array $pair): string => $pair[0],
        array_values(_travel_core_seo_field_map()),
    );
}

/**
 * Per-language form data for a provider's SEO Templates admin page:
 * the installed storefront languages plus each language's EFFECTIVE template
 * per field (stored override → shared legacy value → built-in default), so
 * the admin edits exactly what would render.
 *
 * @param array<string, mixed> $defaults The addon's fn_*_seo_defaults() map
 * @return array{languages: array<string, string>, values: array<string, array<string, string>>}
 */
function fn_travel_core_seo_lang_form_data(string $addonName, array $defaults): array
{
    $languages = [];
    if (function_exists('fn_get_translation_languages')) {
        foreach ((array) fn_get_translation_languages() as $lc => $langRow) {
            $languages[TypeCoerce::toString($lc)] = TypeCoerce::toString(
                is_array($langRow) ? ($langRow['name'] ?? $lc) : $lc,
            );
        }
    }
    if ($languages === []) {
        $lc = defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en';
        $languages = [$lc => strtoupper($lc)];
    }

    $settings = TypeCoerce::toStringMap(\Tygh\Registry::get('addons.' . $addonName));
    $values = [];
    foreach (array_keys($languages) as $langKey) {
        foreach (_travel_core_seo_template_keys() as $key) {
            $values[$langKey][$key] = _travel_core_seo_template_for($settings, $defaults, $key, $langKey);
        }
    }

    return ['languages' => $languages, 'values' => $values];
}

/**
 * Persist the per-language template fields posted as seo_lang[<lang>][<key>].
 *
 * Writes each value to the "<key>__<lang>" setting (auto-created by
 * Settings::updateValue) and mirrors it into the Registry so the same
 * request renders the fresh values. Unknown languages and keys are ignored.
 *
 * @param array<mixed, mixed> $seoLang
 */
function fn_travel_core_seo_save_lang_templates(string $addonName, array $seoLang): void
{
    $settings = \Tygh\Settings::instance();
    if (!is_object($settings) || !method_exists($settings, 'updateValue')) {
        return;
    }

    $validLangs = [];
    if (function_exists('fn_get_translation_languages')) {
        $validLangs = array_map(strval(...), array_keys((array) fn_get_translation_languages()));
    }

    $merged = [];
    foreach ($seoLang as $lcRaw => $fields) {
        $lc = TypeCoerce::toString($lcRaw);
        if (!is_array($fields) || ($validLangs !== [] && !in_array($lc, $validLangs, true))) {
            continue;
        }
        foreach (_travel_core_seo_template_keys() as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = TypeCoerce::toString($fields[$key]);
            $settings->updateValue($key . '__' . $lc, $value, $addonName, true);
            $merged[$key . '__' . $lc] = $value;
        }
    }

    if ($merged !== []) {
        $existing = \Tygh\Registry::get('addons.' . $addonName);
        \Tygh\Registry::set('addons.' . $addonName, array_merge(is_array($existing) ? $existing : [], $merged));
    }
}

/**
 * Render + write the per-language SEO fields for ONE existing product.
 *
 * Each language resolves its own template set (see
 * _travel_core_seo_template_for) and fill-if-empty inspects that language's
 * own current values. $extraFields (e.g. a provider's language-less
 * short_description) are merged into every language's write.
 *
 * @param array<string, mixed> $placeholders
 * @param list<string>|null $languages null = every storefront language
 * @param array<string, mixed> $extraFields
 * @return bool Whether any language produced fields and was written
 */
function fn_travel_core_seo_localize(string $addonName, array $placeholders, int $productId, ?string $hotelId, ?array $languages = null, array $extraFields = []): bool
{
    if ($languages === null) {
        $languages = [defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en'];
        if (function_exists('fn_get_translation_languages')) {
            $allLangs = fn_get_translation_languages();
            if (is_array($allLangs) && !empty($allLangs)) {
                $languages = array_keys($allLangs);
            }
        }
    }

    $wroteAny = false;
    foreach ($languages as $lang) {
        $lang = TypeCoerce::toString($lang);
        $seoFields = fn_travel_core_apply_seo_fields($addonName, $placeholders, $productId, $hotelId, $lang);
        if ($seoFields === []) {
            continue;
        }
        fn_update_product(array_merge($extraFields, $seoFields), $productId, $lang);
        $wroteAny = true;
    }

    return $wroteAny;
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
    $languages = [TypeCoerce::toString(CART_LANGUAGE)];
    if (function_exists('fn_get_translation_languages')) {
        $allLangs = fn_get_translation_languages();
        if (is_array($allLangs) && !empty($allLangs)) {
            $languages = array_map(strval(...), array_keys($allLangs));
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

            // Render PER LANGUAGE: each storefront language resolves its own
            // template set (and fill-if-empty checks ITS OWN current values),
            // so RO and EN get their own copy instead of one shared render.
            $wroteAny = fn_travel_core_seo_localize($addonName, $placeholders, $productId, $hotelId, $languages);
            $wroteAny ? $updated++ : $skipped++;

            if (function_exists('fn_set_progress')) {
                fn_set_progress('echo', TypeCoerce::toString($hotel['hotel_name'] ?? $hotel['name'] ?? $hotel['hotel_id'] ?? '') . ' — ' . ($wroteAny ? 'updated' : 'skipped'));
            }
        }

        $offset += $batchSize;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'total' => $total];
}
