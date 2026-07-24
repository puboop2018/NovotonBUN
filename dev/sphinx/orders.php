<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/orders — paginated orders (optionally filtered).
 * Pass an id to fetch a single order via /api/v1/orders/{id}.
 *
 * Usage (CLI):
 *   php orders.php                                # first page of orders
 *   php orders.php --page=1 --per_page=25 --limit=10
 *   php orders.php --reference_code=ABC123        # filter
 *   php orders.php --id=ORD-42                    # one order by id
 *
 * Usage (browser):
 *   orders.php?per_page=25&limit=10
 *   orders.php?id=ORD-42
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "orders — paginated orders, or one order by id\n"
       . "  --page=N   --per_page=N   --limit=N (print trim)\n"
       . "  --reference_code=  filter the list\n"
       . "  --id=ORDER_ID      fetch a single order instead\n";
    exit;
}

$cfg = spx_config();

$id = spx_param('id', '') ?? '';
if ($id !== '') {
    spx_run($cfg, 'GET', '/api/v1/orders/' . rawurlencode($id), null, []);
    exit;
}

$query = [
    'page'     => (int) (spx_param('page', '1') ?? '1'),
    'per_page' => (int) (spx_param('per_page', '50') ?? '50'),
];
if (($ref = spx_param('reference_code')) !== null && $ref !== '') {
    $query['reference_code'] = $ref;
}
$limit = (int) (spx_param('limit', '0') ?? '0');

spx_run($cfg, 'GET', '/api/v1/orders', null, $query, $limit);
