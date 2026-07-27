<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/static/hotels/{id} — one hotel's full static record
 * (name, address, coordinates, facilities, images).
 *
 * Usage (CLI):
 *   php hotel.php 3612
 *   php hotel.php --id=3612
 *
 * Usage (browser):
 *   hotel.php?id=3612
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "static/hotels/{id} — one hotel's full static record\n"
       . "  <id>  (positional) or --id=\n";
    exit;
}

$cfg = spx_config();
$id  = spx_param('id', spx_param('0', ''));

if ($id === null || $id === '') {
    spx_out_setup();
    echo "ERROR: hotel id required — e.g. `php hotel.php 3612`\n";
    exit(1);
}

spx_run($cfg, 'GET', '/api/v1/static/hotels/' . rawurlencode($id), null, []);
