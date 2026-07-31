<?php

declare(strict_types=1);

/**
 * Sphinx · hotel availability for a destination NAME, listed per hotel.
 *
 * The sibling `hotel_search.php` is a raw endpoint probe: it needs a numeric
 * `--destination_id` and pretty-prints whatever JSON comes back. This one
 * answers the question that is usually actually being asked — "what can I
 * actually book in <place> on these dates?" — and does three things
 * `hotel_search.php` does not:
 *
 *   1. resolves the destination BY NAME (`GET /static/destinations?search=`),
 *      so you never have to know that Antalya is 168566;
 *   2. drains the cursor to the end instead of stopping after N polls — a
 *      Sphinx search streams offers across many pages and the first page is
 *      not a representative sample;
 *   3. filters on `confirmation` and collapses offers to one row per hotel,
 *      so the output is a bookable-hotel list rather than a JSON wall.
 *
 * `confirmation` is a documented enum on the Hotel Offer resource:
 *   immediate  — the offer can be booked and is confirmed instantly
 *   on_request — bookable, but the supplier has to confirm it
 * The storefront's "only sync hotels with immediate confirmation" setting
 * keeps only the former, so `--confirmation=immediate` (the default) shows
 * the same subset the store would keep.
 *
 * Defaults reproduce the reference query: Antalya, 2026-09-01 → 2026-09-07,
 * two adults and one five-year-old.
 *
 * Usage (CLI):
 *   php hotel_availability.php
 *   php hotel_availability.php --destination="Antalya City"
 *   php hotel_availability.php --destination_id=168566
 *   php hotel_availability.php --check_in=2026-09-01 --check_out=2026-09-07 \
 *       --adults=2 --children=5
 *   php hotel_availability.php --confirmation=any     # immediate + on_request
 *   php hotel_availability.php --offers               # every offer, not 1/hotel
 *   php hotel_availability.php --raw=2                # raw API JSON for 2 offers
 *   php hotel_availability.php --limit=20             # print only 20 hotels
 *
 * Usage (browser — same names, as a query string):
 *   hotel_availability.php?confirmation=immediate&limit=10
 *   hotel_availability.php?confirmation=immediate&limit=10&raw=3
 *   hotel_availability.php?raw_response=1          # verbatim API bytes
 *   hotel_availability.php?quiet=1&limit=20        # just the hotel list
 *
 * Exit code is 1 when the search itself fails, 0 otherwise (including "no
 * immediate offers" — that is a legitimate answer, not an error).
 */

require __DIR__ . '/_sphinx_client.php';

// A full drain is ~15 sequential API calls and takes roughly a minute. In a
// browser that means two distinct failures without these: the request is
// killed at max_execution_time (30s by default), and until then the tab shows
// a blank page because the output sits in a buffer. Stream instead.
if (!spx_is_cli()) {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_implicit_flush(true);
}

/** Push whatever has been echoed so far to the browser. No-op on CLI. */
function avail_flush(): void
{
    if (!spx_is_cli()) {
        @flush();
    }
}

if (spx_wants_help()) {
    spx_out_setup();
    echo "hotel availability by destination NAME, one row per hotel\n"
       . "  --destination=NAME     destination to resolve by name (default Antalya)\n"
       . "  --destination_id=N     skip the name lookup and use this id\n"
       . "  --check_in=YYYY-MM-DD  default 2026-09-01\n"
       . "  --check_out=YYYY-MM-DD default 2026-09-07\n"
       . "  --adults=N             default 2\n"
       . "  --children=age,age     default 5\n"
       . "  --currency=            default EUR\n"
       . "  --confirmation=        immediate (default) | on_request | any\n"
       . "  --offers               list every matching offer, not one per hotel\n"
       . "  --limit=N              print only the first N rows\n"
       . "  --quiet                skip the per-page progress, show the list only\n"
       . "\n"
       . "  seeing the API response:\n"
       . "  --raw=N                pretty-printed JSON of the first N rows above\n"
       . "  --raw_response=N       VERBATIM bytes of the search response + the\n"
       . "                         first N /results pages, exactly off the wire\n"
       . "  --raw_max=N            truncate each verbatim body at N bytes (0 = no cap)\n"
       . "\n"
       . "  draining:\n"
       . "  --polls=N              max result pages to drain (default 40)\n"
       . "  --poll_delay=S         seconds between polls (default 1)\n"
       . "  --max_offers=N         stop once N offers are collected (PARTIAL — see below)\n"
       . "\n"
       . "  A capped drain does NOT give you 'the first N hotels'. Sphinx streams\n"
       . "  suppliers concurrently, so stopping early yields whichever supplier\n"
       . "  answered fastest: a 2-page drain of Antalya reported 135 hotels and\n"
       . "  zero on_request offers, against 835 hotels and 8,845 on_request for\n"
       . "  the full drain, and named a different cheapest hotel. Use --limit to\n"
       . "  shorten the OUTPUT; use --max_offers only when you knowingly want a\n"
       . "  fast, unrepresentative sample.\n";
    exit;
}

const AVAIL_DEFAULT_DESTINATION = 'Antalya';
const AVAIL_DEFAULT_CHECK_IN    = '2026-09-01';
const AVAIL_DEFAULT_CHECK_OUT   = '2026-09-07';
const AVAIL_DEFAULT_CHILDREN    = '5';

$cfg           = spx_config();
$destinationId = (int) (spx_param('destination_id', '0') ?? '0');
$destName      = spx_param('destination', AVAIL_DEFAULT_DESTINATION) ?? AVAIL_DEFAULT_DESTINATION;
$checkIn       = spx_param('check_in', AVAIL_DEFAULT_CHECK_IN) ?? AVAIL_DEFAULT_CHECK_IN;
$checkOut      = spx_param('check_out', AVAIL_DEFAULT_CHECK_OUT) ?? AVAIL_DEFAULT_CHECK_OUT;
$adults        = max(1, (int) (spx_param('adults', '2') ?? '2'));
$currency      = spx_param('currency', 'EUR') ?? 'EUR';
$confirmation  = strtolower(trim(spx_param('confirmation', 'immediate') ?? 'immediate'));
// Flag params are truthiness-tested, not presence-tested: in a browser every
// query key is present, so `?offers=0` under a `!== null` check would silently
// ENABLE the flag — the opposite of what was typed.
$perOffer      = avail_flag('offers');
$quiet         = avail_flag('quiet');
$rawCount      = max(0, (int) (spx_param('raw', '0') ?? '0'));
$rawResponses  = max(0, (int) (spx_param('raw_response', '0') ?? '0'));
$rawMax        = max(0, (int) (spx_param('raw_max', '0') ?? '0'));
$limit         = max(0, (int) (spx_param('limit', '0') ?? '0'));
$maxOffers     = max(0, (int) (spx_param('max_offers', '0') ?? '0'));
$maxPolls      = max(1, (int) (spx_param('polls', '40') ?? '40'));
$pollDelay     = max(0, (int) (spx_param('poll_delay', '1') ?? '1'));

/** A flag is on when present AND not explicitly switched off. */
function avail_flag(string $name): bool
{
    $raw = spx_param($name);

    return $raw !== null && !in_array(strtolower(trim($raw)), ['0', 'no', 'false', 'off'], true);
}

$childrenAges = array_values(array_filter(
    array_map('intval', preg_split('/[,\s]+/', spx_param('children', AVAIL_DEFAULT_CHILDREN) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: []),
    static fn (int $n): bool => $n >= 0,
));

spx_out_setup();

// Opened in a browser with no query string at all: say what the knobs are
// before spending a minute on the default search. Output is text/plain, so
// these are copy-paste URLs rather than links.
if (!spx_is_cli() && $_GET === []) {
    $self = basename(__FILE__);
    echo "Running the DEFAULT search (Antalya, {$checkIn} → {$checkOut}, 2 adults + child aged 5,\n";
    echo "confirmation=immediate). It drains every result page, so expect ~60 seconds.\n\n";
    echo "Add a query string to change it:\n";
    echo "  {$self}?limit=10                      first 10 hotels only\n";
    echo "  {$self}?confirmation=immediate&limit=10\n";
    echo "  {$self}?confirmation=any&limit=10      include on_request\n";
    echo "  {$self}?limit=5&raw=5                  + the offer JSON for those 5\n";
    echo "  {$self}?limit=5&raw_response=1&raw_max=20000\n";
    echo "                                        + the verbatim API response bytes\n";
    echo "  {$self}?quiet=1&limit=20               list only, no progress log\n";
    echo "  {$self}?destination=Antalya%20City&limit=10\n";
    echo "  {$self}?help=1                         every parameter\n";
    avail_flush();
}

// ── 1. Resolve the destination by name ─────────────────────────────────────
// The catalog holds ~203k destinations across 2k pages, so the `search`
// parameter is the only sane way in. It is case-insensitive, matches on
// substring, and orders by relevance.
if ($destinationId <= 0) {
    spx_section("STEP 1 · resolve destination \"{$destName}\"");
    echo "GET /api/v1/static/destinations?search=" . rawurlencode($destName) . "\n\n";

    $lookup = spx_get($cfg, '/api/v1/static/destinations', [
        'search'   => $destName,
        'per_page' => 25,
    ]);
    if ($lookup['code'] !== 200) {
        echo "HTTP {$lookup['code']} — destination lookup failed.\n";
        echo substr($lookup['body'], 0, 800) . "\n";
        exit(1);
    }

    $decodedLookup = json_decode($lookup['body'], true);
    $candidates = is_array($decodedLookup) && isset($decodedLookup['data']) && is_array($decodedLookup['data'])
        ? $decodedLookup['data']
        : [];
    if ($candidates === []) {
        echo "No destination matches \"{$destName}\".\n";
        exit(1);
    }

    foreach ($candidates as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        printf(
            "  %s id=%-8s %-38s %-10s %s\n",
            $i === 0 ? '→' : ' ',
            (string) ($row['id'] ?? '?'),
            (string) ($row['name'] ?? '?'),
            (string) ($row['type'] ?? '?'),
            (string) ($row['country_code'] ?? ''),
        );
    }

    // Relevance order already puts the best match first; an exact
    // case-insensitive name match still wins if one exists further down.
    $chosen = $candidates[0];
    foreach ($candidates as $row) {
        if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $destName) === 0) {
            $chosen = $row;
            break;
        }
    }
    $destinationId = (int) ($chosen['id'] ?? 0);
    $destName = (string) ($chosen['name'] ?? $destName);
    echo "\nusing destination_id={$destinationId} (\"{$destName}\", " . (string) ($chosen['type'] ?? '?') . ")\n";
    echo "note: sibling destinations (Side, Alanya, Belek, …) are separate ids — search them separately.\n";
}

// ── 2. POST /hotels/search ─────────────────────────────────────────────────
$body = [
    'destination_id' => $destinationId,
    'check_in'       => $checkIn,
    'check_out'      => $checkOut,
    'currency'       => $currency,
    'occupancy'      => [
        [
            // children_ages is REQUIRED by the SearchRoomOccupancy DTO even
            // when empty — omitting it 500s the endpoint.
            'adults'        => $adults,
            'children_ages' => $childrenAges,
        ],
    ],
];

spx_section('STEP 2 · POST /api/v1/hotels/search');
echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

$search = spx_post($cfg, '/api/v1/hotels/search', $body);
echo "HTTP {$search['code']} · " . number_format($search['ms'], 1) . " ms"
   . ($search['error'] !== '' ? " · cURL: {$search['error']}" : '') . "\n";
if ($search['code'] !== 200) {
    echo spx_format_json($search['body']) . "\n";
    exit(1);
}

$decoded = json_decode($search['body'], true);
$cursor  = is_array($decoded) ? (string) ($decoded['cursor'] ?? '') : '';
if ($cursor === '') {
    echo "No cursor in the search response — nothing to poll.\n";
    echo spx_format_json($search['body']) . "\n";
    exit(1);
}
echo "cursor acquired\n";

// ── 3. Drain GET /hotels/results until the cursor goes null ────────────────
spx_section('STEP 3 · GET /api/v1/hotels/results (draining the cursor)');

/** @var list<array<string, mixed>> $offers */
$offers = [];
/** @var list<array{label:string, url:string, code:int, body:string}> $verbatim */
$verbatim = [];
$pages  = 0;
$stopReason = '';

if ($rawResponses > 0) {
    $verbatim[] = [
        'label' => 'POST /api/v1/hotels/search',
        'url' => $cfg['url'] . '/api/v1/hotels/search',
        'code' => $search['code'],
        'body' => $search['body'],
    ];
}

for ($poll = 1; $poll <= $maxPolls && $cursor !== ''; $poll++) {
    $pageUrl = '/api/v1/hotels/results?' . http_build_query(['cursor' => $cursor]);
    $page = spx_get($cfg, '/api/v1/hotels/results', ['cursor' => $cursor]);
    if ($page['code'] !== 200) {
        echo "  page {$poll}: HTTP {$page['code']} — stopping\n";
        echo '  ' . substr($page['body'], 0, 400) . "\n";
        $stopReason = "the API returned HTTP {$page['code']} on page {$poll}";
        break;
    }

    // Captured BEFORE decoding — these are the bytes as they came off the
    // wire. curl is not asked for gzip (no CURLOPT_ENCODING in spx_request),
    // so the server sends identity and no transparent decode happens.
    if ($rawResponses > 0 && count($verbatim) <= $rawResponses) {
        $verbatim[] = [
            'label' => "GET /api/v1/hotels/results (page {$poll})",
            'url' => $cfg['url'] . $pageUrl,
            'code' => $page['code'],
            'body' => $page['body'],
        ];
    }

    $pd   = json_decode($page['body'], true);
    $rows = is_array($pd) && isset($pd['data']) && is_array($pd['data']) ? $pd['data'] : [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $offers[] = $row;
        }
    }
    $pages++;

    if (!$quiet) {
        printf(
            "  page %-3d %5d offer(s) · %6.0f ms · running total %d\n",
            $poll,
            count($rows),
            $page['ms'],
            count($offers),
        );
        avail_flush();
    }

    // A null cursor means the search is exhausted. An empty PAGE does not:
    // Sphinx streams suppliers asynchronously and can hand back an empty
    // page while a slower supplier is still answering. Measured on Antalya:
    // pages 1 and 7 were both empty while pages 5 and 9 carried thousands.
    $next = is_array($pd) ? ($pd['cursor'] ?? null) : null;
    $cursor = is_string($next) ? $next : '';

    if ($maxOffers > 0 && count($offers) >= $maxOffers && $cursor !== '') {
        $stopReason = "the --max_offers={$maxOffers} cap was reached";
        break;
    }
    if ($cursor !== '' && $pollDelay > 0) {
        sleep($pollDelay);
    }
}
if ($stopReason === '' && $cursor !== '') {
    $stopReason = "the --polls={$maxPolls} cap was reached";
}
$partial = $stopReason !== '';

// ── 4. Filter + group ──────────────────────────────────────────────────────
$byConfirmation = [];
foreach ($offers as $offer) {
    $value = strtolower((string) ($offer['confirmation'] ?? '(missing)'));
    $byConfirmation[$value] = ($byConfirmation[$value] ?? 0) + 1;
}

$matching = $confirmation === 'any'
    ? $offers
    : array_values(array_filter(
        $offers,
        static fn (array $o): bool => strtolower((string) ($o['confirmation'] ?? '')) === $confirmation,
    ));

spx_section('STEP 4 · results');

// The partial warning belongs HERE, next to the numbers, not only up in the
// progress log — this block is what gets read and quoted. A capped drain is
// not a sample of the whole: measured on Antalya, a 2-page drain reported
// 135 hotels and ZERO on_request offers against 835 / 8,845 for a full
// drain, and named a different cheapest hotel.
if ($partial) {
    echo "*** PARTIAL RESULT — every number below is INCOMPLETE ***\n";
    echo "*** Drain stopped early because {$stopReason}; the cursor was still live.\n";
    echo "*** Sphinx streams suppliers concurrently, so what you have is whichever\n";
    echo "*** supplier answered first — NOT a representative or cheapest-first sample.\n";
    echo "*** Remove the cap (or raise --polls) for real figures.\n\n";
}

echo "destination : {$destName} (id {$destinationId})\n";
echo "dates       : {$checkIn} → {$checkOut}\n";
echo 'occupancy   : ' . $adults . ' adult(s)'
   . ($childrenAges !== [] ? ', children aged ' . implode(', ', $childrenAges) : ', no children') . "\n";
echo 'completeness : ' . ($partial ? "PARTIAL ({$stopReason})" : 'complete — cursor drained to null') . "\n";
echo "pages drained: {$pages}\n";
echo 'offers total : ' . count($offers) . "\n";
echo 'by confirmation:';
if ($byConfirmation === []) {
    echo ' (none)';
}
foreach ($byConfirmation as $value => $count) {
    echo "  {$value}={$count}";
}
echo "\n";
echo "filter      : confirmation=" . ($confirmation === 'any' ? 'any (no filter)' : $confirmation)
   . ' → ' . count($matching) . " offer(s)\n";

if ($matching === []) {
    echo "\nNothing matched. Try --confirmation=any, other dates, or a sibling destination id.\n";
    exit(0);
}

// One row per hotel by default, keeping that hotel's cheapest matching offer.
$rowsOut = [];
if ($perOffer) {
    $rowsOut = $matching;
} else {
    foreach ($matching as $offer) {
        $hotelId = (string) ($offer['hotel_id'] ?? $offer['hotel_name'] ?? '?');
        $price = avail_price($offer);
        if (!isset($rowsOut[$hotelId]) || $price < avail_price($rowsOut[$hotelId])) {
            $keptOffers = ($rowsOut[$hotelId]['_offer_count'] ?? 0) + 1;
            $rowsOut[$hotelId] = $offer;
            $rowsOut[$hotelId]['_offer_count'] = $keptOffers;
        } else {
            $rowsOut[$hotelId]['_offer_count'] = ($rowsOut[$hotelId]['_offer_count'] ?? 1) + 1;
        }
    }
    $rowsOut = array_values($rowsOut);
    usort($rowsOut, static fn (array $a, array $b): int => avail_price($a) <=> avail_price($b));
}

$shown = $limit > 0 ? array_slice($rowsOut, 0, $limit) : $rowsOut;

spx_section(
    ($perOffer
        ? 'OFFERS (confirmation=' . $confirmation . ') — ' . count($rowsOut) . ' total'
        : 'HOTELS (confirmation=' . $confirmation . ') — ' . count($rowsOut) . ' distinct, cheapest offer each')
    . ($partial ? '   [PARTIAL]' : ''),
);

foreach ($shown as $i => $offer) {
    $pricing = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : [];
    $rooms   = is_array($offer['rooms'] ?? null) ? $offer['rooms'] : [];
    $roomNames = [];
    foreach ($rooms as $room) {
        if (is_array($room)) {
            $roomNames[] = trim((string) ($room['name'] ?? $room['code'] ?? ''));
        }
    }
    $cancellation = is_array($offer['cancellation_fees'] ?? null) ? $offer['cancellation_fees'] : [];

    printf("\n%3d. %s\n", $i + 1, (string) ($offer['hotel_name'] ?? '(unnamed)'));
    printf(
        "     %s · hotel_id=%s · %s\n",
        (string) ($offer['destination_name'] ?? '?'),
        (string) ($offer['hotel_id'] ?? '?'),
        (string) ($offer['confirmation'] ?? '?'),
    );
    printf(
        "     price   : %s %s   (marketing %s · discount %s · commission %s)\n",
        number_format(avail_price($offer), 2),
        (string) ($pricing['currency'] ?? $currency),
        avail_num($pricing['marketing_price'] ?? null),
        avail_num($pricing['discount'] ?? null),
        avail_num($pricing['commission'] ?? null),
    );
    printf(
        "     room    : %s   board: %s\n",
        $roomNames !== [] ? implode(' + ', array_filter($roomNames)) : '(none listed)',
        (string) ($offer['meal_type_name'] ?? '?'),
    );
    printf(
        "     cancel  : %s · must_verify=%s · terms_loaded=%s\n",
        !empty($cancellation['is_free']) ? 'FREE cancellation' : 'fees apply',
        !empty($offer['must_verify']) ? 'true' : 'false',
        !empty($cancellation['is_loaded']) ? 'true' : 'false',
    );
    if (!$perOffer && ($offer['_offer_count'] ?? 0) > 1) {
        printf("     offers  : %d matching offer(s) for this hotel\n", (int) $offer['_offer_count']);
    }
    printf("     offer_id: %s\n", (string) ($offer['offer_id'] ?? '?'));
}

if ($limit > 0 && count($rowsOut) > $limit) {
    echo "\n… " . (count($rowsOut) - $limit) . " more hidden by --limit={$limit}\n";
}

// ── 5. Raw API payload, on request ─────────────────────────────────────────
// Dumped from the ROWS PRINTED ABOVE, not from the unsorted match list, so
// `--limit=3 --raw=3` shows the payload of the same three hotels. This is
// REFORMATTED (decoded then re-encoded with pretty-print) — the field VALUES
// are the API's, the whitespace and key order presentation is ours. For the
// literal bytes, use --raw_response.
if ($rawCount > 0) {
    spx_section("OFFER JSON · first {$rawCount} row(s) above (re-indented for reading)");
    foreach (array_slice($shown, 0, $rawCount) as $offer) {
        unset($offer['_offer_count']); // our own grouping tally, not API data
        echo json_encode($offer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// ── 6. The literal response bytes ──────────────────────────────────────────
// Untouched: not decoded, not re-encoded, not pretty-printed. spx_request()
// sets no CURLOPT_ENCODING, so no Accept-Encoding is sent and the server
// replies identity — what curl handed back IS what crossed the wire.
if ($rawResponses > 0) {
    spx_section('VERBATIM RESPONSE BODIES · exactly as returned by the API');
    echo "Not decoded, not re-encoded, not reformatted — these are the response bytes.\n";
    if ($rawMax > 0) {
        echo "Truncated at --raw_max={$rawMax} bytes each. Pass raw_max=0 for the whole body.\n";
    }

    foreach ($verbatim as $i => $captured) {
        $bytes = strlen($captured['body']);
        echo "\n" . str_repeat('-', 72) . "\n";
        echo '[' . ($i + 1) . '/' . count($verbatim) . "] {$captured['label']}\n";
        echo "URL   : {$captured['url']}\n";
        echo "HTTP  : {$captured['code']}\n";
        echo "Bytes : " . number_format($bytes) . "\n";
        echo str_repeat('-', 72) . "\n";

        if ($rawMax > 0 && $bytes > $rawMax) {
            echo substr($captured['body'], 0, $rawMax);
            echo "\n… [truncated: " . number_format($bytes - $rawMax) . " more bytes]\n";
        } else {
            echo $captured['body'] . "\n";
        }
        avail_flush();
    }

    if ($verbatim === []) {
        echo "(nothing captured — the drain ended before any page was fetched)\n";
    }
}

echo "\nNext: php hotel_verify.php <offer_id>   (terms + payment schedule live only on verify)\n";

/** The offer's actual sale price, falling back through the pricing shape. */
function avail_price(array $offer): float
{
    $pricing = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : [];

    foreach (['selling_price', 'marketing_price', 'supplier_price'] as $key) {
        if (isset($pricing[$key]) && is_numeric($pricing[$key])) {
            return (float) $pricing[$key];
        }
    }

    return 0.0;
}

function avail_num(mixed $value): string
{
    return is_numeric($value) ? number_format((float) $value, 2) : '—';
}
