#!/usr/bin/env php
<?php
/**
 * Sphinx Hotels — Facility ID Audit
 *
 * Extracts all unique facility IDs from sphinx_hotels.facilities_json,
 * compares them against the 101 mapped aliases in travel_api_alias,
 * and reports any unmapped facility IDs.
 *
 * Usage:
 *   Option A — Run against the database directly:
 *     php audit_facility_ids.php --host=localhost --db=cscart --user=root --pass=secret
 *
 *   Option B — Print SQL queries to run manually:
 *     php audit_facility_ids.php --sql-only
 */
declare(strict_types=1);

// ── Parse CLI arguments ──
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

// ── Database connection ──
$host = $args['host'] ?? 'localhost';
$db   = $args['db']   ?? '';
$user = $args['user'] ?? '';
$pass = $args['pass'] ?? '';
$prefix = $args['prefix'] ?? 'cscart_';

if (empty($db) || empty($user)) {
    echo "Usage:\n";
    echo "  php audit_facility_ids.php --host=localhost --db=cscart --user=root --pass=secret [--prefix=cscart_]\n";
    echo "  php audit_facility_ids.php --sql-only\n\n";
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

echo "=== Sphinx Hotels — Facility ID Audit ===\n\n";

// ── Step 1: Extract all unique facility IDs from sphinx_hotels ──
echo "Step 1: Extracting unique facility IDs from {$prefix}sphinx_hotels.facilities_json ...\n";

$stmt = $pdo->query("SELECT hotel_id, facilities_json FROM {$prefix}sphinx_hotels WHERE facilities_json IS NOT NULL AND facilities_json != '[]'");

$facilityIndex = [];  // id => ['name' => string, 'hotel_count' => int]
$totalHotels = 0;
$hotelsWithFacilities = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $totalHotels++;
    $facilities = json_decode($row['facilities_json'], true);
    if (!is_array($facilities) || empty($facilities)) {
        continue;
    }
    $hotelsWithFacilities++;

    foreach ($facilities as $facility) {
        $id = (string) ($facility['id'] ?? '');
        $name = $facility['name'] ?? '(no name)';
        if ($id === '') {
            continue;
        }
        if (!isset($facilityIndex[$id])) {
            $facilityIndex[$id] = ['name' => $name, 'hotel_count' => 0];
        }
        $facilityIndex[$id]['hotel_count']++;
    }
}

ksort($facilityIndex, SORT_NUMERIC);

echo "  Total hotels in sphinx_hotels: {$totalHotels}\n";
echo "  Hotels with facilities: {$hotelsWithFacilities}\n";
echo "  Unique facility IDs found: " . count($facilityIndex) . "\n\n";

// ── Step 2: Get mapped aliases from travel_api_alias ──
echo "Step 2: Loading mapped facility aliases from {$prefix}travel_api_alias ...\n";

$stmt = $pdo->prepare("
    SELECT a.api_value, m.canonical_code
    FROM {$prefix}travel_api_alias a
    JOIN {$prefix}travel_feature_map m ON m.map_id = a.map_id
    WHERE a.api_source = 'sphinx'
      AND m.feature_type = 'facility'
");
$stmt->execute();

$mappedAliases = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mappedAliases[$row['api_value']] = $row['canonical_code'];
}

echo "  Mapped aliases in travel_api_alias: " . count($mappedAliases) . "\n\n";

// ── Step 3: Compare and report ──
$mapped = [];
$unmapped = [];

foreach ($facilityIndex as $id => $info) {
    if (isset($mappedAliases[$id])) {
        $mapped[$id] = array_merge($info, ['canonical_code' => $mappedAliases[$id]]);
    } else {
        $unmapped[$id] = $info;
    }
}

// Also find aliases that exist in code but no hotel uses them
$unusedAliases = [];
foreach ($mappedAliases as $id => $code) {
    if (!isset($facilityIndex[$id])) {
        $unusedAliases[$id] = $code;
    }
}

echo "=== RESULTS ===\n\n";

// Mapped facilities
echo "--- MAPPED FACILITIES (" . count($mapped) . ") ---\n";
echo sprintf("  %-6s %-40s %-25s %s\n", 'ID', 'API Name', 'Canonical Code', 'Hotels');
echo "  " . str_repeat('-', 90) . "\n";
foreach ($mapped as $id => $info) {
    echo sprintf("  %-6s %-40s %-25s %d\n", $id, $info['name'], $info['canonical_code'], $info['hotel_count']);
}

echo "\n";

// Unmapped facilities — THE KEY OUTPUT
if (empty($unmapped)) {
    echo "--- UNMAPPED FACILITIES: NONE ---\n";
    echo "  All facility IDs from the API are mapped. No gaps found.\n";
} else {
    echo "--- UNMAPPED FACILITIES (" . count($unmapped) . ") — ACTION REQUIRED ---\n";
    echo sprintf("  %-6s %-40s %s\n", 'ID', 'API Name', 'Hotels');
    echo "  " . str_repeat('-', 60) . "\n";
    foreach ($unmapped as $id => $info) {
        echo sprintf("  %-6s %-40s %d\n", $id, $info['name'], $info['hotel_count']);
    }
    echo "\n  These facility IDs need to be added to \$facilityAliases in func.php\n";
    echo "  and their canonical codes added to travel_feature_map.\n";
}

echo "\n";

// Unused aliases (mapped but no hotel has them)
if (!empty($unusedAliases)) {
    echo "--- UNUSED ALIASES (" . count($unusedAliases) . ") — mapped but no hotel uses them ---\n";
    echo sprintf("  %-6s %s\n", 'ID', 'Canonical Code');
    echo "  " . str_repeat('-', 40) . "\n";
    foreach ($unusedAliases as $id => $code) {
        echo sprintf("  %-6s %s\n", $id, $code);
    }
}

echo "\n=== AUDIT COMPLETE ===\n";

// ──────────────────────────────────────────────────
function printSqlQueries(): void
{
    echo "=== SQL Queries for Manual Execution ===\n\n";

    echo "-- 1. Extract all unique facility IDs + names + hotel count from sphinx_hotels:\n\n";
    echo <<<'SQL'
SELECT
    jt.id   AS facility_id,
    jt.name AS facility_name,
    COUNT(DISTINCT h.hotel_id) AS hotel_count
FROM cscart_sphinx_hotels h
CROSS JOIN JSON_TABLE(
    h.facilities_json,
    '$[*]' COLUMNS (
        id   INT          PATH '$.id',
        name VARCHAR(255) PATH '$.name'
    )
) AS jt
WHERE h.facilities_json IS NOT NULL
  AND h.facilities_json != '[]'
GROUP BY jt.id, jt.name
ORDER BY jt.id;
SQL;

    echo "\n\n-- 2. Find facility IDs that are NOT in travel_api_alias (unmapped):\n\n";
    echo <<<'SQL'
SELECT
    jt.id   AS facility_id,
    jt.name AS facility_name,
    COUNT(DISTINCT h.hotel_id) AS hotel_count
FROM cscart_sphinx_hotels h
CROSS JOIN JSON_TABLE(
    h.facilities_json,
    '$[*]' COLUMNS (
        id   INT          PATH '$.id',
        name VARCHAR(255) PATH '$.name'
    )
) AS jt
LEFT JOIN cscart_travel_api_alias a
    ON a.api_source = 'sphinx'
    AND a.api_value = CAST(jt.id AS CHAR)
    AND a.map_id IN (SELECT map_id FROM cscart_travel_feature_map WHERE feature_type = 'facility')
WHERE h.facilities_json IS NOT NULL
  AND h.facilities_json != '[]'
  AND a.alias_id IS NULL
GROUP BY jt.id, jt.name
ORDER BY hotel_count DESC, jt.id;
SQL;

    echo "\n\n-- 3. Count summary:\n\n";
    echo <<<'SQL'
SELECT
    COUNT(DISTINCT jt.id) AS total_unique_facility_ids,
    COUNT(DISTINCT CASE WHEN a.alias_id IS NOT NULL THEN jt.id END) AS mapped_ids,
    COUNT(DISTINCT CASE WHEN a.alias_id IS NULL THEN jt.id END) AS unmapped_ids
FROM cscart_sphinx_hotels h
CROSS JOIN JSON_TABLE(
    h.facilities_json,
    '$[*]' COLUMNS (
        id INT PATH '$.id'
    )
) AS jt
LEFT JOIN cscart_travel_api_alias a
    ON a.api_source = 'sphinx'
    AND a.api_value = CAST(jt.id AS CHAR)
    AND a.map_id IN (SELECT map_id FROM cscart_travel_feature_map WHERE feature_type = 'facility')
WHERE h.facilities_json IS NOT NULL
  AND h.facilities_json != '[]';
SQL;

    echo "\n\n-- NOTE: Replace 'cscart_' with your actual table prefix if different.\n";
}
