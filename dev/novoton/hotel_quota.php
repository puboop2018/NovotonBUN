<?php

declare(strict_types=1);

/**
 * Novoton · hotel_quota (fn #4) — free allotment (availability) for a room.
 *
 * Usage (CLI):
 *   php hotel_quota.php --hotel_id=4535 --room_id="DBL 2+0" \
 *                       --check_in=2026-08-02 --check_out=2026-08-09
 *
 * Usage (browser):
 *   hotel_quota.php?hotel_id=4535&room_id=DBL+2%2B0&check_in=2026-08-02&check_out=2026-08-09
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotel_quota — free allotment (availability) for a room\n"
       . "  --hotel_id=  --room_id=  (--room_type= optional)\n"
       . "  --check_in=YYYY-MM-DD  --check_out=YYYY-MM-DD\n"
       . "  --limit=N  trim repeated quota rows\n";
    exit;
}

$cfg      = nvt_config();
$hotelId  = nvt_param('hotel_id', '') ?? '';
$roomId   = nvt_param('room_id', '') ?? '';
$roomType = nvt_param('room_type', '') ?? '';
$checkIn  = nvt_param('check_in', '') ?? '';
$checkOut = nvt_param('check_out', '') ?? '';
$limit    = (int) (nvt_param('limit', '0') ?? '0');

if ($hotelId === '' || $roomId === '' || $checkIn === '' || $checkOut === '') {
    nvt_out_setup();
    echo "ERROR: --hotel_id, --room_id, --check_in and --check_out are required.\n";
    exit(1);
}

$roomTypeXml = $roomType !== '' ? '<IdRoomType>' . htmlspecialchars($roomType) . '</IdRoomType>' : '';

$xml = nvt_xml_header() . '
<hotel_quota>
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
    <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
    ' . $roomTypeXml . '
    <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
</hotel_quota>';

nvt_run($cfg, 'hotel_quota', $xml, $limit);
