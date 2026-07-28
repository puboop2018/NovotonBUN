<?php

/**
 * settings-schema.php — dump everything needed to write a SETTINGS self-heal.
 *
 * WHY: CS-Cart creates addon settings rows from addon.xml only at
 * install/upgrade time. It never re-reads that file on a code deploy or a
 * cache clear, and this repo has no settings self-heal — language keys have
 * one, the schema has one, settings do not. So a setting added in a later
 * release is invisible on every already-installed store: the field simply is
 * not on the addon's settings page and there is no way to set it.
 *
 * Writing that self-heal means touching CS-Cart's own settings API and tables,
 * which are NOT in this repository (the kit is licensed and gitignored), so
 * they cannot be inspected from the development environment. This page prints
 * the three things needed to write it correctly instead of by guesswork:
 *
 *   1. the public API of Tygh\Settings (via Reflection — method signatures)
 *   2. the structure of the settings_* tables
 *   3. what travel_core actually has stored right now, so the gap between
 *      addon.xml and the database is visible
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev, so this file is served by the
 * container's PHP as a real page. It refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/settings-schema.php
 *   CLI (from inside the app container): php /var/www/html/dev/tools/settings-schema.php
 *
 * Read-only: this tool never writes. Copy the output into the chat.
 */

$ss_is_cli = PHP_SAPI === 'cli';

if (!$ss_is_cli) {
    $ss_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($ss_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("settings-schema is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$ss_docroot = dirname(__DIR__, 2);
if (!is_file($ss_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$ss_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

define('AREA', 'A');
define('ACCOUNT_TYPE', 'admin');
require $ss_docroot . '/init.php';

function ss_section(string $title): void
{
    echo "\n" . str_repeat('=', 78) . "\n{$title}\n" . str_repeat('=', 78) . "\n";
}

// ── 1. The Settings API ─────────────────────────────────────────────────────

ss_section('1. Tygh\Settings — public API (what a self-heal may call)');

if (!class_exists('\Tygh\Settings')) {
    echo "Tygh\\Settings not found — CS-Cart version may name it differently.\n";
} else {
    $ss_ref = new ReflectionClass('\Tygh\Settings');
    echo 'file: ' . (string) $ss_ref->getFileName() . "\n\n";

    foreach ($ss_ref->getMethods(ReflectionMethod::IS_PUBLIC) as $ss_method) {
        $ss_params = [];
        foreach ($ss_method->getParameters() as $ss_p) {
            $ss_type = $ss_p->hasType() ? ((string) $ss_p->getType()) . ' ' : '';
            $ss_def = '';
            if ($ss_p->isDefaultValueAvailable()) {
                $ss_raw = $ss_p->getDefaultValue();
                $ss_def = ' = ' . str_replace("\n", '', var_export($ss_raw, true));
            }
            $ss_params[] = $ss_type . '$' . $ss_p->getName() . $ss_def;
        }
        $ss_ret = $ss_method->hasReturnType() ? ': ' . ((string) $ss_method->getReturnType()) : '';
        printf(
            "  %s%sfunction %s(%s)%s\n",
            $ss_method->isStatic() ? 'static ' : '',
            '',
            $ss_method->getName(),
            implode(', ', $ss_params),
            $ss_ret,
        );
    }
}

// ── 2. Table structure ──────────────────────────────────────────────────────

ss_section('2. settings_* table structure');

foreach (['settings_sections', 'settings_objects', 'settings_descriptions', 'settings_variants', 'settings_vendor_values'] as $ss_table) {
    echo "\n-- ?:{$ss_table} --\n";
    $ss_cols = db_get_array('DESCRIBE ?:' . $ss_table);
    if (!is_array($ss_cols) || $ss_cols === []) {
        echo "  (table absent)\n";
        continue;
    }
    foreach ($ss_cols as $ss_col) {
        printf(
            "  %-26s %-24s %-5s %-6s %s\n",
            (string) ($ss_col['Field'] ?? ''),
            (string) ($ss_col['Type'] ?? ''),
            (string) ($ss_col['Null'] ?? ''),
            (string) ($ss_col['Key'] ?? ''),
            (string) ($ss_col['Extra'] ?? ''),
        );
    }
}

// ── 3. What travel_core actually has ────────────────────────────────────────

ss_section('3. travel_core: addon.xml vs the database');

$ss_section_row = db_get_row(
    "SELECT * FROM ?:settings_sections WHERE name = 'travel_core'",
);
echo "settings_sections row for travel_core:\n";
echo '  ' . (is_array($ss_section_row) && $ss_section_row !== [] ? json_encode($ss_section_row) : '(none)') . "\n\n";

$ss_stored = db_get_array(
    "SELECT o.object_id, o.name, o.type, o.value, o.position, o.section_id, o.section_tab_id
     FROM ?:settings_objects o
     JOIN ?:settings_sections s ON s.section_id = o.section_id
     WHERE s.name = 'travel_core'
     ORDER BY o.position, o.object_id",
);
$ss_stored = is_array($ss_stored) ? $ss_stored : [];

echo 'stored settings objects: ' . count($ss_stored) . "\n";
foreach ($ss_stored as $ss_o) {
    printf(
        "  #%-5s %-34s %-20s pos=%-4s value=%s\n",
        (string) ($ss_o['object_id'] ?? ''),
        (string) ($ss_o['name'] ?? ''),
        (string) ($ss_o['type'] ?? ''),
        (string) ($ss_o['position'] ?? ''),
        mb_substr((string) ($ss_o['value'] ?? ''), 0, 40),
    );
}

// Which addon.xml <item id="..."> entries never made it into the DB.
$ss_xml_path = $ss_docroot . '/app/addons/travel_core/addon.xml';
echo "\naddon.xml declared vs stored:\n";
if (!is_file($ss_xml_path)) {
    echo "  addon.xml not readable at {$ss_xml_path}\n";
} else {
    $ss_xml = @simplexml_load_file($ss_xml_path);
    $ss_declared = [];
    if ($ss_xml !== false && isset($ss_xml->settings->sections->section)) {
        foreach ($ss_xml->settings->sections->section as $ss_sec) {
            foreach ($ss_sec->items->item ?? [] as $ss_item) {
                $ss_declared[] = (string) $ss_item['id'];
            }
        }
    }
    $ss_storedNames = array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $ss_stored);
    $ss_missing = array_values(array_diff($ss_declared, $ss_storedNames));

    echo '  declared in addon.xml: ' . count($ss_declared) . "\n";
    echo '  MISSING from the DB  : ' . count($ss_missing) . "\n";
    foreach ($ss_missing as $ss_name) {
        echo "    - {$ss_name}\n";
    }
}

// The geocoding values as the addon actually reads them today.
ss_section('4. Geocoding as travel_core reads it right now');

if (function_exists('fn_travel_core_get_geocoding_settings')) {
    echo json_encode(fn_travel_core_get_geocoding_settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "fn_travel_core_get_geocoding_settings() not loaded — pull the latest code first.\n";
}

echo "\nDone. Copy everything above into the chat.\n";
