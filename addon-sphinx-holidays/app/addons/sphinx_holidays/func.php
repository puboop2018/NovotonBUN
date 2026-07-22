<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/func.php                         *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;

/**
 * Variants function for the default_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 */
function fn_settings_variants_addons_sphinx_holidays_default_currency(): array
{
    $currencies = Registry::get('currencies');
    $result = [];

    if (empty($currencies) || !is_array($currencies)) {
        return $result;
    }

    foreach ($currencies as $code => $currency) {
        $result[$code] = $code . (!empty($currency['symbol']) ? ' (' . $currency['symbol'] . ')' : '');
    }

    return $result;
}

/**
 * Dynamic variants for the "Product languages" multiple checkboxes setting.
 * Lists all active CS-Cart languages.
 */
function fn_settings_variants_addons_sphinx_holidays_product_languages(): array
{
    $languages = db_get_array("SELECT lang_code, name FROM ?:languages WHERE status = 'A' ORDER BY name");
    $result = [];
    foreach ($languages as $lang) {
        $result[$lang['lang_code']] = $lang['name'] . ' (' . strtoupper($lang['lang_code']) . ')';
    }
    return $result;
}

/**
 * Builds a flat associative array of all CS-Cart categories for use in selectbox
 * settings. Option labels show the full category path (e.g. "Hotels / Albania / Durres").
 * The tree order is preserved by sorting on id_path.
 *
 * @return array<int, string>  [category_id => "Parent / Child / …"]  with 0 => "None" first.
 */
function fn_sphinx_holidays_build_category_options(): array
{
    $lang = defined('DESCR_SL') ? DESCR_SL : 'en';

    $categories = db_get_array(
        "SELECT c.category_id, c.id_path
         FROM ?:categories c
         ORDER BY c.id_path ASC"
    );

    if (empty($categories)) {
        return [0 => __('none')];
    }

    // Name lookup for the current backend language; fall back to EN.
    $names = db_get_hash_single_array(
        "SELECT category_id, category FROM ?:category_descriptions WHERE lang_code = ?s",
        ['category_id', 'category'],
        $lang
    );

    if (empty($names)) {
        $names = db_get_hash_single_array(
            "SELECT category_id, category FROM ?:category_descriptions WHERE lang_code = 'en'",
            ['category_id', 'category']
        );
    }

    $options = [0 => __('none')];

    foreach ($categories as $cat) {
        $cat_id   = (int) $cat['category_id'];
        $parts    = array_filter(explode('/', trim((string) $cat['id_path'], '/')));
        $path_labels = [];

        foreach ($parts as $part_id) {
            $pid = (int) $part_id;
            if (isset($names[$pid])) {
                $path_labels[] = $names[$pid];
            }
        }

        if (!empty($path_labels)) {
            $options[$cat_id] = implode(' / ', $path_labels);
        } elseif (isset($names[$cat_id])) {
            $options[$cat_id] = $names[$cat_id];
        }
    }

    return $options;
}

/**
 * Variants for the "hotels_category_id" selectbox setting.
 * Returns all CS-Cart categories as [id => "Full / Path"] options.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_hotels_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Variants for the "packages_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_packages_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Variants for the "circuits_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_circuits_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Variants for the "experiences_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_experiences_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Addon uninstall function.
 * Drops Sphinx-specific tables and cleans up.
 */
function fn_sphinx_holidays_uninstall(): bool
{
    // Remove Sphinx aliases from shared feature mapping (table may not exist if travel_core already uninstalled)
    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $aliasTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_api_alias'
    );
    if ($aliasTableExists) {
        // Region feature-map rows seeded by fn_sphinx_holidays_seed_region_mappings:
        // join-scoped to sphinx's own aliases (must run BEFORE the alias delete)
        $featureMapExists = db_get_field(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
            $tablePrefix . 'travel_feature_map'
        );
        if ($featureMapExists) {
            db_query(
                "DELETE fm FROM ?:travel_feature_map fm
                 JOIN ?:travel_api_alias a ON a.map_id = fm.map_id AND a.api_source = 'sphinx'
                 WHERE fm.feature_type = 'region'"
            );
        }
        db_query("DELETE FROM ?:travel_api_alias WHERE api_source = 'sphinx'");
    }

    // Remove language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'sphinx_holidays.%'");

    // Clean up block manager blocks (dedicated types sphinx_booking_engine /
    // sphinx_best_deals) together with their descriptions and layout placements
    $sphinx_block_ids = db_get_fields(
        "SELECT block_id FROM ?:bm_blocks WHERE type IN ('sphinx_booking_engine', 'sphinx_best_deals')"
    );
    if (!empty($sphinx_block_ids)) {
        db_query("DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)", $sphinx_block_ids);
        db_query("DELETE FROM ?:bm_snapping WHERE block_id IN (?n)", $sphinx_block_ids);
        db_query("DELETE FROM ?:bm_blocks WHERE block_id IN (?n)", $sphinx_block_ids);
    }

    // Drop Sphinx-specific tables (order matters for FK constraints)
    db_query("DROP TABLE IF EXISTS ?:sphinx_image_sync_queue");
    db_query("DROP TABLE IF EXISTS ?:sphinx_destination_whitelist");
    db_query("DROP TABLE IF EXISTS ?:sphinx_cache");
    db_query("DROP TABLE IF EXISTS ?:sphinx_sync_log");
    db_query("DROP TABLE IF EXISTS ?:sphinx_bookings");
    db_query("DROP TABLE IF EXISTS ?:sphinx_package_routes");
    db_query("DROP TABLE IF EXISTS ?:sphinx_experiences");
    db_query("DROP TABLE IF EXISTS ?:sphinx_circuits");
    db_query("DROP TABLE IF EXISTS ?:sphinx_destinations");
    db_query("DROP TABLE IF EXISTS ?:sphinx_hotels");

    return true;
}

/**
 * Post-install function.
 * Seeds Sphinx-specific aliases into the shared feature mapping.
 */
function fn_sphinx_holidays_post_install(): bool
{
    fn_sphinx_holidays_seed_aliases();
    fn_sphinx_holidays_seed_region_mappings();
    fn_sphinx_holidays_seed_language_keys();
    fn_sphinx_holidays_seed_seo_defaults();
    return true;
}

/**
 * Ensure language keys added after initial install are present in the database.
 *
 * Merges two sources (addon.xml wins on conflict — it is the maintained home
 * of settings labels):
 *   - addon.xml <language_variables>: settings labels/tooltips, section names
 *   - lang_keys.php: runtime keys used by backend pages that are not declared
 *     in addon.xml (SEO templates page, placeholders, ...)
 *
 * Also mirrors settings labels/tooltips into ?:settings_descriptions: CS-Cart
 * fills descriptions only when a settings object is created, so settings
 * shipped in later releases otherwise render with an empty label on existing
 * installations (the ":" with no text seen on the addon settings page).
 *
 * Idempotent (INSERT ... ON DUPLICATE KEY UPDATE). Stamps the source content
 * hash into the sphinx_holidays._lang_seed_hash lang var; init.php probes that
 * stamp and re-runs this seeder whenever addon.xml or lang_keys.php change, so
 * new labels appear on the next admin page load without reinstalling.
 */
function fn_sphinx_holidays_seed_language_keys(): void
{
    $vars = fn_sphinx_holidays_language_variables();

    foreach ($vars as $name => $translations) {
        foreach ($translations as $lang_code => $value) {
            db_query(
                "INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE value = ?s",
                $name, $lang_code, $value, $value
            );
        }
    }

    // Mirror settings labels into settings_descriptions for every settings
    // object of this addon that has a matching sphinx_holidays.{name} lang var.
    // @db_query: defensive, like the settings_objects migrations in novoton —
    // a schema mismatch must never break the admin panel.
    $objects = db_get_array(
        "SELECT object_id, name FROM ?:settings_objects
         WHERE section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = 'sphinx_holidays')"
    );
    foreach ($objects as $object) {
        $key = 'sphinx_holidays.' . $object['name'];
        if (empty($vars[$key])) {
            continue;
        }
        foreach ($vars[$key] as $lang_code => $label) {
            $tooltip = $vars[$key . '.tooltip'][$lang_code] ?? '';
            @db_query(
                "INSERT INTO ?:settings_descriptions (object_id, object_type, lang_code, value, tooltip)
                 VALUES (?i, 'O', ?s, ?s, ?s)
                 ON DUPLICATE KEY UPDATE value = ?s, tooltip = ?s",
                $object['object_id'], $lang_code, $label, $tooltip, $label, $tooltip
            );
        }
    }

    // Stamp the seeded content so the init.php probe knows when to re-seed.
    $hash = fn_sphinx_holidays_language_seed_hash();
    db_query(
        "INSERT INTO ?:language_values (name, lang_code, value) VALUES (?s, 'en', ?s)
         ON DUPLICATE KEY UPDATE value = ?s",
        'sphinx_holidays._lang_seed_hash', $hash, $hash
    );

    // Labels are served through CS-Cart's registry cache; clear it so the
    // (re)seeded values render immediately instead of after a manual cc.
    if (function_exists('fn_clear_cache')) {
        fn_clear_cache();
    }
}

/**
 * All sphinx language variables from both sources, merged.
 *
 * @return array<string, array<string, string>> name => [lang_code => value]
 */
function fn_sphinx_holidays_language_variables(): array
{
    /** @var array<string, array<string, string>> $vars */
    $vars = require __DIR__ . '/lang_keys.php';

    $xml = @simplexml_load_file(__DIR__ . '/addon.xml');
    if ($xml !== false && isset($xml->language_variables)) {
        foreach ($xml->language_variables->item as $item) {
            $name = (string) $item['id'];
            $lang_code = (string) $item['lang'];
            if ($name === '' || $lang_code === '') {
                continue;
            }
            $vars[$name][$lang_code] = (string) $item;
        }
    }

    return $vars;
}

/**
 * Content hash of the language-variable sources — the self-heal seed stamp.
 */
function fn_sphinx_holidays_language_seed_hash(): string
{
    return md5(
        (string) @md5_file(__DIR__ . '/addon.xml')
        . (string) @md5_file(__DIR__ . '/lang_keys.php')
    );
}

/**
 * Idempotently seed default SEO template values into the sphinx_holidays settings.
 * Called from init.php self-heal probe on every admin page-load when the sentinel
 * key is missing, and from post_install. Existing non-empty values are preserved.
 */
/**
 * Canonical default SEO template strings + field toggles for Sphinx products.
 *
 * Single source of truth shared by the seed routine and the travel_core
 * runtime renderer (which uses these as a fallback when no admin-configured
 * template is stored). Defined in func.php so it is loaded in every AREA,
 * including the storefront cron context that creates products — this is what
 * lets cron-created products get rendered metadata even when the settings
 * were never persisted to the DB.
 *
 * @return array<string, string>
 */
function fn_sphinx_holidays_seo_defaults(): array
{
    return [
        'seo_overwrite_mode'         => 'override_all',
        'seo_product_name'           => '{{name}}',
        'seo_page_title'             => '{{name}} {{classification}}* - {{city}}, {{country}}',
        'seo_meta_description'       => 'Book {{name}} in {{city}}, {{country}}. {{classification}}-star {{property_type}} with {{facilities}}.',
        'seo_meta_keywords'          => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{classification}} star',
        'seo_name_slug'              => '{{name}}-{{city}}-{{country}}',
        'seo_full_description'       => '',
        'seo_field_product_name'     => 'Y',
        'seo_field_page_title'       => 'Y',
        'seo_field_meta_description' => 'Y',
        'seo_field_meta_keywords'    => 'Y',
        'seo_field_name_slug'        => 'Y',
        'seo_field_full_description' => 'Y',
    ];
}

function fn_sphinx_holidays_seed_seo_defaults(): void
{
    $defaults = fn_sphinx_holidays_seo_defaults();

    $current  = \Tygh\Registry::get('addons.sphinx_holidays') ?: [];
    $settings = \Tygh\Settings::instance();
    $toMerge  = [];

    foreach ($defaults as $key => $value) {
        $stored = $current[$key] ?? null;
        // Only write if the key is absent or blank (never overwrite admin edits)
        if ($stored === null || ($stored === '' && $value !== '')) {
            $settings->updateValue($key, $value, 'sphinx_holidays', true);
            $toMerge[$key] = $value;
        }
    }

    if (!empty($toMerge)) {
        \Tygh\Registry::set('addons.sphinx_holidays', array_merge($current, $toMerge));
    }
}

/**
 * Seed Sphinx API aliases into the shared travel_api_alias table.
 * Maps Sphinx free-text values to canonical feature codes.
 *
 * Idempotent — uses FeatureMapper::addAlias() which does INSERT ON DUPLICATE KEY UPDATE.
 */
function fn_sphinx_holidays_seed_aliases(): void
{
    if (!class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)) {
        return;
    }

    // Board/Meal aliases
    $boardAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::boardAliases();

    // Room type aliases (prefix match)
    $roomAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::roomAliases();

    // Guard: travel_feature_map table may not exist if travel_core isn't installed yet
    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $tableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_feature_map'
    );
    if (!$tableExists) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx: Skipping alias seeding — travel_feature_map table not found (travel_core not installed?)',
        ]);
        return;
    }

    // Helper: resolve canonical code to map_id and register alias.
    // Eliminates 4 identical foreach+query blocks.
    $seedAliasGroup = static function (string $featureType, array $aliases, string $matchType = 'exact'): void {
        // Batch-load all map_ids for this feature type to avoid N+1 queries
        $allMaps = db_get_hash_single_array(
            "SELECT canonical_code, map_id FROM ?:travel_feature_map WHERE feature_type = ?s",
            ['canonical_code', 'map_id'],
            $featureType
        );

        foreach ($aliases as $apiValue => $canonicalCode) {
            $mapId = (int) ($allMaps[$canonicalCode] ?? 0);
            if ($mapId > 0) {
                \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', (string) $apiValue, $mapId, $matchType);
            }
        }
    };

    $seedAliasGroup('board', $boardAliases, 'exact');
    $seedAliasGroup('room_type', $roomAliases, 'prefix');

    // Star rating aliases (feature_type='stars'): canonical codes '1' through '5'
    $starAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::starAliases();

    $seedAliasGroup('stars', $starAliases, 'exact');

    // Property type aliases (feature_type='property_type')
    $propertyTypeAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::propertyTypeAliases();

    $seedAliasGroup('property_type', $propertyTypeAliases, 'exact');

    // Hotel facility aliases — property-level amenities
    $hotelFacilityAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::hotelFacilityAliases();

    // Room facility aliases — in-room amenities
    $roomFacilityAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::roomFacilityAliases();

    // Beach access aliases — beach & location amenities
    $beachAccessAliases = \Tygh\Addons\SphinxHolidays\Install\SphinxAliasSeedData::beachAccessAliases();

    $seedAliasGroup('hotel_facility', $hotelFacilityAliases, 'exact');
    $seedAliasGroup('room_facility', $roomFacilityAliases, 'exact');
    $seedAliasGroup('beach_access', $beachAccessAliases, 'exact');

    // Travel groups are NOT seeded as aliases — they're derived from facilities
    // at runtime via TravelGroupResolver::derive(). No API value mapping needed.

    // Clear resolve cache after batch alias inserts
    \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();
}

/**
 * Seed whitelisted regions into travel_feature_map.
 *
 * For each region in the Destination Whitelist, creates a travel_feature_map row
 * (feature_type='region') and a travel_api_alias linking the Sphinx destination_id.
 * This allows SphinxFeatureAssigner::assignRegion() to resolve regions through
 * the standard FeatureMapper pipeline instead of creating variants ad-hoc.
 *
 * Idempotent — uses INSERT IGNORE for map rows and addAlias() for aliases.
 */
function fn_sphinx_holidays_seed_region_mappings(): void
{
    if (!class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)) {
        return;
    }

    $tablePrefix = \Tygh\Registry::get('config.table_prefix');
    $mapTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_feature_map'
    );
    $whitelistTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'sphinx_destination_whitelist'
    );
    if (!$mapTableExists || !$whitelistTableExists) {
        return;
    }

    // Get all whitelisted regions:
    // 1) Regions under countries with selection_type='all' (all children included)
    // 2) Regions explicitly whitelisted with selection_type='specific'
    $regions = db_get_array(
        "SELECT d.destination_id, d.name, d.country_code
         FROM ?:sphinx_destinations d
         JOIN ?:sphinx_destination_whitelist w ON w.destination_id = d.parent_id AND w.selection_type = 'all'
         WHERE d.type = 'region'
         UNION
         SELECT d.destination_id, d.name, d.country_code
         FROM ?:sphinx_destinations d
         JOIN ?:sphinx_destination_whitelist w ON w.destination_id = d.destination_id AND w.selection_type = 'specific'
         WHERE d.type = 'region'"
    );

    if (empty($regions)) {
        return;
    }

    foreach ($regions as $region) {
        $destId = (int) $region['destination_id'];
        $canonicalCode = 'region_' . $destId;
        $displayName = (string) $region['name'];

        db_query(
            "INSERT IGNORE INTO ?:travel_feature_map (feature_type, canonical_code, display_name_en, mapping_source, status)
             VALUES ('region', ?s, ?s, 'auto', 'A')",
            $canonicalCode, $displayName
        );

        $mapId = (int) db_get_field(
            "SELECT map_id FROM ?:travel_feature_map WHERE feature_type = 'region' AND canonical_code = ?s",
            $canonicalCode
        );

        if ($mapId > 0) {
            \Tygh\Addons\TravelCore\Services\FeatureMapper::addAlias('sphinx', (string) $destId, $mapId, 'exact');
        }
    }

    \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();
}

// =========================================================================
// HOOK FUNCTIONS
// =========================================================================

/**
 * Hook: pre_place_order
 * Re-verify Sphinx offer prices before order is placed.
 *
 * If a Sphinx offer is no longer available, the item is removed from the cart
 * instead of blocking the entire order. This allows mixed-provider orders
 * (e.g. Novoton + Sphinx) to proceed with the available items.
 *
 * The order is only blocked if ALL remaining cart items become unavailable
 * (i.e. the cart would be empty after removals).
 */
function fn_sphinx_holidays_pre_place_order(&$cart, &$allow, &$product_groups): void
{
    $verifier = \Tygh\Addons\SphinxHolidays\Services\Container::getPreOrderPriceVerifier();
    $result = $verifier->verify($cart);

    // Remove unavailable Sphinx offers from cart instead of blocking the entire order
    if (!empty($result['unavailable'])) {
        foreach ($result['unavailable'] as $cartId => $info) {
            $hotelName = $info['hotel_name'] ?: $info['offer_id'];

            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.offer_removed_from_order', [
                    '[hotel]' => $hotelName,
                    '[default]' => 'The Sphinx offer for "' . $hotelName . '" is no longer available and has been removed from your order.',
                ])
            );

            fn_log_event('general', 'runtime', [
                'message' => 'Sphinx pre_place_order: removed unavailable item from cart',
                'cart_id' => $cartId,
                'hotel_name' => $hotelName,
            ]);

            // The pending booking row created at add-to-cart would otherwise
            // be stranded forever (order_id=0, unreachable by the order-link
            // reconcilers since the item leaves the order) and show as a
            // permanent "Order ID -" row in the admin grid. delete() clears
            // both ?:sphinx_bookings and the shared mirror.
            $strandedBookingId = 0;
            if (is_array($cart)) {
                $cartProducts = $cart['products'] ?? null;
                $removedProduct = is_array($cartProducts) ? ($cartProducts[$cartId] ?? null) : null;
                $removedExtra = is_array($removedProduct) ? ($removedProduct['extra'] ?? null) : null;
                if (is_array($removedExtra)) {
                    $strandedBookingId = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toInt($removedExtra['travel_booking_id'] ?? 0);
                }
            }
            if ($strandedBookingId > 0) {
                \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository()->delete($strandedBookingId);
            }

            unset($cart['products'][$cartId]);
        }

        // If the cart is now empty (all items were Sphinx and all unavailable), block the order
        if (empty($cart['products'])) {
            fn_set_notification('E', __('error'),
                __('sphinx_holidays.all_offers_unavailable', [
                    '[default]' => 'All hotel offers in your cart are no longer available. Please search again.',
                ])
            );
            $allow = false;
            return;
        }
    }

    if (!empty($result['corrections'])) {
        foreach ($result['corrections'] as $cartId => $correction) {
            if (!isset($cart['products'][$cartId])) {
                continue;
            }
            $newPrice = (float)$correction['api_price'];
            // Keep the old price for "Old vs New" display before overwriting
            $cart['products'][$cartId]['extra']['price_before_correction'] = $cart['products'][$cartId]['price'] ?? null;
            $cart['products'][$cartId]['price'] = $newPrice;
            $cart['products'][$cartId]['base_price'] = $newPrice;
            $cart['products'][$cartId]['original_price'] = $newPrice;
            $cart['products'][$cartId]['extra']['total_price'] = $newPrice;
        }
    }

    // A correction exceeded the absorb allowance: the cart now shows the new
    // price — block this click so the customer re-confirms the updated total
    // (same policy as novoton; EU CRD: the amount charged must be the amount
    // shown at the order button).
    if (!empty($result['reconfirm'])) {
        fn_set_notification(
            'W',
            __('travel_core.price_change', ['[default]' => 'Price update']),
            __('travel_core.price_changed_reconfirm', [
                '[default]' => 'The price of a booking in your cart has changed. Please review the updated total and place your order again.',
            ]),
        );
        $allow = false;
    }
}

/**
 * Hook: place_order_post
 * After an order is placed, submit the booking to the Sphinx API.
 *
 * Status flow: pending → (API call) → confirmed on success, failed on error.
 * On API failure: marks booking as STATUS_FAILED and logs the error.
 */
function fn_sphinx_holidays_place_order_post(&$order_id, &$action, &$order_status, &$cart, &$auth): void
{
    // CS-Cart Multi-Vendor passes $order_id as array (parent + child order IDs).
    // Normalize to the parent (first) order ID for booking submission.
    $resolved_order_id = (int) (is_array($order_id) ? reset($order_id) : $order_id);

    if (empty($resolved_order_id)) {
        return;
    }

    // Fallback path: $cart is null/empty (payment callbacks, order-status
    // re-triggers) — nothing to submit, but the bookings referenced by the
    // order's items may still be unlinked; link them from the stored order
    // (novoton parity: its place_order_post handles this the same way).
    if (empty($cart['products'])) {
        fn_sphinx_holidays_link_order_bookings($resolved_order_id);
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();

    // Booking contact comes from the ORDER's checkout profile — the guest form
    // no longer duplicates the email/phone questions. Legacy cart extras
    // (contact_email/contact_phone, from forms submitted before the removal)
    // remain as a fallback for in-flight carts only.
    $orderContact = \Tygh\Addons\SphinxHolidays\Services\BookingPayloadFactory::contactFromUserData(
        is_array($cart['user_data'] ?? null) ? $cart['user_data'] : []
    );

    foreach ($cart['products'] as $product) {
        if (empty($product['extra']['sphinx_booking']) || empty($product['extra']['travel_booking_id'])) {
            continue;
        }

        $booking_id = (int)$product['extra']['travel_booking_id'];
        $offer_id = $product['extra']['offer_id'] ?? '';

        $contact = [
            'email' => $orderContact['email'] !== ''
                ? $orderContact['email']
                : \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($product['extra']['contact_email'] ?? ''),
            'phone' => $orderContact['phone'] !== ''
                ? $orderContact['phone']
                : \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($product['extra']['contact_phone'] ?? ''),
        ];

        // Link booking to order with PENDING status (not confirmed yet — API call hasn't happened)
        $repo->linkToOrder($booking_id, $resolved_order_id, \Tygh\Addons\TravelCore\TravelConstants::STATUS_PENDING);

        // Backfill the booking row so admin views and the retry path see the
        // same contact the API is given (add_to_cart stores it empty now).
        if ($contact['email'] !== '' || $contact['phone'] !== '') {
            $repo->update($booking_id, [
                'guest_email' => $contact['email'],
                'guest_phone' => $contact['phone'],
            ]);
        }

        // Submit booking to Sphinx API
        if (!empty($offer_id)) {
            try {
                $api = \Tygh\Addons\SphinxHolidays\Services\Container::getApi();
                $guests_data = [];
                if (!empty($product['extra']['guests_data'])) {
                    $guests_data = is_string($product['extra']['guests_data'])
                        ? json_decode($product['extra']['guests_data'], true)
                        : $product['extra']['guests_data'];
                }

                $payload = \Tygh\Addons\SphinxHolidays\Services\BookingPayloadFactory::build(
                    \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($offer_id),
                    is_array($guests_data) ? $guests_data : [],
                    $contact,
                    $resolved_order_id
                );

                // Dispatch by booking type — packages/circuits/experiences have
                // their own book endpoints (previously EVERY sphinx product was
                // sent to bookHotel, so non-hotel bookings hit the wrong one).
                $booking_type = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($product['extra']['booking_type'] ?? 'hotel');
                $bookResult = match ($booking_type) {
                    'circuit' => $api->bookCircuit($payload),
                    'package' => $api->bookPackage($payload),
                    'experience' => $api->bookExperience($payload),
                    default => $api->bookHotel($payload),
                };

                // The documented book response carries the voucher code as
                // booking_confirmation_number (order_id/contract_id/
                // reference_code/status alongside) — there is NO
                // booking_reference field. Reading the wrong key meant
                // api_booking_ref was never stored and the admin grid's
                // confirmation column stayed "-" for every sphinx booking.
                $confirmationNumber = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString(
                    $bookResult['booking_confirmation_number'] ?? $bookResult['booking_reference'] ?? ''
                );
                if ($confirmationNumber !== '') {
                    $repo->updateApiResponse(
                        $booking_id,
                        $confirmationNumber,
                        json_encode($bookResult)
                    );
                } else {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Sphinx: booking confirmed but no confirmation number returned',
                        'booking_id' => $booking_id,
                        'order_id' => $resolved_order_id,
                    ]);
                }

                // API call succeeded — now set confirmed status
                $repo->update($booking_id, [
                    'status' => \Tygh\Addons\TravelCore\TravelConstants::STATUS_CONFIRMED,
                ]);

            } catch (\Throwable $e) {
                // Mark booking as failed in both sphinx_bookings and travel_bookings
                $repo->update($booking_id, [
                    'status' => \Tygh\Addons\TravelCore\TravelConstants::STATUS_FAILED,
                ]);

                fn_log_event('general', 'runtime', [
                    'message' => 'Sphinx book API call failed: ' . $e->getMessage(),
                    'booking_id' => $booking_id,
                    'order_id' => $resolved_order_id,
                ]);
            }
        }
    }

    // Self-heal: a cart item skipped by the guard above (missing
    // sphinx_booking/travel_booking_id extras, pre-persist throw) would leave
    // its booking orphaned forever — the admin grid then shows "Order ID: -"
    // although the order exists. The reconciler is idempotent and cheap, so
    // always run it after submission (novoton parity).
    fn_sphinx_holidays_link_order_bookings($resolved_order_id);
}

/**
 * Link any unlinked sphinx bookings referenced by an order's items to that
 * order. Idempotent: bookings already linked are left untouched; status is
 * NOT changed (a reconciliation must not reset a confirmed booking).
 *
 * @return int Number of bookings newly linked
 */
function fn_sphinx_holidays_link_order_bookings(int $order_id): int
{
    if ($order_id <= 0) {
        return 0;
    }

    $order_info = fn_get_order_info($order_id);
    /** @var array<string, mixed> $order_info */
    $order_info = is_array($order_info) ? $order_info : [];
    $oiProducts = is_array($order_info['products'] ?? null) ? $order_info['products'] : [];
    if (empty($oiProducts)) {
        return 0;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $linked = 0;

    foreach ($oiProducts as $product) {
        if (!is_array($product)) {
            continue;
        }
        /** @var array<string, mixed> $extra */
        $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
        if (empty($extra['sphinx_booking']) || empty($extra['travel_booking_id'])) {
            continue;
        }

        $booking_id = (int) $extra['travel_booking_id'];
        if ($booking_id <= 0) {
            continue;
        }

        $current_order = (int) db_get_field(
            'SELECT order_id FROM ?:sphinx_bookings WHERE booking_id = ?i',
            $booking_id,
        );

        // Already linked to a DIFFERENT order — never steal it.
        if ($current_order > 0 && $current_order !== $order_id) {
            continue;
        }

        // Write when the booking is unlinked. Also write when it is already
        // linked to THIS order but the shared travel_bookings mirror drifted
        // to a different value (typically 0) — that drift is what shows
        // "Order ID: -" in the admin grid for an already-linked booking.
        // linkToOrder() -> update() re-mirrors order_id into travel_bookings.
        $needs_write = $current_order <= 0;
        if (!$needs_write) {
            $mirror_order = (int) db_get_field(
                "SELECT order_id FROM ?:travel_bookings WHERE provider = 'sphinx' AND provider_booking_id = ?s",
                (string) $booking_id,
            );
            $needs_write = $mirror_order !== $order_id;
        }

        if ($needs_write) {
            $repo->linkToOrder($booking_id, $order_id);
            $linked++;
        }
    }

    return $linked;
}

/**
 * Hook: travel_link_order_bookings — fired by travel_core's
 * travel_tools "Reconcile booking–order links" backfill.
 *
 * @param int $order_id
 * @param int $linked Accumulator across providers
 */
function fn_sphinx_holidays_travel_link_order_bookings($order_id, &$linked): void
{
    $linked += fn_sphinx_holidays_link_order_bookings((int) $order_id);
}

/**
 * Hook: calculate_cart_items
 * Preserve stored price for Sphinx bookings.
 */
function fn_sphinx_holidays_calculate_cart_items(&$cart, &$cart_products, &$auth): void
{
    if (empty($cart['products'])) {
        return;
    }

    foreach ($cart['products'] as &$product) {
        if (!empty($product['extra']['sphinx_booking']) && !empty($product['stored_price'])) {
            $product['price'] = $product['base_price'] ?? $product['price'];
        }
    }
    unset($product);
}

/**
 * Hook: get_product_data_post
 * Attach booking engine config to Sphinx hotel products.
 */
function fn_sphinx_holidays_get_product_data_post(&$product_data, &$auth, $preview, $lang_code): void
{
    // Complete no-op for Smarty 5 compatibility.
    // Do NOT modify $product_data during template rendering — any modification
    // corrupts Smarty 5's Variable wrapper, causing Data::getVariable()
    // infinite recursion (Data.php:265) that exhausts 256MB memory.
    // Templates detect Sphinx products from $product.product_code prefix (SPX).
}

/**
 * Hook: gather_additional_product_data_post
 * Complete no-op for Smarty 5 compatibility.
 * Templates detect Sphinx products from $product.product_code prefix (SPX).
 */
function fn_sphinx_holidays_gather_additional_product_data_post(&$product, $auth, $params): void
{
    // Do NOT modify $product or call $view->assign() here.
    // This hook runs during Smarty 5 template rendering — any $product
    // modification corrupts Smarty's scope chain (Data.php:265 crash).
}

/**
 * Hook: user_login_post
 * Link session-based sphinx bookings to the logged-in user.
 */
function fn_sphinx_holidays_user_login_post($user_data, &$auth): void
{
    if (empty($auth['user_id'])) {
        return;
    }

    $session_id = session_id();
    if (empty($session_id)) {
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $repo->linkToUserBySession((int)$auth['user_id'], $session_id);
}

/**
 * Hook: create_user_post
 * Link session-based sphinx bookings to the newly created user.
 */
function fn_sphinx_holidays_create_user_post($user_id, $user_data, &$auth): void
{
    if (empty($user_id)) {
        return;
    }

    $session_id = session_id();
    if (empty($session_id)) {
        return;
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $repo->linkToUserBySession((int)$user_id, $session_id);
}

/**
 * Hook: get_order_info
 * Admin panel notification for failed Sphinx bookings.
 *
 * When an admin views an order that contains failed Sphinx bookings,
 * shows an orange warning notification via fn_set_notification('W', ...).
 */
function fn_sphinx_holidays_get_order_info(&$order, $additional_data): void
{
    // Admin only: the per-line "View Booking" link resolved below and the
    // failed-booking notification are both admin-panel concerns.
    if (!defined('AREA') || AREA !== 'A' || empty($order['order_id'])) {
        return;
    }

    // Resolve the unified travel_bookings surrogate id for each sphinx booking
    // product so the admin order-detail block links to travel_bookings.view
    // with the CORRECT id. extra['travel_booking_id'] holds the sphinx_bookings
    // PK — a different id-space from the surrogate travel_bookings.view expects
    // — so linking it directly would open another provider's booking (the same
    // id-space collision fixed for the "View in provider" grid icon).
    if (!empty($order['products']) && is_array($order['products'])) {
        foreach ($order['products'] as &$sxProduct) {
            if (!is_array($sxProduct)) {
                continue;
            }
            $sxExtra = is_array($sxProduct['extra'] ?? null) ? $sxProduct['extra'] : [];
            if (empty($sxExtra['sphinx_booking']) || empty($sxExtra['travel_booking_id'])) {
                continue;
            }
            $sxExtra['travel_surrogate_id'] = (int) db_get_field(
                "SELECT booking_id FROM ?:travel_bookings WHERE provider = 'sphinx' AND provider_booking_id = ?s",
                (string) $sxExtra['travel_booking_id']
            );
            $sxProduct['extra'] = $sxExtra;
        }
        unset($sxProduct);
    }

    $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
    $bookings = $repo->findByOrderId((int) $order['order_id']);

    foreach ($bookings as $booking) {
        if (($booking['status'] ?? '') === \Tygh\Addons\TravelCore\TravelConstants::STATUS_FAILED) {
            $hotelName = $booking['hotel_name'] ?? '';
            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.booking_api_failed', [
                    '[hotel]' => $hotelName,
                    '[order_id]' => $order['order_id'],
                    '[default]' => 'Sphinx booking failed for hotel "' . $hotelName . '" in order #' . $order['order_id'] . '. Please verify and resubmit.',
                ])
            );
            break; // One notification per order is enough
        }
    }
}

/**
 * Hook: travel_core_exchange_rates_updated
 *
 * Logs the exchange rate update result from travel_core to sphinx_sync_log
 * so the admin panel can display "last updated" timestamps.
 *
 * @param array $result Full result from fn_travel_core_update_exchange_rates()
 */
function fn_sphinx_holidays_travel_core_exchange_rates_updated(array &$result): void
{
    if (empty($result['success'])) {
        return;
    }

    $updates = $result['updates'] ?? [];
    $total = count($updates);
    $synced = count(array_filter($updates, fn($u) => $u['success'] ?? false));

    db_query(
        "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, error_message, started_at, completed_at) VALUES (?s, ?s, ?i, ?i, ?i, ?s, NOW(), NOW())",
        'exchange_rates',
        'completed',
        $total,
        $synced,
        $total - $synced,
        ''
    );
}

/**
 * Download an external image URL and attach it to a CS-Cart product.
 *
 * Uses CS-Cart's fn_update_image_pairs() to properly generate thumbnails
 * and store the image in the standard product gallery.
 *
 * On failure, the short reason is exposed via ImageHelper::$lastDownloadError
 * so cron callers can echo it without inspecting the CS-Cart event log.
 *
 * @param int    $product_id CS-Cart product ID
 * @param string $image_url  External image URL to download
 * @param bool   $is_main    True for main product image, false for additional
 * @return bool True on success
 */
function fn_sphinx_holidays_add_product_image(int $product_id, string $image_url, bool $is_main = false): bool
{
    \Tygh\Addons\SphinxHolidays\Api\ImageHelper::$lastDownloadError = '';
    if (empty($product_id) || empty($image_url)) {
        \Tygh\Addons\SphinxHolidays\Api\ImageHelper::$lastDownloadError = 'empty args';
        return false;
    }

    $temp_file = fn_create_temp_file();
    if (!$temp_file) {
        \Tygh\Addons\SphinxHolidays\Api\ImageHelper::$lastDownloadError = 'temp file failed';
        fn_log_event('general', 'runtime', ['message' => "Sphinx: fn_create_temp_file() returned empty for product #{$product_id}"]);
        return false;
    }

    // Only API-hosted images need auth headers + watermark removal via cURL.
    // CDN images are public — delegate to the URL approach (fn_update_product pipeline).
    $apiBaseUrl  = \Tygh\Addons\SphinxHolidays\Services\ConfigProvider::getApiBaseUrl();
    $isApiHosted = \Tygh\Addons\SphinxHolidays\Api\ImageHelper::matchesApiHost($image_url, $apiBaseUrl);

    if (!$isApiHosted) {
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        return fn_travel_core_attach_images_from_urls($product_id, [$image_url], $is_main) > 0;
    }

    // API-hosted: must strip watermark param and send Bearer auth headers.
    // CS-Cart's URL-fetch path cannot carry custom headers, so cURL is required.
    $download_url = \Tygh\Addons\SphinxHolidays\Api\ImageHelper::withoutWatermark($image_url);
    $headers      = \Tygh\Addons\SphinxHolidays\Api\ImageHelper::getCurlAuthHeaders();

    $fp = fopen($temp_file, 'wb');
    if (!$fp) {
        \Tygh\Addons\SphinxHolidays\Api\ImageHelper::$lastDownloadError = 'fopen failed';
        fn_log_event('general', 'runtime', ['message' => "Sphinx: fopen() failed for temp file '{$temp_file}' product #{$product_id}"]);
        if (file_exists($temp_file)) { unlink($temp_file); }
        return false;
    }

    $ch = curl_init($download_url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'CS-Cart/SphinxHolidays ImageSync/1.0',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);

    curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($httpCode !== 200 || !file_exists($temp_file) || filesize($temp_file) < 1000) {
        \Tygh\Addons\SphinxHolidays\Api\ImageHelper::$lastDownloadError = "HTTP {$httpCode}" . ($curlError ? " ({$curlError})" : '');
        fn_log_event('general', 'runtime', ['message' => "Sphinx: image download failed for product #{$product_id}: HTTP {$httpCode}, size=" . (file_exists($temp_file) ? filesize($temp_file) : 'N/A') . ", url={$download_url}" . ($curlError ? " ({$curlError})" : '')]);
        if (file_exists($temp_file)) { unlink($temp_file); }
        return false;
    }

    return fn_travel_core_attach_product_image($product_id, $temp_file, 'sphinx', $is_main);
}

/**
 * Get hotels with filtering, sorting, and pagination (fn_get_products pattern).
 *
 * @param array $params Search/filter/sort parameters from $_REQUEST
 * @return array{0: array, 1: array} [$hotels, $search_params]
 */
function fn_sphinx_holidays_get_hotels(array $params = []): array
{
    $default_params = [
        'page'           => 1,
        'items_per_page' => (int) \Tygh\Registry::get('settings.Appearance.admin_elements_per_page') ?: 50,
        'sort_by'        => 'name',
        'sort_order'     => 'asc',
        'country_code'   => '',
        'region_id'      => 0,
        'destination_id' => 0,
        'sync_status'    => '',
        'classification' => '',
        'property_type'  => '',
        'link_status'    => '',
        'q'              => '',
        'city'           => '',
    ];

    $params = array_merge($default_params, array_intersect_key($params, $default_params));
    $params['page'] = max(1, (int) $params['page']);
    $params['items_per_page'] = max(1, (int) $params['items_per_page']);
    $params['region_id'] = (int) $params['region_id'];
    $params['destination_id'] = (int) $params['destination_id'];
    $params['q'] = trim((string) $params['q']);
    $params['city'] = trim(\Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($params['city']));

    // Sortings map: allowed sort columns
    $sortings = [
        'hotel_id'       => 'h.hotel_id',
        'name'           => 'h.name',
        'classification' => 'h.classification',
        'country_code'   => 'h.country_code',
        'address_city'   => 'h.address_city',
        'address_country' => 'h.address_country',
        'sync_status'    => 'h.sync_status',
        'last_synced_at' => 'h.last_synced_at',
        'property_type'  => 'h.property_type',
    ];

    $sort_by = isset($sortings[(string) $params['sort_by']]) ? (string) $params['sort_by'] : 'name';
    $sort_order = strtolower((string) $params['sort_order']) === 'desc' ? 'DESC' : 'ASC';
    $sort_column = $sortings[$sort_by];

    $params['sort_by'] = $sort_by;
    $params['sort_order'] = strtolower($sort_order);
    $params['sort_order_toggle'] = ($sort_order === 'ASC') ? 'desc' : 'asc';

    // Build WHERE condition
    $condition = '';

    if ($params['country_code'] !== '') {
        $condition .= db_quote(" AND h.country_code = ?s", $params['country_code']);
    }
    if ($params['region_id'] > 0) {
        $condition .= db_quote(" AND h.region_id = ?i", $params['region_id']);
    }
    if ($params['destination_id'] > 0) {
        $condition .= db_quote(" AND h.destination_id = ?i", $params['destination_id']);
    }
    if ($params['sync_status'] !== '') {
        $condition .= db_quote(" AND h.sync_status = ?s", $params['sync_status']);
    }
    if ($params['classification'] !== '') {
        $classification = (int) $params['classification'];
        if ($classification === 0) {
            $condition .= " AND (h.classification IS NULL OR h.classification = 0)";
        } else {
            $condition .= db_quote(" AND h.classification = ?i", $classification);
        }
    }
    if ($params['property_type'] !== '') {
        $condition .= db_quote(" AND h.property_type = ?s", $params['property_type']);
    }
    if ($params['link_status'] === 'linked') {
        $condition .= " AND h.product_id IS NOT NULL AND h.product_id > 0";
    } elseif ($params['link_status'] === 'orphan') {
        $condition .= " AND (h.product_id IS NULL OR h.product_id = 0)";
    }
    if ($params['q'] !== '') {
        $condition .= db_quote(" AND h.name LIKE ?l", '%' . $params['q'] . '%');
    }
    if ($params['city'] !== '') {
        $condition .= db_quote(" AND h.address_city LIKE ?l", '%' . $params['city'] . '%');
    }

    // Total count
    $params['total_items'] = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:sphinx_hotels h WHERE 1 ?p",
        $condition
    );

    // Pagination
    $offset = ($params['page'] - 1) * $params['items_per_page'];

    // Select listing columns (prefixed with alias)
    $listing_cols = 'h.hotel_id, h.product_id, h.name, h.classification, h.property_type, '
        . 'h.destination_id, h.destination_name, h.region_id, h.region_name, '
        . 'h.country_code, h.country_name, h.address_city, h.address_country, h.latitude, h.longitude, '
        . 'h.image_url, h.is_recommended, h.is_adults_only, h.rating, h.rating_count, '
        . 'h.sync_status, h.last_synced_at, h.created_at, h.updated_at, h.product_skip_reason';

    $hotels = db_get_array(
        "SELECT {$listing_cols} FROM ?:sphinx_hotels h"
        . " WHERE 1 ?p"
        . " ORDER BY {$sort_column} {$sort_order}"
        . " LIMIT ?i, ?i",
        $condition,
        $offset,
        $params['items_per_page']
    );

    return [is_array($hotels) ? $hotels : [], $params];
}

/**
 * Write a single pre-assembled cart-product row into the current session cart,
 * then trigger CS-Cart's calculate + save trio.
 *
 * Thin wrapper that absorbs the reference-based `$cart`/`$auth` handling
 * required by CS-Cart's procedural cart API. Living at the func.php boundary
 * keeps `\Tygh::\$app` out of `src/Services/CartService`.
 *
 * @param array<string, mixed> $row Pre-assembled cart-product entry (shape:
 *                                  product_id, amount, price, extra, …).
 */
function fn_sphinx_holidays_write_cart_row(string $cartId, array $row): void
{
    $cart = &\Tygh\Tygh::$app['session']['cart'];
    $auth = &\Tygh\Tygh::$app['session']['auth'];

    if (empty($cart)) {
        fn_clear_cart($cart);
    }

    $cart['products'][$cartId] = $row;

    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);
}

/**
 * Idempotent schema-delta applier for EXISTING installs.
 *
 * The addon.xml `for="upgrade"` ALTERs are inert in practice: the addon
 * version is pinned at 1.0.0 and no CS-Cart upgrade scheme exists, so
 * installed sites never received schema deltas — code shipped ahead of a
 * hand-applied ALTER crashed on missing columns (INSTALL.md §8 documented
 * this as a manual deploy-checklist step). Mirrors travel_core's
 * fn_travel_core_ensure_schema(): called on admin page load (init.php,
 * AREA 'A'), static-guarded per request, and only ever ADDS what is
 * missing — fresh installs already get complete tables from the base
 * CREATEs, and tables that don't exist yet are skipped entirely.
 *
 * Keep this list in sync with addon.xml's `for="upgrade"` items: every new
 * upgrade ALTER gets a matching entry here so existing installs converge.
 */
function fn_sphinx_holidays_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $prefixRaw = Registry::get('config.table_prefix');
    $prefix = is_scalar($prefixRaw) ? (string) $prefixRaw : 'cscart_';

    // table (unprefixed) => column => ADD COLUMN definition
    $columns = [
        'sphinx_hotels' => [
            'address' => "ADD COLUMN `address` VARCHAR(500) DEFAULT NULL COMMENT 'Street address from API' AFTER `longitude`",
            'phone' => "ADD COLUMN `phone` VARCHAR(50) DEFAULT NULL COMMENT 'Hotel phone number' AFTER `address`",
            'email' => "ADD COLUMN `email` VARCHAR(255) DEFAULT NULL COMMENT 'Hotel email address' AFTER `phone`",
            'website' => "ADD COLUMN `website` VARCHAR(500) DEFAULT NULL COMMENT 'Hotel website URL' AFTER `email`",
            'address_city' => "ADD COLUMN `address_city` VARCHAR(255) DEFAULT NULL COMMENT 'City from the hotel API address' AFTER `website`",
            'address_country' => "ADD COLUMN `address_country` VARCHAR(255) DEFAULT NULL COMMENT 'Country from the hotel API address' AFTER `address_city`",
            'product_skip_reason' => "ADD COLUMN `product_skip_reason` VARCHAR(50) DEFAULT NULL COMMENT 'Why product linking was skipped (category_failed, invalid_country, etc.)' AFTER `product_id`",
            'images_json' => "ADD COLUMN `images_json` JSON DEFAULT NULL COMMENT 'All image objects from API [{url, ...}, ...]' AFTER `image_url`",
            'product_needs_update' => "ADD COLUMN `product_needs_update` ENUM('Y','N') DEFAULT 'N' COMMENT 'Set to Y when API data differs from CS-Cart product' AFTER `images_json`",
            'calendar_prices_raw' => "ADD COLUMN `calendar_prices_raw` JSON DEFAULT NULL COMMENT 'Per-date raw from-prices for the booking calendar (calendar_prices cron)' AFTER `sync_status`",
        ],
        'sphinx_destinations' => [
            'full_path' => "ADD COLUMN `full_path` VARCHAR(500) DEFAULT NULL COMMENT 'Breadcrumb: City, Region, Country, Continent' AFTER `country_code`",
        ],
        'sphinx_sync_log' => [
            'rate_limit_hits' => 'ADD COLUMN `rate_limit_hits` INT UNSIGNED DEFAULT 0 AFTER `error_message`',
            'sync_mode' => "ADD COLUMN `sync_mode` VARCHAR(20) DEFAULT 'full' AFTER `rate_limit_hits`",
        ],
        'sphinx_bookings' => [
            'payment_terms_json' => "ADD COLUMN `payment_terms_json` JSON DEFAULT NULL COMMENT 'Payment terms from API (verify / orders sync)' AFTER `api_response`",
            'cancellation_fees_json' => "ADD COLUMN `cancellation_fees_json` JSON DEFAULT NULL COMMENT 'Cancellation fees from API (verify / orders sync)' AFTER `payment_terms_json`",
            'pricing_json' => "ADD COLUMN `pricing_json` JSON DEFAULT NULL COMMENT 'Full pricing breakdown from verify (marketing/discount/selling/commission/supplier/taxes/fees)' AFTER `cancellation_fees_json`",
        ],
    ];

    // table => index => ADD KEY definition
    $indexes = [
        'sphinx_hotels' => [
            'idx_sync_product' => 'ADD KEY `idx_sync_product` (`sync_status`, `product_id`)',
            'idx_sync_country' => 'ADD KEY `idx_sync_country` (`sync_status`, `country_code`)',
            'idx_country_sync_synced' => 'ADD KEY `idx_country_sync_synced` (`country_code`, `sync_status`, `last_synced_at`)',
        ],
        'sphinx_destinations' => [
            'idx_full_path' => 'ADD KEY `idx_full_path` (`full_path`(100))',
        ],
    ];

    $tables = array_values(array_unique(array_merge(array_keys($columns), array_keys($indexes), ['sphinx_cache'])));
    $prefixed = array_map(static fn (string $t): string => $prefix . $t, $tables);

    // Two catalog reads cover every check below.
    $columnRows = db_get_array(
        'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?a)',
        $prefixed,
    );
    $indexRows = db_get_array(
        'SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?a)',
        $prefixed,
    );
    if (!is_array($columnRows) || !is_array($indexRows)) {
        return;
    }

    $haveColumn = [];
    $cacheDataType = '';
    foreach ($columnRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = isset($row['TABLE_NAME']) && is_scalar($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
        $column = isset($row['COLUMN_NAME']) && is_scalar($row['COLUMN_NAME']) ? (string) $row['COLUMN_NAME'] : '';
        $haveColumn[$table][$column] = true;
        if ($table === $prefix . 'sphinx_cache' && $column === 'cache_data') {
            $cacheDataType = isset($row['DATA_TYPE']) && is_scalar($row['DATA_TYPE']) ? strtolower((string) $row['DATA_TYPE']) : '';
        }
    }
    $haveIndex = [];
    foreach ($indexRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = isset($row['TABLE_NAME']) && is_scalar($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
        $index = isset($row['INDEX_NAME']) && is_scalar($row['INDEX_NAME']) ? (string) $row['INDEX_NAME'] : '';
        $haveIndex[$table][$index] = true;
    }

    foreach ($columns as $table => $defs) {
        if (!isset($haveColumn[$prefix . $table])) {
            continue; // table absent — addon not (fully) installed; base CREATE owns it
        }
        foreach ($defs as $column => $ddl) {
            if (!isset($haveColumn[$prefix . $table][$column])) {
                db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
            }
        }
    }

    foreach ($indexes as $table => $defs) {
        if (!isset($haveColumn[$prefix . $table])) {
            continue;
        }
        foreach ($defs as $index => $ddl) {
            if (!isset($haveIndex[$prefix . $table][$index])) {
                db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
            }
        }
    }

    // Charset fix: cache_data was LONGBLOB before the UTF-8 correction.
    if ($cacheDataType === 'longblob') {
        db_query('ALTER TABLE ?:sphinx_cache MODIFY `cache_data` LONGTEXT NOT NULL');
    }

    // Widen coordinate columns to the 8-decimal standard (latitude DECIMAL(10,8),
    // longitude DECIMAL(11,8); originally DECIMAL(10,7)). Idempotent: MODIFY only
    // when the stored scale is still below 8, so it runs once then no-ops.
    $coordScaleRows = db_get_array(
        "SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_SCALE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('latitude', 'longitude')
           AND TABLE_NAME IN (?a)",
        [$prefix . 'sphinx_hotels', $prefix . 'sphinx_destinations'],
    );
    if (is_array($coordScaleRows)) {
        $coordScale = [];
        foreach ($coordScaleRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $coordTableName = $row['TABLE_NAME'] ?? null;
            $coordColName = $row['COLUMN_NAME'] ?? null;
            if (!is_string($coordTableName) || !is_string($coordColName)) {
                continue;
            }
            $coordRowScale = $row['NUMERIC_SCALE'] ?? null;
            $coordScale[$coordTableName][$coordColName] = is_numeric($coordRowScale) ? (int) $coordRowScale : 0;
        }
        foreach (['sphinx_hotels', 'sphinx_destinations'] as $coordTable) {
            $have = $coordScale[$prefix . $coordTable] ?? null;
            if ($have !== null && (($have['latitude'] ?? 0) < 8 || ($have['longitude'] ?? 0) < 8)) {
                db_query(
                    'ALTER TABLE ?:' . $coordTable
                    . ' MODIFY `latitude` DECIMAL(10,8) DEFAULT NULL, MODIFY `longitude` DECIMAL(11,8) DEFAULT NULL'
                );
            }
        }
    }
}
