<?php

declare(strict_types=1);

/**
 * Eurosite · getTagOffersRequest — offer-tag catalog (static data; e.g.
 * Craciun, 1 Mai; may be empty for an account; requires XML credentials).
 *
 * Usage (CLI):      php tag_offers.php [--limit=20]
 * Usage (browser):  tag_offers.php?limit=20
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "tag_offers — Eurosite offer-tag catalog\n  --limit=N   first N <Tag> rows only\n";
    exit;
}

$cfg   = euro_config();
$limit = (int) (euro_param('limit', '0') ?? '0');

euro_run($cfg, 'getTagOffersRequest', '  <getTagOffersRequest/>', $limit);
