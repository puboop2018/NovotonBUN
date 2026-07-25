<?php

declare(strict_types=1);

/**
 * Eurosite · getBookingRequest — status + details of an existing booking.
 *
 * Usage (CLI):      php booking_info.php --ref=int1234 [--source=client|api]
 * Usage (browser):  booking_info.php?ref=int1234&source=client
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "booking_info — look up an existing Eurosite booking\n"
       . "  --ref=REF          booking reference (required)\n"
       . "  --source=client    which reference: client (yours) or api (default client)\n";
    exit;
}

$cfg    = euro_config();
$ref    = euro_param('ref', '') ?? '';
$source = euro_param('source', 'client') ?? 'client';

if ($ref === '') {
    euro_out_setup();
    echo "Missing --ref=. --help for usage.\n";
    exit(1);
}

$details = '  <getBookingRequest>'
    . '<BookingReference Source="' . euro_esc($source) . '">' . euro_esc($ref) . '</BookingReference>'
    . '</getBookingRequest>';

euro_run($cfg, 'getBookingRequest', $details, 0);
