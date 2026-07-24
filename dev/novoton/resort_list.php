<?php

declare(strict_types=1);

/**
 * Novoton · resort_list (fn #16) — destinations / resort names for a country.
 *
 * Usage (CLI):
 *   php resort_list.php                       # all countries
 *   php resort_list.php --country=BULGARIA --limit=30
 *
 * Usage (browser):
 *   resort_list.php?country=BULGARIA&limit=30
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "resort_list — destinations / resort names\n"
       . "  --country=  (default empty = all)   --lang=  (default UK)\n"
       . "  --limit=N   first N resort rows only\n";
    exit;
}

$cfg     = nvt_config();
$country = nvt_param('country', '') ?? '';
$lang    = nvt_param('lang');
$limit   = (int) (nvt_param('limit', '0') ?? '0');

$xml = nvt_xml_header() . '
<resort_list>
    <Country>' . htmlspecialchars($country) . '</Country>
</resort_list>';

nvt_run($cfg, 'resort_list', $xml, $limit, $lang);
