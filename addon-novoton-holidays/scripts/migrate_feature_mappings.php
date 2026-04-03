#!/usr/bin/env php
<?php
/**
 * Migration: hotel_feature_mappings → travel_core (travel_feature_map + travel_api_alias)
 *
 * Migrates Novoton's hotel_feature_mappings rows for feature types that are now
 * handled by travel_core: hotel_facility, room_facility, travel_group, beach_access, resort.
 *
 * Safe to run multiple times (uses INSERT IGNORE).
 *
 * Usage:
 *   Option A — Run against the database directly:
 *     php migrate_feature_mappings.php --host=localhost --db=cscart --user=root --pass=secret
 *
 *   Option B — Print SQL queries to run manually:
 *     php migrate_feature_mappings.php --sql-only
 */
declare(strict_types=1);

$args = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        $parts = explode('=', substr($arg, 2), 2);
        $args[$parts[0]] = $parts[1] ?? true;
    }
}

if (isset($args['sql-only'])) {
    printSqlQueries();
    exit(0);
}

$host   = $args['host'] ?? 'localhost';
$db     = $args['db'] ?? '';
$user   = $args['user'] ?? '';
$pass   = $args['pass'] ?? '';
$prefix = $args['prefix'] ?? 'cscart_';

if (empty($db) || empty($user)) {
    echo "Usage:\n";
    echo "  php migrate_feature_mappings.php --host=localhost --db=cscart --user=root --pass=secret [--prefix=cscart_]\n";
    echo "  php migrate_feature_mappings.php --sql-only\n\n";
    exit(1);
}

try {
    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== hotel_feature_mappings → travel_core Migration ===\n\n";

// Feature types to migrate (these are moving from Novoton-only to travel_core)
$migrateTypes = ['hotel_facility', 'room_facility', 'travel_group', 'beach_access', 'resort'];

// Map Novoton feature_type to travel_core feature_type
$typeMap = [
    'hotel_facility' => 'facility',
    'room_facility'  => 'facility',
    'beach_access'   => 'facility',  // Beach access facilities are merged into 'facility' in travel_core
    'travel_group'   => 'travel_group',
    'resort'         => 'resort',
];

$migrated = 0;
$skipped = 0;
$aliasesCreated = 0;

foreach ($migrateTypes as $novotonType) {
    $coreType = $typeMap[$novotonType];

    $stmt = $pdo->prepare("
        SELECT * FROM {$prefix}hotel_feature_mappings
        WHERE feature_type = ? AND provider = 'novoton'
    ");
    $stmt->execute([$novotonType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Processing {$novotonType} → {$coreType}: " . count($rows) . " rows\n";

    foreach ($rows as $row) {
        $providerCode = $row['provider_code'];
        $nameEn = $row['display_name_en'];
        $nameRo = $row['display_name_ro'] ?: $nameEn;
        $variantId = (int) ($row['cs_cart_variant_id'] ?? 0);
        $featureId = (int) ($row['cs_cart_feature_id'] ?? 0);
        $isActive = ($row['is_active'] ?? 'N') === 'Y' ? 'A' : 'D';
        $mappingSource = $row['mapping_source'] ?? 'seed';
        $variantSource = $row['variant_source'] ?? 'auto';

        // Generate canonical code from provider_code
        $canonicalCode = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($providerCode)));
        if ($canonicalCode === '') {
            echo "  SKIP: empty canonical code for provider_code '{$providerCode}'\n";
            $skipped++;
            continue;
        }

        // Check if this canonical code already exists in travel_feature_map
        $existingStmt = $pdo->prepare("
            SELECT map_id FROM {$prefix}travel_feature_map
            WHERE feature_type = ? AND canonical_code = ?
        ");
        $existingStmt->execute([$coreType, $canonicalCode]);
        $existingMapId = $existingStmt->fetchColumn();

        if ($existingMapId) {
            // Already exists — just ensure alias exists
            $mapId = (int) $existingMapId;

            // Update variant_id if the existing row doesn't have one but Novoton does
            if ($variantId > 0) {
                $pdo->prepare("
                    UPDATE {$prefix}travel_feature_map
                    SET cscart_variant_id = COALESCE(cscart_variant_id, ?),
                        cscart_feature_id = COALESCE(cscart_feature_id, ?)
                    WHERE map_id = ? AND (cscart_variant_id IS NULL OR cscart_variant_id = 0)
                ")->execute([$variantId, $featureId, $mapId]);
            }
        } else {
            // Create new travel_feature_map row
            $insertStmt = $pdo->prepare("
                INSERT IGNORE INTO {$prefix}travel_feature_map
                (feature_type, canonical_code, display_name_en, display_name_ro,
                 cscart_feature_id, cscart_variant_id, variant_source, mapping_source, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $coreType, $canonicalCode, $nameEn, $nameRo,
                $featureId ?: null, $variantId ?: null, $variantSource, $mappingSource, $isActive
            ]);
            $mapId = (int) $pdo->lastInsertId();

            if ($mapId <= 0) {
                $skipped++;
                continue;
            }
            $migrated++;
        }

        // Create alias: novoton provider_code → canonical code
        $aliasStmt = $pdo->prepare("
            INSERT IGNORE INTO {$prefix}travel_api_alias
            (map_id, api_source, api_value, match_type)
            VALUES (?, 'novoton', ?, 'exact')
        ");
        $aliasStmt->execute([$mapId, $providerCode]);
        if ($aliasStmt->rowCount() > 0) {
            $aliasesCreated++;
        }
    }
}

echo "\n=== Migration Complete ===\n";
echo "  New travel_feature_map rows: {$migrated}\n";
echo "  Skipped (already exist or empty): {$skipped}\n";
echo "  New travel_api_alias rows: {$aliasesCreated}\n";

function printSqlQueries(): void
{
    echo "=== SQL Queries for Manual Migration ===\n\n";

    echo "-- 1. Migrate hotel_facility + room_facility + beach_access → facility:\n\n";
    echo <<<'SQL'
INSERT IGNORE INTO cscart_travel_feature_map
    (feature_type, canonical_code, display_name_en, display_name_ro,
     cscart_feature_id, cscart_variant_id, mapping_source, status)
SELECT
    'facility' AS feature_type,
    LOWER(REPLACE(TRIM(provider_code), ' ', '_')) AS canonical_code,
    display_name_en,
    COALESCE(NULLIF(display_name_ro, ''), display_name_en) AS display_name_ro,
    cs_cart_feature_id,
    cs_cart_variant_id,
    mapping_source,
    CASE WHEN is_active = 'Y' THEN 'A' ELSE 'D' END AS status
FROM cscart_hotel_feature_mappings
WHERE feature_type IN ('hotel_facility', 'room_facility', 'beach_access')
  AND provider = 'novoton';
SQL;

    echo "\n\n-- 2. Migrate travel_group:\n\n";
    echo <<<'SQL'
INSERT IGNORE INTO cscart_travel_feature_map
    (feature_type, canonical_code, display_name_en, display_name_ro,
     cscart_feature_id, cscart_variant_id, mapping_source, status)
SELECT
    'travel_group' AS feature_type,
    LOWER(REPLACE(TRIM(provider_code), ' ', '_')) AS canonical_code,
    display_name_en,
    COALESCE(NULLIF(display_name_ro, ''), display_name_en) AS display_name_ro,
    cs_cart_feature_id,
    cs_cart_variant_id,
    mapping_source,
    CASE WHEN is_active = 'Y' THEN 'A' ELSE 'D' END AS status
FROM cscart_hotel_feature_mappings
WHERE feature_type = 'travel_group'
  AND provider = 'novoton';
SQL;

    echo "\n\n-- 3. Migrate resort:\n\n";
    echo <<<'SQL'
INSERT IGNORE INTO cscart_travel_feature_map
    (feature_type, canonical_code, display_name_en, display_name_ro,
     cscart_feature_id, cscart_variant_id, mapping_source, status)
SELECT
    'resort' AS feature_type,
    LOWER(REPLACE(TRIM(provider_code), ' ', '_')) AS canonical_code,
    display_name_en,
    COALESCE(NULLIF(display_name_ro, ''), display_name_en) AS display_name_ro,
    cs_cart_feature_id,
    cs_cart_variant_id,
    mapping_source,
    CASE WHEN is_active = 'Y' THEN 'A' ELSE 'D' END AS status
FROM cscart_hotel_feature_mappings
WHERE feature_type = 'resort'
  AND provider = 'novoton';
SQL;

    echo "\n\n-- 4. Create aliases for all migrated rows:\n\n";
    echo <<<'SQL'
INSERT IGNORE INTO cscart_travel_api_alias (map_id, api_source, api_value, match_type)
SELECT m.map_id, 'novoton', hfm.provider_code, 'exact'
FROM cscart_hotel_feature_mappings hfm
JOIN cscart_travel_feature_map m
  ON m.canonical_code = LOWER(REPLACE(TRIM(hfm.provider_code), ' ', '_'))
  AND m.feature_type = CASE
    WHEN hfm.feature_type IN ('hotel_facility', 'room_facility', 'beach_access') THEN 'facility'
    ELSE hfm.feature_type
  END
WHERE hfm.provider = 'novoton'
  AND hfm.feature_type IN ('hotel_facility', 'room_facility', 'travel_group', 'beach_access', 'resort');
SQL;

    echo "\n\n-- NOTE: Replace 'cscart_' with your actual table prefix if different.\n";
}
