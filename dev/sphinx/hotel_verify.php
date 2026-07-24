<?php

declare(strict_types=1);

/**
 * Sphinx · GET /api/v1/hotels/verify — re-verify a hotel offer before booking.
 *
 * Takes an offer_id from the search results and returns the live offer payload
 * (price, board, cancellation & payment terms).
 *
 * Usage (CLI):
 *   php hotel_verify.php <offer_id>
 *   php hotel_verify.php --offer_id=<offer_id>
 *
 * Usage (browser):
 *   hotel_verify.php?offer_id=<offer_id>
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "hotels/verify — re-verify a hotel offer before booking\n"
       . "  <offer_id>  (positional) or --offer_id=\n";
    exit;
}

$cfg     = spx_config();
$offerId = spx_param('offer_id', spx_param('0', ''));

if ($offerId === null || $offerId === '') {
    spx_out_setup();
    echo "ERROR: offer id required — get one from hotel_search.php results.\n";
    exit(1);
}

spx_run($cfg, 'GET', '/api/v1/hotels/verify', null, ['offer_id' => $offerId]);
