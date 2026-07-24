<?php

declare(strict_types=1);

/**
 * Novoton · hotelinfo (fn #2) — services / rooms / boards / packages for a hotel.
 *
 * This is the single most useful debug call: it returns the room codes
 * (IdRoom), board codes (IdBoard) and package names you feed into room_price,
 * hotel_quota and hotel_res_RQ.
 *
 * Usage (CLI):
 *   php hotel_info.php 4535                 # positional hotel id
 *   php hotel_info.php --hotel_id=4535 --lang=RO
 *   php hotel_info.php 4535 --limit=10      # trim repeated rows (rooms, boards…)
 *
 * Usage (browser):
 *   hotel_info.php?hotel_id=4535&lang=RO
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotelinfo — rooms/boards/packages for a hotel\n"
       . "  <hotel_id>  (positional) or --hotel_id=\n"
       . "  --lang=     (default from env, else UK)\n"
       . "  --limit=N   trim the most-repeated child element to N rows\n";
    exit;
}

$cfg     = nvt_config();
$hotelId = nvt_param('hotel_id', nvt_param('0', ''));
$lang    = nvt_param('lang');
$limit   = (int) (nvt_param('limit', '0') ?? '0');

if ($hotelId === null || $hotelId === '') {
    nvt_out_setup();
    echo "ERROR: hotel id required — e.g. `php hotel_info.php 4535`\n";
    exit(1);
}

$xml = nvt_xml_header() . '
<hotelinfo>
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
</hotelinfo>';

nvt_run($cfg, 'hotelinfo', $xml, $limit, $lang);
