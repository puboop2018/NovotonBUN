<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/static/circuits — paginated circuit catalog.
 *
 * Usage (CLI):
 *   php circuits.php --per_page=50 --limit=20
 *   php circuits.php --updated_since=2026-07-01T00:00:00Z
 *
 * Usage (browser):
 *   circuits.php?per_page=50&limit=20
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "static/circuits — paginated circuit catalog\n"
       . "  --page=N   --per_page=N   --updated_since=ISO8601\n"
       . "  --limit=N  print only the first N rows\n";
    exit;
}

$cfg   = spx_config();
$query = [
    'page'     => (int) (spx_param('page', '1') ?? '1'),
    'per_page' => (int) (spx_param('per_page', '100') ?? '100'),
];
if (($since = spx_param('updated_since')) !== null && $since !== '') {
    $query['updated_since'] = $since;
}
$limit = (int) (spx_param('limit', '0') ?? '0');

spx_run($cfg, 'GET', '/api/v1/static/circuits', null, $query, $limit);
