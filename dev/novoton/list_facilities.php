<?php

declare(strict_types=1);

/**
 * Novoton · list_facilities (fn #26) — the full facility/feature catalog.
 *
 * The "feature list": every facility code + name the API knows about. Feed the
 * codes back into the addon's feature-mapping to see how they resolve.
 *
 * Usage (CLI):
 *   php list_facilities.php
 *   php list_facilities.php --lang=RO --limit=40
 *
 * Usage (browser):
 *   list_facilities.php?limit=40
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "list_facilities — the full facility/feature catalog\n"
       . "  --lang=   (default UK)   --limit=N  first N facility rows only\n";
    exit;
}

$cfg   = nvt_config();
$lang  = nvt_param('lang');
$limit = (int) (nvt_param('limit', '0') ?? '0');

$xml = nvt_xml_header() . '
<list_facilities>
</list_facilities>';

nvt_run($cfg, 'list_facilities', $xml, $limit, $lang);
