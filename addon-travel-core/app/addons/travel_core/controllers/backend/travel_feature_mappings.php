<?php
declare(strict_types=1);
/**
 * Travel Core - Feature Mappings Controller
 *
 * Admin controller for managing the travel_feature_map + travel_api_alias tables.
 * Supports listing, editing, activating/deactivating, re-seeding,
 * auto-resolving variants, and managing aliases.
 *
 * @package TravelCore
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// Valid feature types for the shared mapping
$validFeatureTypes = ['board', 'room_type', 'stars', 'property_type', 'facility', 'travel_group', 'resort', 'region', 'city', 'beach_access'];

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update mapping
    if ($mode == 'update') {
        $mapId = (int) ($_REQUEST['map_id'] ?? 0);
        if ($mapId > 0 && !empty($_REQUEST['mapping_data'])) {
            $data = $_REQUEST['mapping_data'];
            $updateData = [];

            // Allowed editable fields
            if (isset($data['display_name_en'])) {
                $updateData['display_name_en'] = (string) $data['display_name_en'];
            }
            if (isset($data['display_name_ro'])) {
                $updateData['display_name_ro'] = (string) $data['display_name_ro'];
            }
            if (isset($data['cscart_feature_id'])) {
                $updateData['cscart_feature_id'] = (int) $data['cscart_feature_id'] ?: null;
            }
            if (isset($data['cscart_variant_id'])) {
                $updateData['cscart_variant_id'] = (int) $data['cscart_variant_id'] ?: null;
                // Mark as manually set to prevent auto-overwrite
                if ((int) ($data['cscart_variant_id'] ?? 0) > 0) {
                    $updateData['variant_source'] = 'manual';
                }
            }
            if (isset($data['position'])) {
                $updateData['position'] = (int) $data['position'];
            }
            if (isset($data['status'])) {
                $updateData['status'] = $data['status'] === 'A' ? 'A' : 'D';
            }

            if (!empty($updateData)) {
                db_query("UPDATE ?:travel_feature_map SET ?u WHERE map_id = ?i", $updateData, $mapId);
                FeatureMapper::clearCache();
                fn_set_notification('N', __('notice'), __('travel_core.fm_mapping_updated'));
            }
        }

        $redirectParams = !empty($_REQUEST['feature_type_filter']) ? '&feature_type=' . urlencode($_REQUEST['feature_type_filter']) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . $redirectParams];
    }

    // Bulk update (toggle status, delete)
    if ($mode == 'bulk_update') {
        $action = $_REQUEST['dispatch_extra'] ?? '';
        $ids = $_REQUEST['map_ids'] ?? [];

        if (!empty($ids) && is_array($ids)) {
            $ids = array_map('intval', $ids);

            if ($action === 'activate') {
                db_query("UPDATE ?:travel_feature_map SET status = 'A' WHERE map_id IN (?n)", $ids);
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_activated', ['[count]' => count($ids)]));
            } elseif ($action === 'deactivate') {
                db_query("UPDATE ?:travel_feature_map SET status = 'D' WHERE map_id IN (?n)", $ids);
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_deactivated', ['[count]' => count($ids)]));
            } elseif ($action === 'delete') {
                // Delete aliases first, then the mapping
                db_query("DELETE FROM ?:travel_api_alias WHERE map_id IN (?n)", $ids);
                db_query("DELETE FROM ?:travel_feature_map WHERE map_id IN (?n)", $ids);
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_deleted', ['[count]' => count($ids)]));
            }
            FeatureMapper::clearCache();
        }

        $redirectParams = !empty($_REQUEST['feature_type']) ? '&feature_type=' . urlencode($_REQUEST['feature_type']) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . $redirectParams];
    }

    // Re-seed canonical codes
    if ($mode == 'reseed') {
        fn_travel_core_seed_feature_map();
        fn_set_notification('N', __('notice'), __('travel_core.fm_reseeded'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Auto-resolve unmapped variants by name-matching + auto-create
    if ($mode == 'resolve_variants') {

        // Step 1: Auto-populate cscart_feature_id from addon settings for entries that don't have one
        $featureTypes = db_get_fields(
            "SELECT DISTINCT feature_type FROM ?:travel_feature_map WHERE cscart_feature_id = 0 OR cscart_feature_id IS NULL"
        );
        foreach ($featureTypes as $ft) {
            $fid = FeatureMapper::getFeatureId($ft);
            if ($fid > 0) {
                db_query(
                    "UPDATE ?:travel_feature_map SET cscart_feature_id = ?i WHERE feature_type = ?s AND (cscart_feature_id = 0 OR cscart_feature_id IS NULL)",
                    $fid, $ft
                );
            }
        }

        // Step 2: Query entries that have a feature_id but no variant yet
        // Exclude manually locked mappings (variant_source='manual')
        $unmapped = db_get_array(
            "SELECT * FROM ?:travel_feature_map
             WHERE (cscart_variant_id IS NULL OR cscart_variant_id = 0)
             AND cscart_feature_id > 0 AND status = 'A'
             AND (variant_source IS NULL OR variant_source != 'manual')"
        );

        $resolved = 0;
        $created = 0;
        $failed = 0;

        // Group unmapped rows by cscart_feature_id for batch lookup
        $byFeature = [];
        foreach ($unmapped as $mapping) {
            $fid = (int) $mapping['cscart_feature_id'];
            if ($fid > 0) {
                $byFeature[$fid][] = $mapping;
            } else {
                $failed++;
            }
        }

        // Batch-load variant names per feature_id
        foreach ($byFeature as $featureId => $mappings) {
            $variantNameToId = db_get_hash_single_array(
                "SELECT vd.variant, v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = 'en'",
                ['variant', 'variant_id'],
                $featureId
            );

            foreach ($mappings as $mapping) {
                $nameEn = trim($mapping['display_name_en'] ?? '');
                if ($nameEn === '') {
                    $failed++;
                    continue;
                }

                $variantId = null;

                // Pass 1: exact match
                $variantId = $variantNameToId[$nameEn] ?? null;

                // Pass 2: case-insensitive match
                if (!$variantId) {
                    $nameEnLower = mb_strtolower($nameEn);
                    foreach ($variantNameToId as $vName => $vId) {
                        if (mb_strtolower($vName) === $nameEnLower) {
                            $variantId = $vId;
                            break;
                        }
                    }
                }

                // Pass 3: normalized match (strip punctuation, collapse whitespace)
                if (!$variantId) {
                    $normalizedTarget = preg_replace('/\s+/', ' ', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($nameEn, 'UTF-8'))));
                    foreach ($variantNameToId as $vName => $vId) {
                        $normalizedExisting = preg_replace('/\s+/', ' ', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($vName, 'UTF-8'))));
                        if ($normalizedExisting === $normalizedTarget) {
                            $variantId = $vId;
                            break;
                        }
                    }
                }

                if ($variantId) {
                    FeatureMapper::updateVariantId((int) $mapping['map_id'], (int) $variantId, 'auto');
                    $resolved++;
                } else {
                    // Auto-create the variant
                    $nameRo = trim($mapping['display_name_ro'] ?? '') ?: $nameEn;
                    $languages = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
                    if (empty($languages)) {
                        $languages = ['en'];
                    }

                    $newVariantId = (int) db_query(
                        "INSERT INTO ?:product_feature_variants ?e",
                        ['feature_id' => $featureId, 'position' => 0]
                    );

                    if ($newVariantId > 0) {
                        foreach ($languages as $langCode) {
                            $variantName = ($langCode === 'ro') ? $nameRo : $nameEn;
                            db_query(
                                "INSERT INTO ?:product_feature_variant_descriptions (variant_id, lang_code, variant)
                                 VALUES (?i, ?s, ?s) ON DUPLICATE KEY UPDATE variant = ?s",
                                $newVariantId, $langCode, $variantName, $variantName
                            );
                        }
                        FeatureMapper::updateVariantId((int) $mapping['map_id'], $newVariantId, 'auto');
                        // Add to local cache so subsequent mappings can match
                        $variantNameToId[$nameEn] = $newVariantId;
                        $created++;
                    } else {
                        $failed++;
                    }
                }
            }
        }

        FeatureMapper::clearCache();
        fn_set_notification('N', __('notice'), __('travel_core.fm_variants_resolved', [
            '[resolved]' => $resolved + $created,
            '[failed]' => $failed,
        ]));

        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Add alias
    if ($mode == 'add_alias') {
        $mapId = (int) ($_REQUEST['map_id'] ?? 0);
        $apiSource = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_REQUEST['api_source'] ?? '')));
        $apiValue = (string) ($_REQUEST['api_value'] ?? '');
        $validMatchTypes = ['exact', 'prefix', 'contains'];
        $matchType = (string) ($_REQUEST['match_type'] ?? 'exact');
        if (!in_array($matchType, $validMatchTypes, true)) {
            $matchType = 'exact';
        }

        if ($mapId > 0 && $apiSource !== '' && $apiValue !== '') {
            FeatureMapper::addAlias($apiSource, $apiValue, $mapId, $matchType);
            FeatureMapper::clearCache();
            fn_set_notification('N', __('notice'), __('travel_core.fm_alias_added'));
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
    }

    // Delete alias
    if ($mode == 'delete_alias') {
        $aliasId = (int) ($_REQUEST['alias_id'] ?? 0);
        $mapId = (int) ($_REQUEST['map_id'] ?? 0);
        if ($aliasId > 0) {
            db_query("DELETE FROM ?:travel_api_alias WHERE alias_id = ?i", $aliasId);
            FeatureMapper::clearCache();
            fn_set_notification('N', __('notice'), __('travel_core.fm_alias_deleted'));
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
    }

    // Promote unmapped value to a real mapping
    if ($mode === 'map_unmapped') {
        $unmappedId = (int) ($_REQUEST['unmapped_id'] ?? 0);
        if ($unmappedId > 0) {
            $row = db_get_row("SELECT * FROM ?:travel_unmapped_values WHERE unmapped_id = ?i", $unmappedId);
            if ($row) {
                $mapId = FeatureMapper::registerUnmapped(
                    $row['api_source'], $row['feature_type'], $row['api_value'], $row['api_label'] ?? ''
                );
                if ($mapId) {
                    fn_set_notification('N', __('notice'), __('travel_core.fm_unmapped_promoted'));
                    return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
                }
            }
        }
        fn_set_notification('E', __('error'), __('travel_core.fm_unmapped_promote_failed'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.unmapped'];
    }

    // Batch scan provider hotel facilities → populate travel_unmapped_values
    if ($mode === 'scan_facilities') {
        $provider = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_REQUEST['scan_provider'] ?? '')));
        $batchSize = min(max((int) ($_REQUEST['batch_size'] ?? 500), 50), 2000);
        $offset = max(0, (int) ($_REQUEST['scan_offset'] ?? 0));

        if ($provider === '') {
            fn_set_notification('E', __('error'), 'No provider specified.');
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
        }

        // Provider-specific: determine source table and JSON column
        $scanConfig = _travel_fm_get_scan_config($provider);
        if (!$scanConfig) {
            fn_set_notification('E', __('error'), "Provider '{$provider}' does not support facility scanning.");
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
        }

        // Count total hotels (only on first batch)
        $totalHotels = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:{$scanConfig['table']} WHERE {$scanConfig['json_col']} IS NOT NULL AND {$scanConfig['json_col']} != '[]'"
        );

        // Fetch batch of hotels
        $hotels = db_get_array(
            "SELECT {$scanConfig['id_col']}, {$scanConfig['json_col']} FROM ?:{$scanConfig['table']} " .
            "WHERE {$scanConfig['json_col']} IS NOT NULL AND {$scanConfig['json_col']} != '[]' " .
            "ORDER BY {$scanConfig['id_col']} LIMIT ?i, ?i",
            $offset, $batchSize
        );

        $newUnmapped = 0;
        $totalFacilities = 0;

        foreach ($hotels as $hotel) {
            $facilities = json_decode($hotel[$scanConfig['json_col']], true);
            if (!is_array($facilities)) {
                continue;
            }

            foreach ($facilities as $facility) {
                $facilityId = (string) ($facility['id'] ?? '');
                $facilityName = (string) ($facility['name'] ?? '');
                if ($facilityId === '') {
                    continue;
                }
                $totalFacilities++;

                // Check if already mapped
                $mapping = FeatureMapper::resolve($provider, 'facility', $facilityId);
                if (!$mapping) {
                    // Track as unmapped (increments hotel_count if already exists)
                    FeatureMapper::trackUnmapped($provider, 'facility', $facilityId, $facilityName);
                    $newUnmapped++;
                }
            }
        }

        // Clear resolve cache to free memory between batches
        FeatureMapper::clearCache();

        $processedSoFar = $offset + count($hotels);
        $isComplete = count($hotels) < $batchSize || $processedSoFar >= $totalHotels;

        if ($isComplete) {
            $unmappedTotal = (int) db_get_field(
                "SELECT COUNT(*) FROM ?:travel_unmapped_values WHERE api_source = ?s AND feature_type = 'facility'",
                $provider
            );
            fn_set_notification('N', __('notice'), __('travel_core.fm_scan_complete', [
                '[provider]' => $provider,
                '[hotels]' => $processedSoFar,
                '[unmapped]' => $unmappedTotal,
            ]));
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.unmapped&api_source=' . $provider . '&feature_type=facility'];
        }

        // Not complete — redirect back with progress to continue
        $redirectUrl = 'travel_feature_mappings.scan_progress'
            . '&scan_provider=' . $provider
            . '&scan_offset=' . $processedSoFar
            . '&scan_total=' . $totalHotels
            . '&batch_size=' . $batchSize;

        return [CONTROLLER_STATUS_REDIRECT, $redirectUrl];
    }
}

// Helper: provider-specific scan configuration (from TravelProviderRegistry)
function _travel_fm_get_scan_config(string $provider): ?array
{
    return TravelProviderRegistry::getScanConfig($provider);
}

// ── GET: Manage (dashboard or paginated list) ──
if ($mode == 'manage') {
    $featureTypeFilter = $_REQUEST['feature_type'] ?? '';
    $statusFilter = $_REQUEST['status'] ?? '';
    $sourceFilter = $_REQUEST['mapping_source'] ?? '';
    $searchQuery = trim((string) ($_REQUEST['q'] ?? ''));

    // ── Dashboard mode (no feature_type selected) ──
    if (!$featureTypeFilter || !in_array($featureTypeFilter, $validFeatureTypes, true)) {

        // Per-type stats for dashboard cards
        $typeStats = db_get_hash_array(
            "SELECT m.feature_type,
                    COUNT(*) AS total,
                    SUM(m.status = 'A') AS active,
                    SUM(m.cscart_variant_id IS NULL OR m.cscart_variant_id = 0) AS unmapped,
                    SUM(m.mapping_source = 'auto') AS auto_registered,
                    GROUP_CONCAT(DISTINCT a.api_source ORDER BY a.api_source) AS providers
             FROM ?:travel_feature_map m
             LEFT JOIN ?:travel_api_alias a ON a.map_id = m.map_id
             GROUP BY m.feature_type
             ORDER BY FIELD(m.feature_type, 'facility', 'board', 'resort', 'stars', 'property_type', 'travel_group', 'room_type', 'region', 'city', 'beach_access')",
            'feature_type'
        );

        // Enrich with configured feature IDs
        foreach ($typeStats as $ft => &$stat) {
            $stat['feature_id'] = FeatureMapper::getFeatureId($ft);
            $stat['total'] = (int) ($stat['total'] ?? 0);
            $stat['active'] = (int) ($stat['active'] ?? 0);
            $stat['unmapped'] = (int) ($stat['unmapped'] ?? 0);
            $stat['auto_registered'] = (int) ($stat['auto_registered'] ?? 0);
        }
        unset($stat);

        // Unmapped values count
        $unmappedCount = (int) db_get_field("SELECT COUNT(*) FROM ?:travel_unmapped_values");

        // Global stats
        $globalStats = db_get_row(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'A') AS active,
                    SUM(cscart_variant_id IS NULL OR cscart_variant_id = 0) AS unmapped
             FROM ?:travel_feature_map"
        );
        $stats = [
            'total'    => (int) ($globalStats['total'] ?? 0),
            'active'   => (int) ($globalStats['active'] ?? 0),
            'unmapped' => (int) ($globalStats['unmapped'] ?? 0),
            'aliases'  => (int) db_get_field("SELECT COUNT(*) FROM ?:travel_api_alias"),
        ];

        // Human-readable labels for feature types
        $typeLabels = [
            'facility'      => 'Facilities',
            'board'         => 'Board / Meals',
            'resort'        => 'Resorts & Cities',
            'stars'         => 'Star Rating',
            'property_type' => 'Property Type',
            'travel_group'  => 'Travel Group',
            'room_type'     => 'Room Type',
            'region'        => 'Region',
            'city'          => 'City',
            'beach_access'  => 'Beach Access',
        ];

        // Providers with scan config (for "Scan Facilities" dropdown)
        $scanProviders = array_keys(TravelProviderRegistry::getAllScanConfigs());

        Tygh::$app['view']->assign('view_mode', 'dashboard');
        Tygh::$app['view']->assign('type_stats', $typeStats);
        Tygh::$app['view']->assign('type_labels', $typeLabels);
        Tygh::$app['view']->assign('unmapped_count', $unmappedCount);
        Tygh::$app['view']->assign('mapping_stats', $stats);
        Tygh::$app['view']->assign('feature_types', $validFeatureTypes);
        Tygh::$app['view']->assign('scan_providers', $scanProviders);

    } else {
        // ── List mode (feature_type selected, paginated) ──

        // Pagination params (CS-Cart standard)
        $page = max(1, (int) ($_REQUEST['page'] ?? 1));
        $itemsPerPage = (int) ($_REQUEST['items_per_page'] ?? 0);
        if ($itemsPerPage <= 0) {
            $itemsPerPage = (int) Registry::get('settings.Appearance.admin_elements_per_page') ?: 25;
        }
        $itemsPerPage = min($itemsPerPage, 250); // Cap at 250

        // Build WHERE condition using db_quote() (CS-Cart standard pattern)
        $condition = db_quote(" AND m.feature_type = ?s", $featureTypeFilter);

        if ($statusFilter === 'A' || $statusFilter === 'D') {
            $condition .= db_quote(" AND m.status = ?s", $statusFilter);
        }

        if ($sourceFilter !== '' && in_array($sourceFilter, ['seed', 'auto', 'manual'], true)) {
            $condition .= db_quote(" AND m.mapping_source = ?s", $sourceFilter);
        }

        if ($searchQuery !== '') {
            $escaped = addcslashes($searchQuery, '%_\\');
            $condition .= db_quote(
                " AND (m.canonical_code LIKE ?l OR m.display_name_en LIKE ?l OR m.display_name_ro LIKE ?l)",
                '%' . $escaped . '%', '%' . $escaped . '%', '%' . $escaped . '%'
            );
        }

        // Total count (for pagination)
        $totalItems = (int) db_get_field(
            "SELECT COUNT(*) FROM ?:travel_feature_map m WHERE 1 ?p",
            $condition
        );

        // Offset
        $offset = ($page - 1) * $itemsPerPage;
        if ($offset >= $totalItems && $totalItems > 0) {
            $page = 1;
            $offset = 0;
        }

        // Fetch paginated data
        $mappings = db_get_array(
            "SELECT m.*, COUNT(a.alias_id) as alias_count,
                    GROUP_CONCAT(DISTINCT a.api_source ORDER BY a.api_source) as api_sources
             FROM ?:travel_feature_map m
             LEFT JOIN ?:travel_api_alias a ON a.map_id = m.map_id
             WHERE 1 ?p
             GROUP BY m.map_id
             ORDER BY m.position, m.canonical_code
             LIMIT ?i, ?i",
            $condition, $offset, $itemsPerPage
        );

        // Resolve variant + feature names for display
        $variantIds = array_filter(array_unique(array_column($mappings, 'cscart_variant_id')));
        $variantNames = [];
        if (!empty($variantIds)) {
            $variantNames = db_get_hash_single_array(
                "SELECT variant_id, variant FROM ?:product_feature_variant_descriptions WHERE variant_id IN (?n) AND lang_code = ?s",
                ['variant_id', 'variant'], $variantIds, DESCR_SL
            );
        }

        $featureIds = array_filter(array_unique(array_column($mappings, 'cscart_feature_id')));
        $featureNames = [];
        if (!empty($featureIds)) {
            $featureNames = db_get_hash_single_array(
                "SELECT feature_id, description FROM ?:product_features_descriptions WHERE feature_id IN (?n) AND lang_code = ?s",
                ['feature_id', 'description'], $featureIds, DESCR_SL
            );
        }

        foreach ($mappings as &$m) {
            $m['variant_name'] = $variantNames[$m['cscart_variant_id']] ?? '';
            $m['feature_name'] = $featureNames[$m['cscart_feature_id']] ?? '';
        }
        unset($m);

        // Type-level stats for header
        $typeStats = db_get_row(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'A') AS active,
                    SUM(cscart_variant_id IS NULL OR cscart_variant_id = 0) AS unmapped
             FROM ?:travel_feature_map WHERE feature_type = ?s",
            $featureTypeFilter
        );

        // Human-readable labels
        $typeLabels = [
            'facility' => 'Facilities', 'board' => 'Board / Meals', 'resort' => 'Resorts & Cities',
            'stars' => 'Star Rating', 'property_type' => 'Property Type', 'travel_group' => 'Travel Group',
            'room_type' => 'Room Type', 'region' => 'Region', 'city' => 'City', 'beach_access' => 'Beach Access',
        ];

        // CS-Cart pagination: assign $search with standard keys
        $search = [
            'feature_type'   => $featureTypeFilter,
            'status'         => $statusFilter,
            'mapping_source' => $sourceFilter,
            'q'              => $searchQuery,
            'page'           => $page,
            'items_per_page' => $itemsPerPage,
            'total_items'    => $totalItems,
        ];

        Tygh::$app['view']->assign('view_mode', 'list');
        Tygh::$app['view']->assign('mappings', $mappings);
        Tygh::$app['view']->assign('search', $search);
        Tygh::$app['view']->assign('type_stats', $typeStats);
        Tygh::$app['view']->assign('type_label', $typeLabels[$featureTypeFilter] ?? $featureTypeFilter);
        Tygh::$app['view']->assign('configured_feature_id', FeatureMapper::getFeatureId($featureTypeFilter));
        Tygh::$app['view']->assign('feature_types', $validFeatureTypes);
    }
}

// ── GET: Unmapped values ──
if ($mode == 'unmapped') {
    $page = max(1, (int) ($_REQUEST['page'] ?? 1));
    $itemsPerPage = (int) ($_REQUEST['items_per_page'] ?? 0);
    if ($itemsPerPage <= 0) {
        $itemsPerPage = (int) Registry::get('settings.Appearance.admin_elements_per_page') ?: 25;
    }

    $sourceFilter = $_REQUEST['api_source'] ?? '';
    $typeFilter = $_REQUEST['feature_type'] ?? '';

    // Build condition using db_quote() (CS-Cart standard pattern)
    $condition = '';
    if ($sourceFilter !== '') {
        $condition .= db_quote(" AND api_source = ?s", $sourceFilter);
    }
    if ($typeFilter !== '') {
        $condition .= db_quote(" AND feature_type = ?s", $typeFilter);
    }

    $totalItems = (int) db_get_field("SELECT COUNT(*) FROM ?:travel_unmapped_values WHERE 1 ?p", $condition);
    $offset = ($page - 1) * $itemsPerPage;

    $unmapped = db_get_array(
        "SELECT * FROM ?:travel_unmapped_values WHERE 1 ?p ORDER BY hotel_count DESC, last_seen_at DESC LIMIT ?i, ?i",
        $condition, $offset, $itemsPerPage
    );

    $search = [
        'api_source'     => $sourceFilter,
        'feature_type'   => $typeFilter,
        'page'           => $page,
        'items_per_page' => $itemsPerPage,
        'total_items'    => $totalItems,
    ];

    Tygh::$app['view']->assign('unmapped_values', $unmapped);
    Tygh::$app['view']->assign('search', $search);
}

// ── GET: Scan progress (intermediate page between batches) ──
if ($mode == 'scan_progress') {
    $provider = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_REQUEST['scan_provider'] ?? '')));
    $scanOffset = max(0, (int) ($_REQUEST['scan_offset'] ?? 0));
    $scanTotal = max(0, (int) ($_REQUEST['scan_total'] ?? 0));
    $batchSize = min(max((int) ($_REQUEST['batch_size'] ?? 500), 50), 2000);

    $percent = $scanTotal > 0 ? round($scanOffset / $scanTotal * 100, 1) : 0;

    Tygh::$app['view']->assign('scan_provider', $provider);
    Tygh::$app['view']->assign('scan_offset', $scanOffset);
    Tygh::$app['view']->assign('scan_total', $scanTotal);
    Tygh::$app['view']->assign('scan_percent', $percent);
    Tygh::$app['view']->assign('batch_size', $batchSize);
}

// ── GET: Edit single mapping ──
if ($mode == 'edit') {
    $mapId = (int) ($_REQUEST['map_id'] ?? 0);

    if ($mapId <= 0) {
        fn_set_notification('E', __('error'), __('travel_core.fm_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    $mapping = db_get_row("SELECT * FROM ?:travel_feature_map WHERE map_id = ?i", $mapId);
    if (empty($mapping)) {
        fn_set_notification('E', __('error'), __('travel_core.fm_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Load all CS-Cart product features for the feature dropdown
    $allFeatures = db_get_array(
        "SELECT f.feature_id, f.feature_type, fd.description
         FROM ?:product_features f
         LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = ?s
         ORDER BY fd.description",
        DESCR_SL
    );

    // Load variants for the currently selected feature
    $featureVariants = [];
    if (!empty($mapping['cscart_feature_id'])) {
        $featureVariants = db_get_array(
            "SELECT v.variant_id, vd.variant as name
             FROM ?:product_feature_variants v
             LEFT JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id AND vd.lang_code = ?s
             WHERE v.feature_id = ?i
             ORDER BY v.position, vd.variant",
            DESCR_SL,
            (int) $mapping['cscart_feature_id']
        );
    }

    // Load aliases for this mapping
    $aliases = db_get_array(
        "SELECT * FROM ?:travel_api_alias WHERE map_id = ?i ORDER BY api_source, api_value",
        $mapId
    );

    Tygh::$app['view']->assign('mapping', $mapping);
    Tygh::$app['view']->assign('all_features', $allFeatures);
    Tygh::$app['view']->assign('feature_variants', $featureVariants);
    Tygh::$app['view']->assign('aliases', $aliases);
}

// ── GET: AJAX — load variants for a feature (used by edit page) ──
if ($mode == 'get_variants') {
    $featureId = (int) ($_REQUEST['feature_id'] ?? 0);
    $variants = [];

    if ($featureId > 0) {
        $variants = db_get_array(
            "SELECT v.variant_id, vd.variant as name
             FROM ?:product_feature_variants v
             LEFT JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id AND vd.lang_code = ?s
             WHERE v.feature_id = ?i
             ORDER BY v.position, vd.variant",
            DESCR_SL,
            $featureId
        );
    }

    if (defined('AJAX_REQUEST')) {
        Tygh::$app['ajax']->assign('variants', $variants);
    } else {
        header('Content-Type: application/json');
        fn_echo(json_encode($variants));
        exit;
    }
}
