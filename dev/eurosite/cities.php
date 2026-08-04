<?php

declare(strict_types=1);

/**
 * Eurosite · getCityRequest — cities of a country (static data).
 *
 * Usage (CLI):      php cities.php --country=RO [--limit=30]
 * Usage (browser):  cities.php?country=RO&limit=30
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "cities — Eurosite city list for a country\n"
       . "  --country=XX  2-letter country code (default RO)\n"
       . "  --limit=N     first N <City> rows only\n";
    exit;
}

$cfg     = euro_config();
$country = strtoupper(euro_param('country', 'RO') ?? 'RO');
$limit   = (int) (euro_param('limit', '0') ?? '0');

// CountryCode is an ATTRIBUTE (spec + addon client) — as a child element the
// live server ignores the filter and returns the full ~21,700-city catalog.
$details = '  <getCityRequest CountryCode="' . euro_esc($country) . '" />';

euro_run($cfg, 'getCityRequest', $details, $limit);
