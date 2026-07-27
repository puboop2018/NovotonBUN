<?php

declare(strict_types=1);

/**
 * Novoton · hotel_description (fn #5) — descriptive text for a hotel.
 *
 * Usage (CLI):
 *   php hotel_description.php 4535
 *   php hotel_description.php --hotel_id=4535 --lang=RO --package=1
 *
 * Usage (browser):
 *   hotel_description.php?hotel_id=4535&lang=RO
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotel_description — descriptive text for a hotel\n"
       . "  <hotel_id>  (positional) or --hotel_id=\n"
       . "  --lang=     (default UK)   --package=1  include package descriptions\n";
    exit;
}

$cfg      = nvt_config();
$hotelId  = nvt_param('hotel_id', nvt_param('0', ''));
$lang     = nvt_param('lang');
$package  = nvt_param('package') !== null;

if ($hotelId === null || $hotelId === '') {
    nvt_out_setup();
    echo "ERROR: hotel id required — e.g. `php hotel_description.php 4535`\n";
    exit(1);
}

$packageXml = $package ? '<PackageDescription>Yes</PackageDescription>' : '';

$xml = nvt_xml_header() . '
<hotel_description>
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
    ' . $packageXml . '
</hotel_description>';

nvt_run($cfg, 'hotel_description', $xml, 0, $lang);
