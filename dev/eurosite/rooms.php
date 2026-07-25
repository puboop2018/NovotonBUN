<?php

declare(strict_types=1);

/**
 * Eurosite · getRoomRequest — room-type catalog (static data).
 *
 * Usage (CLI):      php rooms.php [--limit=30]
 * Usage (browser):  rooms.php?limit=30
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "rooms — Eurosite room-type catalog\n  --limit=N   first N <Room> rows only\n";
    exit;
}

$cfg   = euro_config();
$limit = (int) (euro_param('limit', '0') ?? '0');

euro_run($cfg, 'getRoomRequest', '  <getRoomRequest/>', $limit);
