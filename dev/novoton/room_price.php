<?php

declare(strict_types=1);

/**
 * Novoton · room_price (fn #3) — real-time accommodation price for one room.
 *
 * Needs a room id + board id from hotel_info, plus a date range and occupancy.
 *
 * Usage (CLI):
 *   php room_price.php --hotel_id=4535 --room_id="DBL 2+0" --board_id=BB \
 *                      --check_in=2026-08-02 --check_out=2026-08-09 --adults=2
 *   php room_price.php --hotel_id=4535 --room_id="DBL 2+1" --board_id=BB \
 *                      --check_in=2026-08-02 --check_out=2026-08-09 \
 *                      --adults=2 --children=5,8
 *
 * Usage (browser):
 *   room_price.php?hotel_id=4535&room_id=DBL+2%2B0&board_id=BB&check_in=2026-08-02&check_out=2026-08-09&adults=2
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "room_price — real-time price for one room\n"
       . "  --hotel_id=  --room_id=  --board_id=  (from hotel_info)\n"
       . "  --check_in=YYYY-MM-DD  --check_out=YYYY-MM-DD\n"
       . "  --adults=N   --children=age,age  (comma-separated child ages)\n"
       . "  --lang=      (default UK)\n";
    exit;
}

$cfg      = nvt_config();
$hotelId  = nvt_param('hotel_id', '') ?? '';
$roomId   = nvt_param('room_id', '') ?? '';
$boardId  = nvt_param('board_id', '') ?? '';
$checkIn  = nvt_param('check_in', '') ?? '';
$checkOut = nvt_param('check_out', '') ?? '';
$adults   = max(1, (int) (nvt_param('adults', '2') ?? '2'));
$lang     = nvt_param('lang');

if ($hotelId === '' || $roomId === '' || $checkIn === '' || $checkOut === '') {
    nvt_out_setup();
    echo "ERROR: --hotel_id, --room_id, --check_in and --check_out are required.\n"
       . "Run with --help for the full argument list.\n";
    exit(1);
}

// Children ages -> <Chd><Age>..</Age></Chd>
$childrenXml = '';
$childrenRaw = nvt_param('children', '') ?? '';
if ($childrenRaw !== '') {
    foreach (preg_split('/[,\s]+/', $childrenRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $age) {
        $childrenXml .= '<Age>' . (int) $age . '</Age>';
    }
}

$xml = nvt_xml_header() . '
<room_price>
    ' . nvt_credentials($cfg) . '
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
    <PackageName></PackageName>
    <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
    <IdBoard>' . htmlspecialchars($boardId) . '</IdBoard>
    <IdExtBoard></IdExtBoard>
    <IdStar></IdStar>
    <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
    <Currency>EUR</Currency>
    <Adt>' . $adults . '</Adt>
    <Chd>' . $childrenXml . '</Chd>
    <Remark>Yes</Remark>
    <Important>Yes</Important>
</room_price>';

nvt_run($cfg, 'room_price', $xml, 0, $lang);
