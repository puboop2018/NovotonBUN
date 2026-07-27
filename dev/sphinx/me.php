<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/me — the authenticated user profile (verifies the token).
 *
 * Usage:  php me.php   |   me.php   (browser)
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "me — authenticated user profile (verifies your Bearer token)\n";
    exit;
}

spx_run(spx_config(), 'GET', '/api/v1/me', null, []);
