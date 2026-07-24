<?php

declare(strict_types=1);

/**
 * Novoton · hotel_images (fn #6) — picture URLs for a hotel.
 *
 * Usage (CLI):
 *   php hotel_images.php 4535
 *   php hotel_images.php --hotel_id=4535 --limit=5
 *
 * Usage (browser):
 *   hotel_images.php?hotel_id=4535&limit=5
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotel_images — picture URLs for a hotel\n"
       . "  <hotel_id>  (positional) or --hotel_id=\n"
       . "  --lang=     (default UK)   --limit=N  first N images only\n";
    exit;
}

$cfg     = nvt_config();
$hotelId = nvt_param('hotel_id', nvt_param('0', ''));
$lang    = nvt_param('lang');
$limit   = (int) (nvt_param('limit', '0') ?? '0');

if ($hotelId === null || $hotelId === '') {
    nvt_out_setup();
    echo "ERROR: hotel id required — e.g. `php hotel_images.php 4535`\n";
    exit(1);
}

$xml = nvt_xml_header() . '
<hotel_images>
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
</hotel_images>';

nvt_run($cfg, 'hotel_images', $xml, $limit, $lang);
