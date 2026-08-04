<?php

declare(strict_types=1);

/**
 * Eurosite · getOwnCityRequest — cities with own offers (static data; one
 * list across all countries, CountryCode per row; requires XML credentials).
 *
 * Usage (CLI):      php own_cities.php [--limit=20]
 * Usage (browser):  own_cities.php?limit=20
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "own_cities — Eurosite cities with own offers\n  --limit=N   first N <City> rows only\n";
    exit;
}

$cfg   = euro_config();
$limit = (int) (euro_param('limit', '0') ?? '0');

euro_run($cfg, 'getOwnCityRequest', '  <getOwnCityRequest/>', $limit);
