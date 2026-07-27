<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/static/destinations — paginated destination catalog.
 *
 * Usage (CLI):
 *   php destinations.php
 *   php destinations.php --page=1 --per_page=50
 *   php destinations.php --limit=20                    # trim printed rows
 *   php destinations.php --updated_since=2026-07-01T00:00:00Z
 *
 * Usage (browser):
 *   destinations.php?per_page=50&limit=20
 *
 * `per_page` is the API page size sent to Sphinx; `limit` only trims what we
 * PRINT (handy when per_page is large but you want a quick peek).
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "static/destinations — paginated destination catalog\n"
       . "  --page=N        API page (default 1)\n"
       . "  --per_page=N    API page size (default 100)\n"
       . "  --updated_since=ISO8601   delta since a datetime\n"
       . "  --limit=N       print only the first N rows\n";
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

spx_run($cfg, 'GET', '/api/v1/static/destinations', null, $query, $limit);
