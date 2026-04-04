#!/usr/bin/env php
<?php
/**
 * Sphinx Hotels — Collect European Facility IDs into travel_unmapped_values
 *
 * Scans all European hotels in sphinx_hotels.facilities_json, resolves each
 * facility ID against travel_api_alias, and populates travel_unmapped_values
 * for any unmatched IDs. This is the CLI equivalent of the admin "Scan Facilities"
 * button — useful for initial backfill or cron-based discovery.
 *
 * Usage:
 *   php collect_european_facilities.php --host=localhost --db=cscart --user=root --pass=secret
 *   php collect_european_facilities.php --sql-only
 *
 * Options:
 *   --prefix=cscart_     Table prefix (default: cscart_)
 *   --batch-size=500     Hotels per batch (default: 500)
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

$host      = $args['host'] ?? 'localhost';
$db        = $args['db'] ?? '';
$user      = $args['user'] ?? '';
$pass      = $args['pass'] ?? '';
$prefix    = $args['prefix'] ?? 'cscart_';
$batchSize = max(50, (int) ($args['batch-size'] ?? 500));

if (empty($db) || empty($user)) {
    echo "Usage:\n";
    echo "  php collect_european_facilities.php --host=localhost --db=cscart --user=root --pass=secret\n";
    echo "  php collect_european_facilities.php --sql-only\n\n";
    exit(1);
}

try {
    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "[ERROR] DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Sphinx — European Facility Collection ===\n\n";

// Load existing mapped aliases
$mappedAliases = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.api_value FROM {$prefix}travel_api_alias a
        JOIN {$prefix}travel_feature_map m ON m.map_id = a.map_id
        WHERE a.api_source = 'sphinx' AND m.feature_type = 'facility'
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mappedAliases[$row['api_value']] = true;
    }
    echo "Loaded " . count($mappedAliases) . " existing Sphinx facility aliases.\n";
} catch (PDOException $e) {
    echo "[WARNING] Could not load aliases: " . $e->getMessage() . "\n";
}

// Count total European hotels
$countriesIn = "'" . implode("','", EUROPEAN_COUNTRIES) . "'";
$totalHotels = (int) $pdo->query("
    SELECT COUNT(*) FROM {$prefix}sphinx_hotels
    WHERE country_code IN ({$countriesIn})
      AND facilities_json IS NOT NULL AND facilities_json != '[]'
")->fetchColumn();

echo "Total European hotels with facilities: {$totalHotels}\n\n";

// Process in batches
$offset = 0;
$facilityIndex = []; // id => ['name' => string, 'hotel_count' => int]

while ($offset < $totalHotels) {
    $stmt = $pdo->query("
        SELECT facilities_json FROM {$prefix}sphinx_hotels
        WHERE country_code IN ({$countriesIn})
          AND facilities_json IS NOT NULL AND facilities_json != '[]'
        ORDER BY hotel_id LIMIT {$batchSize} OFFSET {$offset}
    ");

    $batchCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batchCount++;
        $facilities = json_decode($row['facilities_json'], true);
        if (!is_array($facilities)) {
            continue;
        }
        foreach ($facilities as $f) {
            $id = (string) ($f['id'] ?? '');
            $name = (string) ($f['name'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($facilityIndex[$id])) {
                $facilityIndex[$id] = ['name' => $name, 'hotel_count' => 0];
            }
            $facilityIndex[$id]['hotel_count']++;
            if (strlen($name) > strlen($facilityIndex[$id]['name'])) {
                $facilityIndex[$id]['name'] = $name;
            }
        }
    }

    $offset += $batchCount;
    $pct = round($offset / max(1, $totalHotels) * 100, 1);
    echo "  Processed {$offset}/{$totalHotels} ({$pct}%)\n";

    if ($batchCount < $batchSize) {
        break;
    }
}

// Insert unmapped into travel_unmapped_values
$insertStmt = $pdo->prepare("
    INSERT INTO {$prefix}travel_unmapped_values (api_source, feature_type, api_value, api_label, hotel_count)
    VALUES ('sphinx', 'facility', ?, ?, ?) AS new_row
    ON DUPLICATE KEY UPDATE
        hotel_count = new_row.hotel_count,
        api_label = IF(new_row.api_label != '', new_row.api_label, api_label)
");

$newUnmapped = 0;
$alreadyMapped = 0;

ksort($facilityIndex, SORT_NUMERIC);

foreach ($facilityIndex as $id => $info) {
    if (isset($mappedAliases[$id])) {
        $alreadyMapped++;
        continue;
    }
    $insertStmt->execute([$id, $info['name'], $info['hotel_count']]);
    $newUnmapped++;
}

echo "\n=== RESULTS ===\n";
echo "  Unique facility IDs found: " . count($facilityIndex) . "\n";
echo "  Already mapped (have alias): {$alreadyMapped}\n";
echo "  Unmapped (saved to travel_unmapped_values): {$newUnmapped}\n";

if ($newUnmapped > 0) {
    echo "\n  View in admin: Travel > Feature Mappings > View Unmapped\n";
    echo "  Or query: SELECT * FROM {$prefix}travel_unmapped_values WHERE api_source = 'sphinx' AND feature_type = 'facility' ORDER BY hotel_count DESC;\n";
}

echo "\n=== DONE ===\n";

function printSqlQueries(): void
{
    $countries = "'" . implode("','", EUROPEAN_COUNTRIES) . "'";

    echo "=== SQL Queries ===\n\n";
    echo "-- Insert unmapped Sphinx facility IDs into travel_unmapped_values:\n\n";
    echo <<<SQL
INSERT INTO cscart_travel_unmapped_values (api_source, feature_type, api_value, api_label, hotel_count)
SELECT
    'sphinx' AS api_source,
    'facility' AS feature_type,
    CAST(jt.id AS CHAR) AS api_value,
    jt.name AS api_label,
    COUNT(DISTINCT h.hotel_id) AS hotel_count
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
WHERE h.country_code IN ({$countries})
  AND h.facilities_json IS NOT NULL
  AND h.facilities_json != '[]'
  AND a.alias_id IS NULL
GROUP BY jt.id, jt.name
AS new_row(api_source, feature_type, api_value, api_label, hotel_count)
ON DUPLICATE KEY UPDATE
    hotel_count = new_row.hotel_count,
    api_label = IF(new_row.api_label != '', new_row.api_label, cscart_travel_unmapped_values.api_label);
SQL;

    echo "\n\n-- View results:\n";
    echo "SELECT * FROM cscart_travel_unmapped_values WHERE api_source = 'sphinx' AND feature_type = 'facility' ORDER BY hotel_count DESC;\n";
}
