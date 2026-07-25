<?php

declare(strict_types=1);

/**
 * Eurosite · getCountryRequest — country list (static data; per the operator
 * this answers without a real account from a whitelisted IP).
 *
 * Usage (CLI):      php countries.php [--limit=20]
 * Usage (browser):  countries.php?limit=20
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "countries — Eurosite country list\n  --limit=N   first N <Country> rows only\n";
    exit;
}

$cfg   = euro_config();
$limit = (int) (euro_param('limit', '0') ?? '0');

euro_run($cfg, 'getCountryRequest', '  <getCountryRequest/>', $limit);
