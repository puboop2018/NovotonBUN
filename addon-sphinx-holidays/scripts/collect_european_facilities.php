#!/usr/bin/env php
<?php
/**
 * Sphinx Hotels — Collect All European Facility IDs
 *
 * Queries all European hotels from sphinx_hotels, extracts every unique
 * facility ID + name from facilities_json, and saves them to a
 * sphinx_facility_catalog table for reference and gap analysis.
 *
 * Also compares against travel_api_alias to identify unmapped facilities.
 *
 * Usage:
 *   Option A — Run against the database:
 *     php collect_european_facilities.php --host=localhost --db=cscart --user=root --pass=secret
 *
 *   Option B — Print SQL queries to run manually:
 *     php collect_european_facilities.php --sql-only
 *
 *   Options:
 *     --prefix=cscart_     Table prefix (default: cscart_)
 *     --dry-run            Show what would be saved without writing
 */
declare(strict_types=1);

// European country codes (ISO 3166-1 alpha-2)
const EUROPEAN_COUNTRIES = [
    'AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ',
    'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT',
    'XK', 'LV', 'LI', 'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL',
    'MK', 'NO', 'PL', 'PT', 'RO', 'RU', 'SM', 'RS', 'SK', 'SI',
    'ES', 'SE', 'CH', 'TR', 'UA', 'GB', 'VA',
];

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

$host   = $args['host'] ?? 'localhost';
$db     = $args['db'] ?? '';
$user   = $args['user'] ?? '';
$pass   = $args['pass'] ?? '';
$prefix = $args['prefix'] ?? 'cscart_';
$dryRun = isset($args['dry-run']);

if (empty($db) || empty($user)) {
    echo "Usage:\n";
    echo "  php collect_european_facilities.php --host=localhost --db=cscart --user=root --pass=secret\n";
    echo "  php collect_european_facilities.php --sql-only\n\n";
    echo "Options:\n";
    echo "  --prefix=cscart_   Table prefix (default: cscart_)\n";
    echo "  --dry-run          Show results without writing to database\n";
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

echo "=== Sphinx Hotels — European Facility Collection ===\n\n";

// ── Step 1: Create catalog table if it doesn't exist ──
if (!$dryRun) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$prefix}sphinx_facility_catalog (
            facility_id    INT UNSIGNED NOT NULL PRIMARY KEY,
            facility_name  VARCHAR(255) NOT NULL DEFAULT '',
            hotel_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of European hotels with this facility',
            canonical_code VARCHAR(100) DEFAULT NULL COMMENT 'Matched canonical code from travel_feature_map',
            is_mapped      ENUM('Y','N') DEFAULT 'N' COMMENT 'Whether this ID has an alias in travel_api_alias',
            countries      TEXT DEFAULT NULL COMMENT 'Comma-separated country codes where this facility appears',
            first_seen_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='All unique facility IDs found across European Sphinx hotels'
    ");
    echo "Step 1: sphinx_facility_catalog table ready.\n\n";
}

// ── Step 2: Query all European hotels with facilities ──
$countriesIn = "'" . implode("','", EUROPEAN_COUNTRIES) . "'";
$stmt = $pdo->query("
    SELECT hotel_id, country_code, facilities_json
    FROM {$prefix}sphinx_hotels
    WHERE country_code IN ({$countriesIn})
      AND facilities_json IS NOT NULL
      AND facilities_json != '[]'
");

$facilityIndex = [];  // id => ['name' => string, 'hotel_count' => int, 'countries' => []]
$totalHotels = 0;
$hotelsWithFacilities = 0;
$countryCounts = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $totalHotels++;
    $cc = $row['country_code'];
    $countryCounts[$cc] = ($countryCounts[$cc] ?? 0) + 1;

    $facilities = json_decode($row['facilities_json'], true);
    if (!is_array($facilities) || empty($facilities)) {
        continue;
    }
    $hotelsWithFacilities++;

    foreach ($facilities as $facility) {
        $id = (int) ($facility['id'] ?? 0);
        $name = trim((string) ($facility['name'] ?? ''));
        if ($id <= 0) {
            continue;
        }

        if (!isset($facilityIndex[$id])) {
            $facilityIndex[$id] = ['name' => $name, 'hotel_count' => 0, 'countries' => []];
        }
        $facilityIndex[$id]['hotel_count']++;
        if (!in_array($cc, $facilityIndex[$id]['countries'], true)) {
            $facilityIndex[$id]['countries'][] = $cc;
        }
        // Keep the longest/most descriptive name
        if (strlen($name) > strlen($facilityIndex[$id]['name'])) {
            $facilityIndex[$id]['name'] = $name;
        }
    }
}

ksort($facilityIndex, SORT_NUMERIC);

echo "Step 2: Scanned hotels.\n";
echo "  Total European hotels: {$totalHotels}\n";
echo "  Hotels with facilities: {$hotelsWithFacilities}\n";
echo "  Unique facility IDs found: " . count($facilityIndex) . "\n";
echo "  Countries represented: " . count($countryCounts) . "\n\n";

// Show country breakdown
arsort($countryCounts);
echo "  Hotels by country:\n";
foreach ($countryCounts as $cc => $count) {
    echo "    {$cc}: {$count}\n";
}
echo "\n";

// ── Step 3: Check which facilities are already mapped ──
$mappedAliases = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.api_value, m.canonical_code
        FROM {$prefix}travel_api_alias a
        JOIN {$prefix}travel_feature_map m ON m.map_id = a.map_id
        WHERE a.api_source = 'sphinx'
          AND m.feature_type = 'facility'
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mappedAliases[$row['api_value']] = $row['canonical_code'];
    }
} catch (PDOException $e) {
    echo "  [WARNING] Could not query travel_api_alias (travel_core not installed?): " . $e->getMessage() . "\n\n";
}

echo "Step 3: Loaded " . count($mappedAliases) . " existing Sphinx facility aliases.\n\n";

// ── Step 4: Save to catalog table ──
$mapped = 0;
$unmapped = 0;

if (!$dryRun) {
    $insertStmt = $pdo->prepare("
        INSERT INTO {$prefix}sphinx_facility_catalog
            (facility_id, facility_name, hotel_count, canonical_code, is_mapped, countries)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            facility_name = VALUES(facility_name),
            hotel_count = VALUES(hotel_count),
            canonical_code = VALUES(canonical_code),
            is_mapped = VALUES(is_mapped),
            countries = VALUES(countries)
    ");
}

echo "=== COMPLETE FACILITY LIST ===\n\n";
echo sprintf("  %-6s %-45s %-25s %-8s %-8s %s\n",
    'ID', 'Facility Name', 'Canonical Code', 'Mapped', 'Hotels', 'Countries');
echo "  " . str_repeat('-', 120) . "\n";

foreach ($facilityIndex as $id => $info) {
    $idStr = (string) $id;
    $canonicalCode = $mappedAliases[$idStr] ?? null;
    $isMapped = $canonicalCode !== null ? 'Y' : 'N';
    $countriesCsv = implode(',', $info['countries']);

    if ($isMapped) {
        $mapped++;
    } else {
        $unmapped++;
    }

    echo sprintf("  %-6d %-45s %-25s %-8s %-8d %s\n",
        $id,
        mb_substr($info['name'], 0, 44),
        $canonicalCode ?? '—',
        $isMapped,
        $info['hotel_count'],
        $countriesCsv
    );

    if (!$dryRun) {
        $insertStmt->execute([
            $id,
            $info['name'],
            $info['hotel_count'],
            $canonicalCode,
            $isMapped,
            $countriesCsv,
        ]);
    }
}

echo "\n=== SUMMARY ===\n";
echo "  Total unique facility IDs: " . count($facilityIndex) . "\n";
echo "  Already mapped: {$mapped}\n";
echo "  Unmapped (need aliases): {$unmapped}\n";

if (!$dryRun) {
    echo "\n  Results saved to {$prefix}sphinx_facility_catalog table.\n";
    echo "  Query with: SELECT * FROM {$prefix}sphinx_facility_catalog ORDER BY hotel_count DESC;\n";
} else {
    echo "\n  [DRY RUN] No data written. Remove --dry-run to save to database.\n";
}

if ($unmapped > 0) {
    echo "\n=== UNMAPPED FACILITIES (need new aliases) ===\n\n";
    echo sprintf("  %-6s %-45s %-8s\n", 'ID', 'Facility Name', 'Hotels');
    echo "  " . str_repeat('-', 65) . "\n";

    foreach ($facilityIndex as $id => $info) {
        $idStr = (string) $id;
        if (!isset($mappedAliases[$idStr])) {
            echo sprintf("  %-6d %-45s %-8d\n",
                $id,
                mb_substr($info['name'], 0, 44),
                $info['hotel_count']
            );
        }
    }

    echo "\n  These facility IDs should be added to \$facilityAliases in\n";
    echo "  addon-sphinx-holidays/app/addons/sphinx_holidays/func.php\n";
}

echo "\n=== DONE ===\n";

// ──────────────────────────────────────────────────
function printSqlQueries(): void
{
    $countries = "'" . implode("','", EUROPEAN_COUNTRIES) . "'";

    echo "=== SQL Queries for Manual Execution ===\n\n";

    echo "-- 1. Create catalog table:\n\n";
    echo <<<SQL
CREATE TABLE IF NOT EXISTS cscart_sphinx_facility_catalog (
    facility_id    INT UNSIGNED NOT NULL PRIMARY KEY,
    facility_name  VARCHAR(255) NOT NULL DEFAULT '',
    hotel_count    INT UNSIGNED NOT NULL DEFAULT 0,
    canonical_code VARCHAR(100) DEFAULT NULL,
    is_mapped      ENUM('Y','N') DEFAULT 'N',
    countries      TEXT DEFAULT NULL,
    first_seen_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    echo "\n\n-- 2. Extract all unique facility IDs from European hotels:\n\n";
    echo <<<SQL
SELECT
    jt.id   AS facility_id,
    jt.name AS facility_name,
    COUNT(DISTINCT h.hotel_id) AS hotel_count,
    GROUP_CONCAT(DISTINCT h.country_code ORDER BY h.country_code) AS countries
FROM cscart_sphinx_hotels h
CROSS JOIN JSON_TABLE(
    h.facilities_json,
    '\$[*]' COLUMNS (
        id   INT          PATH '\$.id',
        name VARCHAR(255) PATH '\$.name'
    )
) AS jt
WHERE h.country_code IN ({$countries})
  AND h.facilities_json IS NOT NULL
  AND h.facilities_json != '[]'
GROUP BY jt.id, jt.name
ORDER BY hotel_count DESC, jt.id;
SQL;

    echo "\n\n-- 3. Insert into catalog (run after query 2):\n\n";
    echo <<<SQL
INSERT INTO cscart_sphinx_facility_catalog (facility_id, facility_name, hotel_count, countries, is_mapped, canonical_code)
SELECT
    jt.id,
    jt.name,
    COUNT(DISTINCT h.hotel_id),
    GROUP_CONCAT(DISTINCT h.country_code ORDER BY h.country_code),
    CASE WHEN a.alias_id IS NOT NULL THEN 'Y' ELSE 'N' END,
    m.canonical_code
FROM cscart_sphinx_hotels h
CROSS JOIN JSON_TABLE(
    h.facilities_json,
    '\$[*]' COLUMNS (
        id   INT          PATH '\$.id',
        name VARCHAR(255) PATH '\$.name'
    )
) AS jt
LEFT JOIN cscart_travel_api_alias a
    ON a.api_source = 'sphinx'
    AND a.api_value = CAST(jt.id AS CHAR)
    AND a.map_id IN (SELECT map_id FROM cscart_travel_feature_map WHERE feature_type = 'facility')
LEFT JOIN cscart_travel_feature_map m
    ON m.map_id = a.map_id
WHERE h.country_code IN ({$countries})
  AND h.facilities_json IS NOT NULL
  AND h.facilities_json != '[]'
GROUP BY jt.id, jt.name, a.alias_id, m.canonical_code
ON DUPLICATE KEY UPDATE
    facility_name = VALUES(facility_name),
    hotel_count = VALUES(hotel_count),
    countries = VALUES(countries),
    is_mapped = VALUES(is_mapped),
    canonical_code = VALUES(canonical_code);
SQL;

    echo "\n\n-- 4. View unmapped facilities (sorted by popularity):\n\n";
    echo <<<'SQL'
SELECT facility_id, facility_name, hotel_count, countries
FROM cscart_sphinx_facility_catalog
WHERE is_mapped = 'N'
ORDER BY hotel_count DESC;
SQL;

    echo "\n\n-- 5. View all facilities:\n\n";
    echo <<<'SQL'
SELECT * FROM cscart_sphinx_facility_catalog ORDER BY hotel_count DESC;
SQL;

    echo "\n\n-- NOTE: Replace 'cscart_' with your actual table prefix if different.\n";
}
