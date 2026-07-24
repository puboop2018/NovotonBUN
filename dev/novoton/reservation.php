<?php

declare(strict_types=1);

/**
 * Novoton · hotel_res_RQ (fn #7) — submit a reservation (BOOKING).
 *
 * ⚠  This is an OUTWARD-FACING action: it creates a reservation record on the
 *    provider. Two safety gates, both mirroring the addon's own behaviour:
 *
 *    1. DRY-RUN by default. Without --send the script only PRINTS the exact XML
 *       it would post — nothing leaves your machine. Add --send to actually
 *       call the API.
 *    2. TEST reservation by default. Even with --send, Remark/Comment are set
 *       to "test reservation, do not proceed" (what the addon does in test
 *       mode) so the provider treats it as a test. Add --real to send a live
 *       booking with your own remark — only do this against real credentials
 *       when you mean it.
 *
 * Usage (CLI):
 *   php reservation.php --hotel_id=4535 --room_id="DBL 2+0" --board_id=BB \
 *       --check_in=2026-08-02 --check_out=2026-08-09 \
 *       --holder="POPESCU ION" --guests="POPESCU ION,POPESCU ANA"
 *       # ^ dry-run: prints the XML only
 *   ... same args ... --send        # sends a TEST reservation
 *   ... same args ... --send --real # sends a LIVE reservation (careful!)
 *
 * Usage (browser): same params via query string; add &send=1 (and &real=1).
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "hotel_res_RQ — submit a reservation (BOOKING)\n"
       . "  --hotel_id=  --room_id=  --board_id=\n"
       . "  --check_in=YYYY-MM-DD  --check_out=YYYY-MM-DD\n"
       . "  --holder=\"LAST FIRST\"     booking holder name\n"
       . "  --guests=\"A B,C D\"        comma-separated guest names\n"
       . "  --package=  --order_num=   optional\n"
       . "  --send    actually POST (default: dry-run, prints XML only)\n"
       . "  --real    send a LIVE booking (default: test reservation remark)\n";
    exit;
}

$cfg      = nvt_config();
$hotelId  = nvt_param('hotel_id', '') ?? '';
$roomId   = nvt_param('room_id', '') ?? '';
$boardId  = nvt_param('board_id', '') ?? '';
$checkIn  = nvt_param('check_in', '') ?? '';
$checkOut = nvt_param('check_out', '') ?? '';
$holder   = nvt_param('holder', '') ?? '';
$package  = nvt_param('package', '') ?? '';
$orderNum = nvt_param('order_num', '') ?? '';
$lang     = nvt_param('lang');
$send     = nvt_param('send') !== null;
$real     = nvt_param('real') !== null;

if ($hotelId === '' || $roomId === '' || $checkIn === '' || $checkOut === '') {
    nvt_out_setup();
    echo "ERROR: --hotel_id, --room_id, --check_in and --check_out are required.\n"
       . "Run with --help for the full argument list.\n";
    exit(1);
}

// Guests -> <guests><guest><Id>N</Id><Name>..</Name></guest>...</guests> plus
// per-room <acc><guest><Id>..</Id></guest></acc> referencing the same ids.
$guestNames = [];
foreach (preg_split('/\s*,\s*/', nvt_param('guests', '') ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $g) {
    $guestNames[] = $g;
}
if ($guestNames === [] && $holder !== '') {
    $guestNames[] = $holder;
}
if ($holder === '' && $guestNames !== []) {
    $holder = $guestNames[0];
}

$guestsXml = '';
$accGuestsXml = '';
$id = 1;
foreach ($guestNames as $name) {
    $guestsXml .= '
        <guest><Id>' . $id . '</Id><Name>' . htmlspecialchars($name) . '</Name></guest>';
    $accGuestsXml .= '
            <guest><Id>' . $id . '</Id></guest>';
    $id++;
}
$guestsBlock = $guestsXml !== '' ? '
    <guests>' . $guestsXml . '
    </guests>' : '';

// Test vs live remark — test is the safe default (addon parity).
$remark = $real ? (nvt_param('remark', '') ?? '') : 'test reservation, do not proceed';
$comment = $real ? (nvt_param('comment', '') ?? '') : 'test reservation, do not proceed';

$xml = nvt_xml_header() . '
<hotel_res_RQ>
    ' . nvt_credentials($cfg) . '
    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
    <CreatedBy>API</CreatedBy>
    <PackageName>' . htmlspecialchars($package) . '</PackageName>
    <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
    <DiscountType></DiscountType>' . $guestsBlock . '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($checkIn) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($checkOut) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($roomId) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($boardId) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar></IdStar>
        <Holder>' . htmlspecialchars($holder) . '</Holder>
        <ISO_National>RO</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment) . '</Comment>' . $accGuestsXml . '
    </hotel_acc>
    <OrderNum>' . htmlspecialchars($orderNum) . '</OrderNum>
</hotel_res_RQ>';

nvt_out_setup();

$mode = $real ? 'LIVE reservation' : 'TEST reservation';

if (!$send) {
    nvt_section("hotel_res_RQ · DRY-RUN ({$mode}) — nothing sent");
    echo "This is a preview. Add --send to actually POST it.\n";
    nvt_section('REQUEST that WOULD be sent (credentials masked)');
    echo nvt_format_xml(nvt_mask($xml)) . "\n";
    nvt_section('NEXT');
    echo "  php reservation.php ... --send        # send as a TEST reservation\n";
    echo "  php reservation.php ... --send --real # send as a LIVE reservation\n";
    exit;
}

if ($real) {
    nvt_section('⚠  SENDING A LIVE RESERVATION');
    echo "Remark/Comment are NOT the test-mode text. This creates a real booking.\n";
}

nvt_run($cfg, 'hotel_res_RQ', $xml, 0, $lang);
