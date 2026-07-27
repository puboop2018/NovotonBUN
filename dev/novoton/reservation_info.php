<?php

declare(strict_types=1);

/**
 * Novoton · resinfo (fn #15) — status of an existing reservation.
 *
 * Look up by our IdNum (the reservation number the API returned at booking) or
 * by the agency confirmation code.
 *
 * Usage (CLI):
 *   php reservation_info.php --id_num=123456
 *   php reservation_info.php --confirm_agency=ABC123 --lang=RO
 *
 * Usage (browser):
 *   reservation_info.php?id_num=123456
 */

require __DIR__ . '/_novoton_client.php';

if (nvt_wants_help()) {
    nvt_out_setup();
    echo "resinfo — status of an existing reservation\n"
       . "  --id_num=          reservation number (from hotel_res_RQ), OR\n"
       . "  --confirm_agency=  agency confirmation code\n"
       . "  --lang=  (default UK)\n";
    exit;
}

$cfg           = nvt_config();
$idNum         = nvt_param('id_num', nvt_param('0', '')) ?? '';
$confirmAgency = nvt_param('confirm_agency', '') ?? '';
$lang          = nvt_param('lang');

if ($idNum === '' && $confirmAgency === '') {
    nvt_out_setup();
    echo "ERROR: pass --id_num=... or --confirm_agency=...\n";
    exit(1);
}

$searchXml = $idNum !== ''
    ? '<IdNum>' . htmlspecialchars($idNum) . '</IdNum>'
    : '<ConfirmAgency>' . htmlspecialchars($confirmAgency) . '</ConfirmAgency>';

$xml = nvt_xml_header() . '
<resinfo>
    ' . nvt_credentials($cfg) . '
    ' . $searchXml . '
</resinfo>';

nvt_run($cfg, 'resinfo', $xml, 0, $lang);
