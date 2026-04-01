<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Feature Mappings Controller
 *
 * Admin controller for managing the hotel_feature_mappings table.
 * Supports listing, editing, activating/deactivating, re-seeding,
 * and deleting feature mappings.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

$container = Container::getInstance();
$repo = $container->featureMappingRepository();

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update mapping
    if ($mode === 'update') {
        $mappingId = (int) ($_REQUEST['mapping_id'] ?? 0);
        if ($mappingId > 0 && !empty($_REQUEST['mapping_data'])) {
            $data = $_REQUEST['mapping_data'];
            $allowed = ['display_name_en', 'display_name_ro', 'is_active', 'position', 'cs_cart_feature_id', 'cs_cart_variant_id'];
            $intFields = ['position', 'cs_cart_feature_id', 'cs_cart_variant_id'];
            $updateData = ['mapping_id' => $mappingId];
            foreach ($allowed as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = in_array($field, $intFields, true) ? (int) $data[$field] : $data[$field];
                }
            }
            // When admin sets variant_id, mark as manually locked
            if (isset($data['cs_cart_variant_id'])) {
                $variantId = (int) $data['cs_cart_variant_id'];
                $updateData['variant_source'] = ($variantId > 0) ? 'manual' : null;
            }
            $repo->save($updateData);
            fn_set_notification('N', __('notice'), __('novoton_holidays.feature_mapping_updated'));
        }

        $redirectParams = !empty($_REQUEST['feature_type_filter']) ? '&feature_type=' . urlencode($_REQUEST['feature_type_filter']) : '';
        $redirectParams .= !empty($_REQUEST['source_filter']) ? '&source=' . urlencode($_REQUEST['source_filter']) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_feature_mappings.manage' . $redirectParams];
    }

    // Bulk update (toggle active, delete)
    if ($mode === 'bulk_update') {
        $action = $_REQUEST['dispatch_extra'] ?? '';
        $ids = $_REQUEST['mapping_ids'] ?? [];

        if (!empty($ids) && is_array($ids)) {
            $ids = array_map('intval', $ids);

            if ($action === 'activate') {
                foreach ($ids as $id) {
                    $repo->save(['mapping_id' => $id, 'is_active' => 'Y']);
                }
                fn_set_notification('N', __('notice'), __('novoton_holidays.feature_mappings_activated', ['[count]' => count($ids)]));
            } elseif ($action === 'deactivate') {
                foreach ($ids as $id) {
                    $repo->save(['mapping_id' => $id, 'is_active' => 'N']);
                }
                fn_set_notification('N', __('notice'), __('novoton_holidays.feature_mappings_deactivated', ['[count]' => count($ids)]));
            } elseif ($action === 'delete') {
                foreach ($ids as $id) {
                    $repo->delete($id);
                }
                fn_set_notification('N', __('notice'), __('novoton_holidays.feature_mappings_deleted', ['[count]' => count($ids)]));
            }
        }

        $redirectParams = !empty($_REQUEST['feature_type']) ? '&feature_type=' . urlencode($_REQUEST['feature_type']) : '';
        $redirectParams .= !empty($_REQUEST['source']) ? '&source=' . urlencode($_REQUEST['source']) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_feature_mappings.manage' . $redirectParams];
    }

    // Re-seed mappings
    if ($mode === 'reseed') {
        $result = fn_novoton_holidays_seed_feature_mappings();
        $seeded = $result['seeded'] ?? 0;
        $skipped = $result['skipped'] ?? 0;
        fn_set_notification('N', __('notice'), __('novoton_holidays.feature_mappings_reseeded', [
            '[seeded]' => $seeded,
            '[skipped]' => $skipped,
        ]));

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_feature_mappings.manage'];
    }

    // Auto-resolve unmapped variants (name-match + create)
    if ($mode === 'resolve_variants') {
        $featureMapper = $container->featureMapper();

        // Get all active mappings where variant is unresolved and not manually locked
        $unmapped = db_get_array(
            "SELECT * FROM ?:hotel_feature_mappings " .
            "WHERE (cs_cart_variant_id IS NULL OR cs_cart_variant_id = 0) " .
            "AND (variant_source IS NULL OR variant_source != 'manual') " .
            "AND cs_cart_feature_id > 0 AND is_active = 'Y'"
        );

        $resolved = 0;
        $created = 0;
        $failed = 0;

        foreach ($unmapped as $mapping) {
            $featureId = (int) $mapping['cs_cart_feature_id'];
            $nameEn = trim($mapping['display_name_en'] ?? '');
            $nameRo = trim($mapping['display_name_ro'] ?? '');
            $mappingId = (int) $mapping['mapping_id'];

            if ($featureId <= 0 || $nameEn === '') {
                $failed++;
                continue;
            }

            // Try name-match against existing CS-Cart variants (EN first)
            $variantId = db_get_field(
                "SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = 'en' AND vd.variant = ?s
                 LIMIT 1",
                $featureId,
                $nameEn
            );

            // Fallback: try RO name
            if (!$variantId && $nameRo !== '' && $nameRo !== $nameEn) {
                $variantId = db_get_field(
                    "SELECT v.variant_id
                     FROM ?:product_feature_variants v
                     JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                     WHERE v.feature_id = ?i AND vd.lang_code = 'ro' AND vd.variant = ?s
                     LIMIT 1",
                    $featureId,
                    $nameRo
                );
            }

            if ($variantId) {
                $repo->updateVariantId($mappingId, (int) $variantId, 'auto');
                $resolved++;
            } else {
                // Create new variant
                $variantId = $featureMapper->createVariantFromMapping($mapping);
                if ($variantId > 0) {
                    $repo->updateVariantId($mappingId, $variantId, 'auto');
                    $created++;
                } else {
                    $failed++;
                }
            }
        }

        // Clear FeatureMapper cache after batch variant resolution
        \Tygh\Addons\TravelCore\Services\FeatureMapper::clearCache();

        fn_set_notification('N', __('notice'), __('novoton_holidays.fm_variants_resolved', [
            '[resolved]' => $resolved,
            '[created]' => $created,
            '[failed]' => $failed,
        ]));

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_feature_mappings.manage'];
    }
}

// ── GET: Manage (list) ──
if ($mode === 'manage') {
    $featureTypeFilter = $_REQUEST['feature_type'] ?? '';
    $sourceFilter = $_REQUEST['source'] ?? '';
    $activeFilter = $_REQUEST['active'] ?? '';

    $conditions = [];
    $params = [];

    if ($featureTypeFilter && in_array($featureTypeFilter, Constants::VALID_FEATURE_TYPES, true)) {
        $conditions[] = "feature_type = ?s";
        $params[] = $featureTypeFilter;
    }

    if ($sourceFilter && in_array($sourceFilter, ['seed', 'auto', 'manual'], true)) {
        $conditions[] = "mapping_source = ?s";
        $params[] = $sourceFilter;
    }

    if ($activeFilter === 'Y' || $activeFilter === 'N') {
        $conditions[] = "is_active = ?s";
        $params[] = $activeFilter;
    }

    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Build query with positional parameters
    $query = "SELECT * FROM ?:hotel_feature_mappings {$where} ORDER BY feature_type, position, provider_code";

    // CS-Cart's db_get_array uses positional ?s params
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
        case 3:
            $mappings = db_get_array($query, $params[0], $params[1], $params[2]);
            break;
        default:
            $mappings = db_get_array($query);
    }

    // Resolve variant names for display
    $variantIds = array_filter(array_unique(array_column($mappings, 'cs_cart_variant_id')));
    $variantNames = [];
    if (!empty($variantIds)) {
        $variantNames = db_get_hash_single_array(
            "SELECT variant_id, variant FROM ?:product_feature_variant_descriptions WHERE variant_id IN (?n) AND lang_code = ?s",
            ['variant_id', 'variant'], $variantIds, DESCR_SL
        );
    }
    foreach ($mappings as &$m) {
        $m['variant_name'] = $variantNames[$m['cs_cart_variant_id']] ?? '';
    }
    unset($m);

    // Group by feature type for display
    $grouped = [];
    foreach ($mappings as $m) {
        $grouped[$m['feature_type']][] = $m;
    }

    // Get stats
    $stats = [
        'total' => db_get_field("SELECT COUNT(*) FROM ?:hotel_feature_mappings"),
        'active' => db_get_field("SELECT COUNT(*) FROM ?:hotel_feature_mappings WHERE is_active = 'Y'"),
        'auto' => db_get_field("SELECT COUNT(*) FROM ?:hotel_feature_mappings WHERE mapping_source = 'auto'"),
        'unmapped' => db_get_field("SELECT COUNT(*) FROM ?:hotel_feature_mappings WHERE cs_cart_variant_id IS NULL OR cs_cart_variant_id = 0"),
    ];

    // Get configured feature IDs from settings
    $featureSettings = [];
    foreach (Constants::FEATURE_TYPE_TO_SETTING as $type => $settingKey) {
        $featureSettings[$type] = (int) Registry::get($settingKey);
    }

    Tygh::$app['view']->assign('mappings', $mappings);
    Tygh::$app['view']->assign('grouped_mappings', $grouped);
    Tygh::$app['view']->assign('mapping_stats', $stats);
    Tygh::$app['view']->assign('feature_settings', $featureSettings);
    Tygh::$app['view']->assign('feature_types', Constants::VALID_FEATURE_TYPES);
    Tygh::$app['view']->assign('search', [
        'feature_type' => $featureTypeFilter,
        'source' => $sourceFilter,
        'active' => $activeFilter,
    ]);
}

// ── GET: Edit single mapping ──
if ($mode === 'edit') {
    $mappingId = (int) ($_REQUEST['mapping_id'] ?? 0);

    if ($mappingId <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.feature_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_feature_mappings.manage'];
    }

    $mapping = db_get_row("SELECT * FROM ?:hotel_feature_mappings WHERE mapping_id = ?i", $mappingId);

    if (empty($mapping)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.feature_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_feature_mappings.manage'];
    }

    // Get the CS-Cart feature info if configured
    $featureInfo = null;
    if ($mapping['cs_cart_feature_id'] > 0) {
        $featureInfo = db_get_row(
            "SELECT f.feature_id, f.feature_type, fd.description
             FROM ?:product_features f
             LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = ?s
             WHERE f.feature_id = ?i",
            DESCR_SL,
            $mapping['cs_cart_feature_id']
        );
    }

    // Get variant info if mapped
    $variantInfo = null;
    if (!empty($mapping['cs_cart_variant_id'])) {
        $variantInfo = db_get_row(
            "SELECT v.variant_id, vd.variant
             FROM ?:product_feature_variants v
             LEFT JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id AND vd.lang_code = ?s
             WHERE v.variant_id = ?i",
            DESCR_SL,
            $mapping['cs_cart_variant_id']
        );
    }

    // Load all existing variants for this feature (for the variant selector dropdown)
    $featureVariants = [];
    if ($mapping['cs_cart_feature_id'] > 0) {
        $featureVariants = db_get_array(
            "SELECT v.variant_id, vd.variant as name
             FROM ?:product_feature_variants v
             LEFT JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id AND vd.lang_code = ?s
             WHERE v.feature_id = ?i
             ORDER BY v.position, vd.variant",
            DESCR_SL,
            $mapping['cs_cart_feature_id']
        );
    }

    Tygh::$app['view']->assign('mapping', $mapping);
    Tygh::$app['view']->assign('feature_info', $featureInfo);
    Tygh::$app['view']->assign('variant_info', $variantInfo);
    Tygh::$app['view']->assign('feature_variants', $featureVariants);
}
