<?php

declare(strict_types=1);

/**
 * Novoton · hotel_list (fn #1) — list hotel names, optionally filtered.
 *
 * Usage (CLI):
 *   php hotel_list.php                              # every hotel (all wildcards)
 *   php hotel_list.php --country=BULGARIA          # filter by country
 *   php hotel_list.php --country=BULGARIA --city=SUNNY BEACH
 *   php hotel_list.php --hotel=EDART               # name filter
 *   php hotel_list.php --limit=20                  # print only the first 20 rows
 *
 * Usage (browser):
 *   hotel_list.php?country=BULGARIA&limit=20
 *
 * Filters map to the API's <Country>/<City>/<Hotel>/<HotelType> — '%' is the
 * wildcard, so an omitted filter matches everything.
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotel_list — list hotel names\n"
       . "  --country=  (default %)   --city=  (default %)\n"
       . "  --hotel=    (default %)   --type=  (default %)\n"
       . "  --limit=N   print only the first N <hotel> rows\n";
    exit;
}

$cfg     = nvt_config();
$country = nvt_param('country', '%') ?: '%';
$city    = nvt_param('city', '%') ?: '%';
$hotel   = nvt_param('hotel', '%') ?: '%';
$type    = nvt_param('type', '%') ?: '%';
$limit   = (int) (nvt_param('limit', '0') ?? '0');

$xml = nvt_xml_header() . '
<hotel_list>
    <hotelinfo>
        <Country>' . nvt_cdata($country) . '</Country>
        <City>' . nvt_cdata($city) . '</City>
        <Hotel>' . nvt_cdata($hotel) . '</Hotel>
        <HotelType>' . nvt_cdata($type) . '</HotelType>
    </hotelinfo>
</hotel_list>';

nvt_run($cfg, 'hotel_list', $xml, $limit);
