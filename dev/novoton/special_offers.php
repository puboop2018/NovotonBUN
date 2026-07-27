<?php

declare(strict_types=1);

/**
 * Novoton · spo (fn #10) — special offers / early booking & discounts.
 *
 * Usage (CLI):
 *   php special_offers.php --hotel_id=4535
 *   php special_offers.php --hotel_id=4535 --package="EDART +BEACH 2026" --limit=10
 *
 * Usage (browser):
 *   special_offers.php?hotel_id=4535&limit=10
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "spo — special offers / early booking & discounts\n"
       . "  --hotel_id=  (--package= optional)\n"
       . "  --lang=  (default UK)   --limit=N  trim repeated offer rows\n";
    exit;
}

$cfg     = nvt_config();
$hotelId = nvt_param('hotel_id', nvt_param('0', '')) ?? '';
$package = nvt_param('package', '') ?? '';
$lang    = nvt_param('lang');
$limit   = (int) (nvt_param('limit', '0') ?? '0');

if ($hotelId === '') {
    nvt_out_setup();
    echo "ERROR: hotel id required — e.g. `php special_offers.php --hotel_id=4535`\n";
    exit(1);
}

$packageXml = $package !== '' ? '<PackageName>' . htmlspecialchars($package) . '</PackageName>' : '';

$xml = nvt_xml_header() . '
<spo>
    ' . nvt_credentials($cfg) . '
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
    ' . $packageXml . '
</spo>';

nvt_run($cfg, 'spo', $xml, $limit, $lang);
