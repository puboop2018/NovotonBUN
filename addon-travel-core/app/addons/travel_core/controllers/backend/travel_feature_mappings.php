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
use Tygh\Addons\TravelCore\TravelConstants;

/** @var \Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface $repo */
$repo = FeatureMapper::getRepository();

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

// Valid feature types — derived from FeatureMapper to stay in sync automatically
$validFeatureTypes = array_merge(FeatureMapper::STRICT_FEATURE_TYPES, FeatureMapper::DYNAMIC_FEATURE_TYPES);

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update mapping
    if ($mode === 'update') {
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
                $repo->updateMapping($mapId, $updateData);
                FeatureMapper::clearCache();
                fn_set_notification('N', __('notice'), __('travel_core.fm_mapping_updated'));
            }
        }

        $redirectParams = !empty($_REQUEST['feature_type_filter']) ? '&feature_type=' . urlencode($_REQUEST['feature_type_filter']) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . $redirectParams];
    }

    // Bulk update (toggle status, delete)
    if ($mode === 'bulk_update') {
        $action = $_REQUEST['dispatch_extra'] ?? '';
        $ids = $_REQUEST['map_ids'] ?? [];

        if (!empty($ids) && is_array($ids)) {
            $ids = array_map('intval', $ids);

            if ($action === 'activate') {
                $repo->bulkUpdateStatus($ids, 'A');
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_activated', ['[count]' => count($ids)]));
            } elseif ($action === 'deactivate') {
                $repo->bulkUpdateStatus($ids, 'D');
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_deactivated', ['[count]' => count($ids)]));
            } elseif ($action === 'delete') {
                $repo->deleteMappings($ids);
                fn_set_notification('N', __('notice'), __('travel_core.fm_mappings_deleted', ['[count]' => count($ids)]));
            }
            FeatureMapper::clearCache();
        }

        $redirectParams = !empty($_REQUEST['feature_type']) ? '&feature_type=' . urlencode($_REQUEST['feature_type']) : '';
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage' . $redirectParams];
    }

    // Re-seed canonical codes
    if ($mode === 'reseed') {
        fn_travel_core_seed_feature_map();
        fn_set_notification('N', __('notice'), __('travel_core.fm_reseeded'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Auto-resolve unmapped variants by name-matching + auto-create
    if ($mode === 'resolve_variants') {

        // Step 1: Auto-populate cscart_feature_id from addon settings for entries that don't have one
        $featureTypes = $repo->getFeatureTypesWithoutFeatureId();
        foreach ($featureTypes as $ft) {
            $fid = FeatureMapper::getFeatureId($ft);
            if ($fid > 0) {
                $repo->bulkSetFeatureId($ft, $fid);
            }
        }

        // Step 2: Query entries that have a feature_id but no variant yet
        // Exclude manually locked mappings (variant_source='manual')
        $unmapped = $repo->getUnresolvedMappings();

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
                    $normalizedTarget = preg_replace('/\s+/', ' ', trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($nameEn, 'UTF-8'))));
                    foreach ($variantNameToId as $vName => $vId) {
                        $normalizedExisting = preg_replace('/\s+/', ' ', trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($vName, 'UTF-8'))));
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
                    $languages = $repo->getActiveLanguageCodes();
                    if (empty($languages)) {
                        $languages = ['en'];
                    }

                    $newVariantId = $repo->createFeatureVariant($featureId);

                    if ($newVariantId > 0) {
                        $nameByLang = [];
                        foreach ($languages as $langCode) {
                            $nameByLang[$langCode] = ($langCode === 'ro') ? $nameRo : $nameEn;
                        }
                        $repo->insertFeatureVariantDescriptions($newVariantId, $nameByLang);
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
    if ($mode === 'add_alias') {
        $mapId = (int) ($_REQUEST['map_id'] ?? 0);
        $apiSource = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_REQUEST['api_source'] ?? '')));
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
    if ($mode === 'delete_alias') {
        $aliasId = (int) ($_REQUEST['alias_id'] ?? 0);
        $mapId = (int) ($_REQUEST['map_id'] ?? 0);
        if ($aliasId > 0) {
            $repo->deleteAlias($aliasId);
            FeatureMapper::clearCache();
            fn_set_notification('N', __('notice'), __('travel_core.fm_alias_deleted'));
        }
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.edit&map_id=' . $mapId];
    }

    // Promote unmapped value to a real mapping
    if ($mode === 'map_unmapped') {
        $unmappedId = (int) ($_REQUEST['unmapped_id'] ?? 0);
        if ($unmappedId > 0) {
            $row = $repo->getUnmappedById($unmappedId);
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
        $provider = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_REQUEST['scan_provider'] ?? '')));
        $batchSize = min(max((int) ($_REQUEST['batch_size'] ?? TravelConstants::BATCH_SIZE_DEFAULT), TravelConstants::BATCH_SIZE_MIN), TravelConstants::BATCH_SIZE_MAX);
        $offset = max(0, (int) ($_REQUEST['scan_offset'] ?? 0));

        if ($provider === '') {
            fn_set_notification('E', __('error'), 'No provider specified.');
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
        }

        // Provider-specific: determine source table and JSON column
        $scanConfig = _travel_fm_get_scan_config($provider);
        if (!$scanConfig) {
            fn_set_notification('E', __('error'), "Provider '" . htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') . "' does not support facility scanning.");
            return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
        }

        // Count total hotels (only on first batch)
        $totalHotels = $repo->countHotelsWithJsonFacilities($scanConfig['table'], $scanConfig['json_col']);

        // Fetch batch of hotels
        $hotels = $repo->findHotelsBatchForScan(
            $scanConfig['table'],
            $scanConfig['id_col'],
            $scanConfig['json_col'],
            $offset,
            $batchSize
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

                // Check if already mapped (facility could be hotel_facility, room_facility, or beach_access)
                $mapping = FeatureMapper::resolveFacility($provider, $facilityId);
                if (!$mapping) {
                    // Track as unmapped — default to hotel_facility
                    FeatureMapper::trackUnmapped($provider, 'hotel_facility', $facilityId, $facilityName);
                    $newUnmapped++;
                }
            }
        }

        // Clear resolve cache to free memory between batches
        FeatureMapper::clearCache();

        $processedSoFar = $offset + count($hotels);
        $isComplete = count($hotels) < $batchSize || $processedSoFar >= $totalHotels;

        if ($isComplete) {
            $condition = db_quote(" AND api_source = ?s AND feature_type IN (?a)", $provider, FeatureMapper::FACILITY_TYPES);
            $unmappedResult = $repo->getPaginatedUnmapped($condition, 0, 0);
            $unmappedTotal = $unmappedResult['total'];
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

/**
 * Helper: provider-specific scan configuration (from TravelProviderRegistry)
 *
 * @return array<string, mixed>|null
 */
function _travel_fm_get_scan_config(string $provider): ?array
{
    return TravelProviderRegistry::getScanConfig($provider);
}

// ── GET: Manage (dashboard or paginated list) ──
if ($mode === 'manage') {
    $featureTypeFilter = $_REQUEST['feature_type'] ?? '';
    $statusFilter = $_REQUEST['status'] ?? '';
    $sourceFilter = $_REQUEST['mapping_source'] ?? '';
    $searchQuery = trim((string) ($_REQUEST['q'] ?? ''));

    // ── Dashboard mode (no feature_type selected) ──
    if (!$featureTypeFilter || !in_array($featureTypeFilter, $validFeatureTypes, true)) {

        // Per-type stats for dashboard cards
        $typeStats = $repo->getTypeStats();

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
        $unmappedCount = $repo->getUnmappedCount();

        // Global stats
        $stats = $repo->getGlobalStats();

        // Human-readable labels for feature types
        $typeLabels = [
            'hotel_facility' => 'Hotel Facilities',
            'room_facility'  => 'Room Facilities',
            'beach_access'   => 'Beach Access',
            'board'          => 'Board / Meals',
            'resort'         => 'Resorts & Cities',
            'stars'          => 'Star Rating',
            'property_type'  => 'Property Type',
            'travel_group'   => 'Travel Group',
            'room_type'      => 'Room Type',
            'region'         => 'Region',
            'city'           => 'City',
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

        // Offset
        $offset = ($page - 1) * $itemsPerPage;

        // Fetch paginated data via repository
        $paginatedResult = $repo->getPaginatedMappings($condition, $offset, $itemsPerPage);
        $mappings = $paginatedResult['items'];
        $totalItems = $paginatedResult['total'];

        if ($offset >= $totalItems && $totalItems > 0) {
            $page = 1;
            $offset = 0;
            $paginatedResult = $repo->getPaginatedMappings($condition, $offset, $itemsPerPage);
            $mappings = $paginatedResult['items'];
        }

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
        $typeStats = $repo->getTypeStatsSingle($featureTypeFilter);

        // Human-readable labels
        $typeLabels = [
            'hotel_facility' => 'Hotel Facilities', 'room_facility' => 'Room Facilities',
            'beach_access' => 'Beach Access', 'board' => 'Board / Meals', 'resort' => 'Resorts & Cities',
            'stars' => 'Star Rating', 'property_type' => 'Property Type', 'travel_group' => 'Travel Group',
            'room_type' => 'Room Type', 'region' => 'Region', 'city' => 'City',
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
        Tygh::$app['view']->assign('type_label', $typeLabels[$featureTypeFilter]);
        Tygh::$app['view']->assign('configured_feature_id', FeatureMapper::getFeatureId($featureTypeFilter));
        Tygh::$app['view']->assign('feature_types', $validFeatureTypes);
    }
}

// ── GET: Unmapped values ──
if ($mode === 'unmapped') {
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

    $offset = ($page - 1) * $itemsPerPage;

    $paginatedUnmapped = $repo->getPaginatedUnmapped($condition, $offset, $itemsPerPage);
    $unmapped = $paginatedUnmapped['items'];
    $totalItems = $paginatedUnmapped['total'];

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
if ($mode === 'scan_progress') {
    $provider = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_REQUEST['scan_provider'] ?? '')));
    $scanOffset = max(0, (int) ($_REQUEST['scan_offset'] ?? 0));
    $scanTotal = max(0, (int) ($_REQUEST['scan_total'] ?? 0));
    $batchSize = min(max((int) ($_REQUEST['batch_size'] ?? TravelConstants::BATCH_SIZE_DEFAULT), TravelConstants::BATCH_SIZE_MIN), TravelConstants::BATCH_SIZE_MAX);

    $percent = $scanTotal > 0 ? round($scanOffset / $scanTotal * 100, 1) : 0;

    Tygh::$app['view']->assign('scan_provider', $provider);
    Tygh::$app['view']->assign('scan_offset', $scanOffset);
    Tygh::$app['view']->assign('scan_total', $scanTotal);
    Tygh::$app['view']->assign('scan_percent', $percent);
    Tygh::$app['view']->assign('batch_size', $batchSize);
}

// ── GET: Edit single mapping ──
if ($mode === 'edit') {
    $mapId = (int) ($_REQUEST['map_id'] ?? 0);

    if ($mapId <= 0) {
        fn_set_notification('E', __('error'), __('travel_core.fm_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    $mapping = $repo->getMappingById($mapId);
    if (empty($mapping)) {
        fn_set_notification('E', __('error'), __('travel_core.fm_mapping_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'travel_feature_mappings.manage'];
    }

    // Load all CS-Cart product features for the feature dropdown
    $allFeatures = $repo->findAllCsCartFeatures(DESCR_SL);

    // Load variants for the currently selected feature
    $featureVariants = [];
    if (!empty($mapping['cscart_feature_id'])) {
        $featureVariants = $repo->findVariantsForFeature((int) $mapping['cscart_feature_id'], DESCR_SL);
    }

    // Load aliases for this mapping
    $aliases = $repo->getAliasesForMapping($mapId);

    Tygh::$app['view']->assign('mapping', $mapping);
    Tygh::$app['view']->assign('all_features', $allFeatures);
    Tygh::$app['view']->assign('feature_variants', $featureVariants);
    Tygh::$app['view']->assign('aliases', $aliases);
}

// ── GET: AJAX — load variants for a feature (used by edit page) ──
if ($mode === 'get_variants') {
    $featureId = (int) ($_REQUEST['feature_id'] ?? 0);
    $variants = [];

    if ($featureId > 0) {
        $variants = $repo->findVariantsForFeature($featureId, DESCR_SL);
    }

    if (defined('AJAX_REQUEST')) {
        Tygh::$app['ajax']->assign('variants', $variants);
    } else {
        header('Content-Type: application/json');
        fn_echo(json_encode($variants));
        exit;
    }
}
