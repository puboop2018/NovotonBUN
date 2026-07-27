<?php

declare(strict_types=1);

/**
 * Novoton · priceinfo (fn #13) — season prices for a hotel package.
 *
 * Usage (CLI):
 *   php price_info.php --hotel_id=4535 --package="EDART +BEACH 2026"
 *   php price_info.php --hotel_id=4535 --package="EDART +BEACH 2026" --limit=10
 *
 * Usage (browser):
 *   price_info.php?hotel_id=4535&package=EDART+%2BBEACH+2026
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "priceinfo — season prices for a hotel package\n"
       . "  --hotel_id=  --package=  (package name from hotel_info)\n"
       . "  --lang=  (default UK)   --limit=N  trim repeated season rows\n";
    exit;
}

$cfg      = nvt_config();
$hotelId  = nvt_param('hotel_id', '') ?? '';
$package  = nvt_param('package', '') ?? '';
$lang     = nvt_param('lang');
$limit    = (int) (nvt_param('limit', '0') ?? '0');

if ($hotelId === '') {
    nvt_out_setup();
    echo "ERROR: --hotel_id is required (and usually --package).\n";
    exit(1);
}

$xml = nvt_xml_header() . '
<priceinfo>
    ' . nvt_credentials($cfg) . '
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
    <PackageName>' . htmlspecialchars($package) . '</PackageName>
</priceinfo>';

nvt_run($cfg, 'priceinfo', $xml, $limit, $lang);
