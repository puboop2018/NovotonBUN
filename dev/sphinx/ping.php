<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/ping — connectivity check (no auth required).
 *
 * Usage:  php ping.php   |   ping.php   (browser)
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "ping — connectivity check (no auth required)\n";
    exit;
}

spx_run(spx_config(), 'GET', '/api/v1/ping', null, []);
