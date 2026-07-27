<?php

declare(strict_types=1);

/**
 * Eurosite · getOwnHotelsRequest — the operator's own hotels (+ rooms) in a city.
 *
 * Usage (CLI):      php own_hotels.php --city=ROMM [--limit=10]
 * Usage (browser):  own_hotels.php?city=ROMM&limit=10
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "own_hotels — Eurosite own hotels of a city\n"
       . "  --city=CODE  city code from cities.php (e.g. ROMM = Mamaia)\n"
       . "  --limit=N    first N repeated rows only\n";
    exit;
}

$cfg   = euro_config();
$city  = euro_param('city', '') ?? '';
$limit = (int) (euro_param('limit', '0') ?? '0');

if ($city === '') {
    euro_out_setup();
    echo "Missing --city= (find codes with cities.php). --help for usage.\n";
    exit(1);
}

$details = '  <getOwnHotelsRequest>'
    . '<CityCode>' . euro_esc($city) . '</CityCode>'
    . '</getOwnHotelsRequest>';

euro_run($cfg, 'getOwnHotelsRequest', $details, $limit);
