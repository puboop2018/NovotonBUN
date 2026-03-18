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

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// Valid feature types for the shared mapping
$validFeatureTypes = ['board', 'room_type', 'stars', 'property_type', 'facility'];

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

    // Auto-resolve unmapped variants by name-matching
    if ($mode == 'resolve_variants') {
        $unmapped = db_get_array(
            "SELECT * FROM ?:travel_feature_map
             WHERE (cscart_variant_id IS NULL OR cscart_variant_id = 0)
             AND cscart_feature_id > 0 AND status = 'A'"
        );

        $resolved = 0;
        $failed = 0;

        foreach ($unmapped as $mapping) {
            $featureId = (int) $mapping['cscart_feature_id'];
            $nameEn = trim($mapping['display_name_en'] ?? '');
            $mapId = (int) $mapping['map_id'];

            if ($featureId <= 0 || $nameEn === '') {
                $failed++;
                continue;
            }

            // Try name-match against existing CS-Cart variants
            $variantId = db_get_field(
                "SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = 'en' AND vd.variant = ?s
                 LIMIT 1",
                $featureId, $nameEn
            );

            if ($variantId) {
                db_query("UPDATE ?:travel_feature_map SET cscart_variant_id = ?i WHERE map_id = ?i", (int) $variantId, $mapId);
                $resolved++;
            } else {
                $failed++;
            }
        }

        FeatureMapper::clearCache();
        fn_set_notification('N', __('notice'), __('travel_core.fm_variants_resolved', [
            '[resolved]' => $resolved,
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
}

// ── GET: Manage (list) ──
if ($mode == 'manage') {
    $featureTypeFilter = $_REQUEST['feature_type'] ?? '';
    $statusFilter = $_REQUEST['status'] ?? '';

    $conditions = [];
    $params = [];

    if ($featureTypeFilter && in_array($featureTypeFilter, $validFeatureTypes, true)) {
        $conditions[] = "m.feature_type = ?s";
        $params[] = $featureTypeFilter;
    }

    if ($statusFilter === 'A' || $statusFilter === 'D') {
        $conditions[] = "m.status = ?s";
        $params[] = $statusFilter;
    }

    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "SELECT m.*, COUNT(a.alias_id) as alias_count
              FROM ?:travel_feature_map m
              LEFT JOIN ?:travel_api_alias a ON a.map_id = m.map_id
              {$where}
              GROUP BY m.map_id
              ORDER BY m.feature_type, m.position, m.canonical_code";

    switch (count($params)) {
        case 0:
            $mappings = db_get_array($query);
            break;
        case 1:
            $mappings = db_get_array($query, $params[0]);
            break;
        case 2:
            $mappings = db_get_array($query, $params[0], $params[1]);
            break;
        default:
            $mappings = db_get_array($query);
    }

    // Resolve variant names for display
    $variantIds = array_filter(array_unique(array_column($mappings, 'cscart_variant_id')));
    $variantNames = [];
    if (!empty($variantIds)) {
        $variantNames = db_get_hash_single_array(
            "SELECT variant_id, variant FROM ?:product_feature_variant_descriptions WHERE variant_id IN (?n) AND lang_code = ?s",
            ['variant_id', 'variant'], $variantIds, DESCR_SL
        );
    }

    // Resolve feature names for display
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

    // Group by feature type for display
    $grouped = [];
    foreach ($mappings as $m) {
        $grouped[$m['feature_type']][] = $m;
    }

    // Stats
    $stats = [
        'total' => db_get_field("SELECT COUNT(*) FROM ?:travel_feature_map"),
        'active' => db_get_field("SELECT COUNT(*) FROM ?:travel_feature_map WHERE status = 'A'"),
        'unmapped' => db_get_field("SELECT COUNT(*) FROM ?:travel_feature_map WHERE cscart_variant_id IS NULL OR cscart_variant_id = 0"),
        'aliases' => db_get_field("SELECT COUNT(*) FROM ?:travel_api_alias"),
    ];

    Tygh::$app['view']->assign('mappings', $mappings);
    Tygh::$app['view']->assign('grouped_mappings', $grouped);
    Tygh::$app['view']->assign('mapping_stats', $stats);
    Tygh::$app['view']->assign('feature_types', $validFeatureTypes);
    Tygh::$app['view']->assign('search', [
        'feature_type' => $featureTypeFilter,
        'status' => $statusFilter,
    ]);
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
