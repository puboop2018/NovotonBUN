<?php

declare(strict_types=1);

/**
 * Sphinx · POST /api/v1/hotels/search  then poll GET /api/v1/hotels/results.
 *
 * Sphinx search is asynchronous: POST /search returns a `cursor` (a JWT that
 * embeds the search_id); you then poll /results with that search_id + cursor
 * until status is "completed". This probe does the POST and, unless
 * --no_poll, one or more result polls for you.
 *
 * Usage (CLI):
 *   php hotel_search.php --destination_id=101 \
 *       --check_in=2026-08-02 --check_out=2026-08-09 --adults=2
 *   php hotel_search.php --destination_id=101 --check_in=2026-08-02 \
 *       --check_out=2026-08-09 --adults=2 --children=5,8 --limit=10
 *   php hotel_search.php ... --polls=5 --poll_delay=2   # poll up to 5×, 2s apart
 *   php hotel_search.php ... --no_poll                  # just show the search token
 *
 * Usage (browser):
 *   hotel_search.php?destination_id=101&check_in=2026-08-02&check_out=2026-08-09&adults=2&limit=10
 */

require __DIR__ . '/_sphinx_client.php';

if (spx_wants_help()) {
    spx_out_setup();
    echo "hotels/search + hotels/results — async hotel availability search\n"
       . "  --destination_id=  --check_in=YYYY-MM-DD  --check_out=YYYY-MM-DD\n"
       . "  --adults=N   --children=age,age   --currency=  (default EUR)\n"
       . "  --polls=N       max result polls (default 3)\n"
       . "  --poll_delay=S  seconds between polls (default 2)\n"
       . "  --no_poll       stop after /search (show the cursor only)\n"
       . "  --limit=N       print only the first N result rows\n";
    exit;
}

$cfg          = spx_config();
$destination  = (int) (spx_param('destination_id', '0') ?? '0');
$checkIn      = spx_param('check_in', '') ?? '';
$checkOut     = spx_param('check_out', '') ?? '';
$adults       = max(1, (int) (spx_param('adults', '2') ?? '2'));
$currency     = spx_param('currency', 'EUR') ?? 'EUR';
$limit        = (int) (spx_param('limit', '0') ?? '0');
$maxPolls     = max(1, (int) (spx_param('polls', '3') ?? '3'));
$pollDelay    = max(0, (int) (spx_param('poll_delay', '2') ?? '2'));
$noPoll       = spx_param('no_poll') !== null;

if ($destination <= 0 || $checkIn === '' || $checkOut === '') {
    spx_out_setup();
    echo "ERROR: --destination_id, --check_in and --check_out are required.\n";
    exit(1);
}

$childrenAges = array_values(array_filter(
    array_map('intval', preg_split('/[,\s]+/', spx_param('children', '') ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: []),
    static fn (int $n): bool => $n >= 0,
));

$body = [
    'destination_id' => $destination,
    'check_in'       => $checkIn,
    'check_out'      => $checkOut,
    'currency'       => $currency,
    'occupancy'      => [
        [
            // The API's SearchRoomOccupancy DTO REQUIRES children_ages (it
            // 500s without it, even for zero children) — same contract the
            // addon's SearchOccupancyResolver has always sent.
            'adults'        => $adults,
            'children_ages' => $childrenAges,
        ],
    ],
];

spx_out_setup();

// ── 1. POST /search ────────────────────────────────────────────────────────
spx_section('STEP 1 · POST /api/v1/hotels/search');
echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$res = spx_post($cfg, '/api/v1/hotels/search', $body);
echo "\nHTTP {$res['code']} · " . number_format($res['ms'], 1) . " ms"
   . ($res['error'] !== '' ? " · cURL: {$res['error']}" : '') . "\n";
echo spx_format_json($res['body'], $limit) . "\n";

$decoded = json_decode($res['body'], true);
$cursor  = is_array($decoded) ? (string) ($decoded['cursor'] ?? '') : '';
$searchId = is_array($decoded) ? (string) ($decoded['search_id'] ?? '') : '';
if ($searchId === '' && $cursor !== '') {
    $searchId = spx_extract_search_id($cursor);
}

if ($searchId === '' && $cursor === '') {
    spx_section('No cursor/search_id in the response — cannot poll.');
    exit;
}

echo "\nsearch_id : " . ($searchId !== '' ? $searchId : '(embedded in cursor)') . "\n";

if ($noPoll) {
    spx_section('--no_poll set — stopping after /search.');
    exit;
}

// ── 2. Poll /results ─────────────────────────────────────────────────────────
for ($i = 1; $i <= $maxPolls; $i++) {
    if ($i > 1 && $pollDelay > 0) {
        sleep($pollDelay);
    }
    $query = [];
    if ($searchId !== '') {
        $query['search_id'] = $searchId;
    }
    if ($cursor !== '') {
        $query['cursor'] = $cursor;
    }

    spx_section("STEP 2 · poll {$i}/{$maxPolls} · GET /api/v1/hotels/results");
    $poll = spx_get($cfg, '/api/v1/hotels/results', $query);
    echo "HTTP {$poll['code']} · " . number_format($poll['ms'], 1) . " ms\n";
    echo spx_format_json($poll['body'], $limit) . "\n";

    $pd = json_decode($poll['body'], true);
    $status = is_array($pd) ? (string) ($pd['status'] ?? '') : '';
    $nextCursor = is_array($pd) ? (string) ($pd['cursor'] ?? '') : '';
    if ($nextCursor !== '') {
        $cursor = $nextCursor;
    }
    if ($status === 'completed' || $status === 'done' || $status === '') {
        echo "\n(status: " . ($status !== '' ? $status : 'no status field') . " — stopping)\n";
        break;
    }
    echo "\n(status: {$status} — polling again" . ($i < $maxPolls ? " in {$pollDelay}s" : '') . ")\n";
}

/**
 * Extract the search_id embedded in a `cursor` JWT (payload segment). Mirrors
 * SphinxApi::extractSearchIdFromCursor.
 */
function spx_extract_search_id(string $cursor): string
{
    $parts = explode('.', $cursor);
    if (count($parts) < 2) {
        return '';
    }
    $payload = strtr($parts[1], '-_', '+/');
    $rem = strlen($payload) % 4;
    if ($rem > 0) {
        $payload .= str_repeat('=', 4 - $rem);
    }
    $json = base64_decode($payload, true);
    if ($json === false) {
        return '';
    }
    $claims = json_decode($json, true);
    return is_array($claims) && isset($claims['search_id']) && is_string($claims['search_id'])
        ? $claims['search_id']
        : '';
}
