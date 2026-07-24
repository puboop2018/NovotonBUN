<?php

declare(strict_types=1);

/**
 * Novoton · frmsearch — availability search across hotels.
 *
 * Usage (CLI):
 *   php search.php --country=BULGARIA --check_in=2026-08-02 --check_out=2026-08-09 --adults=2
 *   php search.php --country=BULGARIA --city="SUNNY BEACH" --hotel=EDART \
 *                  --check_in=2026-08-02 --check_out=2026-08-09 --adults=2 --limit=15
 *
 * Usage (browser):
 *   search.php?country=BULGARIA&check_in=2026-08-02&check_out=2026-08-09&adults=2&limit=15
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "frmsearch — availability search across hotels\n"
       . "  --country=  --city=  --hotel=   (uppercased; empty = any)\n"
       . "  --check_in=YYYY-MM-DD  --check_out=YYYY-MM-DD\n"
       . "  --adults=N   --limit=N  first N result rows only\n";
    exit;
}

$cfg      = nvt_config();
$country  = strtoupper(nvt_param('country', '') ?? '');
$city     = strtoupper(nvt_param('city', '') ?? '');
$hotel    = strtoupper(nvt_param('hotel', '') ?? '');
$checkIn  = nvt_param('check_in', '') ?? '';
$checkOut = nvt_param('check_out', '') ?? '';
$adults   = max(1, (int) (nvt_param('adults', '2') ?? '2'));
$limit    = (int) (nvt_param('limit', '0') ?? '0');

if ($checkIn === '' || $checkOut === '') {
    nvt_out_setup();
    echo "ERROR: --check_in and --check_out are required.\n";
    exit(1);
}

// <Adt> carries one <Age> per adult (default adult age 33, as the addon uses).
$adultsXml = str_repeat('<Age>33</Age>', $adults);

$xml = nvt_xml_header() . '
<frmsearch>
    ' . nvt_credentials($cfg) . '
    <Country>' . nvt_cdata($country) . '</Country>
    <City>' . nvt_cdata($city) . '</City>
    <Hotel>' . nvt_cdata($hotel) . '</Hotel>
    <Arr1>' . htmlspecialchars($checkIn) . '</Arr1>
    <Dep1>' . htmlspecialchars($checkOut) . '</Dep1>
    <OfferType>hotel</OfferType>
    <Adt>' . $adultsXml . '</Adt>
    <Currency>EUR</Currency>
</frmsearch>';

nvt_run($cfg, 'frmsearch', $xml, $limit);
