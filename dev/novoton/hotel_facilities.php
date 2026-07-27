<?php

declare(strict_types=1);

/**
 * Novoton · hotel_facilities (fn #27) — facilities/features FOR one hotel.
 *
 * Usage (CLI):
 *   php hotel_facilities.php 4535
 *   php hotel_facilities.php --hotel_id=4535 --limit=30
 *
 * Usage (browser):
 *   hotel_facilities.php?hotel_id=4535&limit=30
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotel_facilities — facilities/features for one hotel\n"
       . "  <hotel_id>  (positional) or --hotel_id=\n"
       . "  --limit=N   first N facility rows only\n";
    exit;
}

$cfg     = nvt_config();
$hotelId = nvt_param('hotel_id', nvt_param('0', ''));
$limit   = (int) (nvt_param('limit', '0') ?? '0');

if ($hotelId === null || $hotelId === '') {
    nvt_out_setup();
    echo "ERROR: hotel id required — e.g. `php hotel_facilities.php 4535`\n";
    exit(1);
}

$xml = nvt_xml_header() . '
<hotel_facilities>
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
</hotel_facilities>';

nvt_run($cfg, 'hotel_facilities', $xml, $limit);
