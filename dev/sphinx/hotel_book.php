<?php

declare(strict_types=1);

/**
 * Sphinx · POST /api/v1/hotels/book — book a verified hotel offer (BOOKING).
 *
 * ⚠  OUTWARD-FACING: this creates a booking record on the provider. DRY-RUN by
 *    default — without --send the script only PRINTS the JSON body it would
 *    post. Add --send to actually book.
 *
 * The offer_id must come from a fresh hotel_search → hotel_verify (offers
 * expire). Guests are passed as "Last First" names, comma-separated; the first
 * adult is treated as the holder.
 *
 * Usage (CLI):
 *   php hotel_book.php --offer_id=<id> \
 *       --guests="POPESCU ION,POPESCU ANA" \
 *       --email=ion@example.com --phone=+40700000000
 *       # ^ dry-run: prints the JSON only
 *   ... same args ... --send      # actually POST /book
 *
 * Usage (browser): same params via query string; add &send=1 to book.
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "hotels/book — book a verified hotel offer (BOOKING)\n"
       . "  --offer_id=   (from hotel_search -> hotel_verify)\n"
       . "  --guests=\"LAST FIRST,LAST FIRST\"   comma-separated\n"
       . "  --email=  --phone=   booking contact\n"
       . "  --price=  --currency=  (optional; echoed into the body)\n"
       . "  --reference_code=      your own reference (optional)\n"
       . "  --send    actually POST (default: dry-run, prints JSON only)\n";
    exit;
}

$cfg     = spx_config();
$offerId = spx_param('offer_id', '') ?? '';
$email   = spx_param('email', '') ?? '';
$phone   = spx_param('phone', '') ?? '';
$price   = spx_param('price', '') ?? '';
$currency = spx_param('currency', 'EUR') ?? 'EUR';
$ref     = spx_param('reference_code', '') ?? '';
$send    = spx_param('send') !== null;

if ($offerId === '') {
    spx_out_setup();
    echo "ERROR: --offer_id is required (from hotel_search -> hotel_verify).\n";
    exit(1);
}

// Guests: "LAST FIRST" -> {last_name, first_name, is_holder}
$guests = [];
$i = 0;
foreach (preg_split('/\s*,\s*/', spx_param('guests', '') ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
    $parts = preg_split('/\s+/', trim($name), 2) ?: [];
    $guests[] = [
        'last_name'  => $parts[0] ?? '',
        'first_name' => $parts[1] ?? '',
        'type'       => 'adult',
        'is_holder'  => $i === 0,
    ];
    $i++;
}

$body = [
    'offer_id' => $offerId,
    'currency' => $currency,
    'guests'   => $guests,
    'contact'  => [
        'email' => $email,
        'phone' => $phone,
    ],
    // travel_core cart-integration flags, for parity with the addon's book call.
    'travel_booking' => true,
    'sphinx_booking' => true,
];
if ($price !== '') {
    $body['price'] = (float) $price;
}
if ($ref !== '') {
    $body['reference_code'] = $ref;
}

spx_out_setup();

if (!$send) {
    spx_section('hotels/book · DRY-RUN — nothing sent');
    echo "This is a preview. Add --send to actually POST it.\n";
    spx_section('REQUEST BODY that WOULD be posted (JSON)');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    spx_section('NEXT');
    echo "  php hotel_book.php ... --send    # actually book\n";
    exit;
}

spx_section('⚠  SENDING A LIVE BOOKING (POST /api/v1/hotels/book)');
spx_run($cfg, 'POST', '/api/v1/hotels/book', $body, []);
