<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/static/hotels — paginated hotel catalog.
 *
 * Usage (CLI):
 *   php hotels.php
 *   php hotels.php --page=1 --per_page=50 --limit=20
 *   php hotels.php --destination_ids=101,102
 *   php hotels.php --updated_since=2026-07-01T00:00:00Z
 *
 * Usage (browser):
 *   hotels.php?per_page=50&limit=20&destination_ids=101,102
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "static/hotels — paginated hotel catalog\n"
       . "  --page=N   --per_page=N   (API pagination)\n"
       . "  --destination_ids=1,2,3   restrict to destinations\n"
       . "  --updated_since=ISO8601   delta since a datetime\n"
       . "  --limit=N  print only the first N rows\n";
    exit;
}

$cfg   = spx_config();
$query = [
    'page'     => (int) (spx_param('page', '1') ?? '1'),
    'per_page' => (int) (spx_param('per_page', '100') ?? '100'),
];
$destIds = spx_param('destination_ids', '') ?? '';
if ($destIds !== '') {
    $query['destination_ids'] = array_values(array_filter(
        array_map('intval', preg_split('/[,\s]+/', $destIds, -1, PREG_SPLIT_NO_EMPTY) ?: []),
        static fn (int $n): bool => $n > 0,
    ));
}
if (($since = spx_param('updated_since')) !== null && $since !== '') {
    $query['updated_since'] = $since;
}
$limit = (int) (spx_param('limit', '0') ?? '0');

spx_run($cfg, 'GET', '/api/v1/static/hotels', null, $query, $limit);
