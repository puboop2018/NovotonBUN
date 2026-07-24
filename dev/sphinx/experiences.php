<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/static/experiences — paginated experience catalog.
 *
 * Usage (CLI):
 *   php experiences.php --per_page=50 --limit=20
 *
 * Usage (browser):
 *   experiences.php?per_page=50&limit=20
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "static/experiences — paginated experience catalog\n"
       . "  --page=N   --per_page=N   --limit=N (print trim)\n";
    exit;
}

$cfg   = spx_config();
$query = [
    'page'     => (int) (spx_param('page', '1') ?? '1'),
    'per_page' => (int) (spx_param('per_page', '100') ?? '100'),
];
$limit = (int) (spx_param('limit', '0') ?? '0');

spx_run($cfg, 'GET', '/api/v1/static/experiences', null, $query, $limit);
