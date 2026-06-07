<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: diagnose hotel search for a specific hotel.
 *
 * Makes a real search API call and reports the full request/response so
 * you can see exactly why searches fail or return no results. Safe to
 * run at any time — it does NOT create bookings or modify any data.
 *
 * Usage — by ID:
 *   cron_mode=diagnose_search&hotel_id=2249
 *   cron_mode=diagnose_search&hotel_id=2249&check_in=2026-07-05&check_out=2026-07-12
 *   cron_mode=diagnose_search&hotel_id=2249&adults=2&rooms=1
 *
 * Usage — by name (partial, case-insensitive):
 *   cron_mode=diagnose_search&hotel_name=kazbek
 *   cron_mode=diagnose_search&hotel_name=kazbek+dubrovnik&check_in=2026-07-05&check_out=2026-07-12
 */
class DiagnoseSearchCommand extends AbstractSyncCommand
{
    /** How long to poll for results before giving up (seconds). */
    private const int POLL_TIMEOUT = 20;

    /** Delay between poll attempts (seconds). */
    private const int POLL_INTERVAL = 2;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Diagnose hotel search API call — &hotel_id=X or &hotel_name=kazbek — shows raw request/response';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        $hotelName = TypeCoerce::toString($params['hotel_name'] ?? '');

        $checkIn = TypeCoerce::toString($params['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($params['check_out'] ?? '');
        $adults = max(1, TypeCoerce::toInt($params['adults'] ?? 2));
        $rooms = max(1, TypeCoerce::toInt($params['rooms'] ?? 1));

        // Remember whether dates were supplied so we can warn the operator: a
        // defaulted date can land out of season and return 0 offers, which is
        // easily mistaken for "the hotel has no availability".
        $datesDefaulted = $checkIn === '';
        if ($checkIn === '') {
            $checkIn = date('Y-m-d', (int) strtotime('+30 days'));
        }
        if ($checkOut === '') {
            $checkOut = date('Y-m-d', (int) strtotime($checkIn . ' +7 days'));
        }

        // ── Resolve hotel by name if no ID was given ───────────────────
        if ($hotelId === '' && $hotelName !== '') {
            $hotelId = $this->resolveHotelByName($hotelName);
            if ($hotelId === '') {
                return ['success' => false, 'error' => 'hotel not found', 'query' => $hotelName];
            }
        }

        if ($hotelId === '') {
            $this->output('ERROR: provide &hotel_id=<id> or &hotel_name=<partial name>.');
            $this->output('  Example: &cron_mode=diagnose_search&hotel_name=kazbek');
            return ['success' => false, 'error' => 'hotel_id or hotel_name required'];
        }

        // ── Identify the hotel in the local DB ─────────────────────────
        $hotel = Container::getHotelRepository()->getById($hotelId);
        if ($hotel === null) {
            $this->output('');
            $this->output("WARNING: hotel [{$hotelId}] is NOT in the local sphinx_hotels table.");
            $this->output('  Will look it up directly from the API below; the search call still runs regardless.');
        } else {
            $this->output('');
            $this->output('--- Hotel record (local DB) ---');
            $this->output('  name             = ' . TypeCoerce::toString($hotel['name'] ?? ''));
            $this->output('  country_code     = ' . TypeCoerce::toString($hotel['country_code'] ?? ''));
            $this->output('  destination_name = ' . TypeCoerce::toString($hotel['destination_name'] ?? ''));
            $this->output('  classification   = ' . TypeCoerce::toString($hotel['classification'] ?? '') . '*');
            $this->output('  property_type    = ' . TypeCoerce::toString($hotel['property_type'] ?? ''));
            $this->output('  product_id       = ' . TypeCoerce::toString($hotel['product_id'] ?? '0'));
            $this->output('  destination_id   = ' . TypeCoerce::toString($hotel['destination_id'] ?? '0'));
            $this->output('  sync_status      = ' . TypeCoerce::toString($hotel['sync_status'] ?? ''));
        }

        // Destination drives the live availability search (the storefront PDP
        // only knows hotel_id; section 8 compares hotel_ids vs destination_id).
        $destinationId = $hotel !== null ? TypeCoerce::toInt($hotel['destination_id'] ?? 0) : 0;

        // ── Configuration ──────────────────────────────────────────────
        $this->output('');
        $this->output('--- 1. Configuration ---');

        $apiBaseUrl = ConfigProvider::getApiBaseUrl();
        $apiKey = ConfigProvider::getApiKey();
        $currency = ConfigProvider::getDefaultCurrency();
        $maskedKey = $apiKey !== ''
            ? substr($apiKey, 0, 8) . '…' . substr($apiKey, -4)
            : '(not set)';

        $this->output("  api_base_url = {$apiBaseUrl}");
        $this->output("  api_key      = {$maskedKey}");
        $this->output("  currency     = {$currency}");

        if ($apiBaseUrl === '') {
            $this->output('ERROR: api_base_url is not configured.');
            return ['success' => false, 'error' => 'api_base_url not configured'];
        }
        if ($apiKey === '') {
            $this->output('ERROR: api_key is not configured — API calls will fail with HTTP 401.');
            return ['success' => false, 'error' => 'api_key not configured'];
        }

        $api = Container::getApi();
        $client = $api->getHttpClient();

        // ── Identify the hotel directly from the API ───────────────────
        // GET /api/v1/static/hotels/{id} — authoritative name/location,
        // works even when the hotel was never synced into the local DB.
        $this->output('');
        $this->output("--- Hotel record (API: GET /api/v1/static/hotels/{$hotelId}) ---");
        $apiHotel = $api->getHotel($hotelId);
        if ($apiHotel === null) {
            $this->output('  NOT FOUND — HTTP ' . $client->getLastHttpCode() . ': ' . ($client->getLastError() ?: '(none)'));
            $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 300));
            if ($client->getLastHttpCode() === 404) {
                $this->output("  => The API does not know hotel [{$hotelId}] — the ID is wrong or no longer valid.");
            }
        } else {
            /** @var mixed $rawData */
            $rawData = $apiHotel['data'] ?? $apiHotel;
            $data = is_array($rawData) ? $rawData : $apiHotel;
            $this->output('  name        = ' . TypeCoerce::toString($data['name'] ?? '(none)'));
            $this->output('  city        = ' . TypeCoerce::toString($data['city'] ?? $data['destination_name'] ?? ''));
            $this->output('  country     = ' . TypeCoerce::toString($data['country'] ?? $data['country_code'] ?? ''));
            $this->output('  stars       = ' . TypeCoerce::toString($data['classification'] ?? $data['stars'] ?? ''));
            $this->output('  type        = ' . TypeCoerce::toString($data['property_type'] ?? $data['type'] ?? ''));

            if ($destinationId <= 0) {
                $destinationId = TypeCoerce::toInt($data['destination_id'] ?? 0);
            }
        }

        $this->output('');
        $this->output("=== Diagnosing search for hotel [{$hotelId}] ===");
        $this->output("Dates: {$checkIn} → {$checkOut} | adults={$adults}, rooms={$rooms}");
        if ($datesDefaulted) {
            $this->output('  NOTE: no &check_in supplied — using default (+30 days). To reproduce a');
            $this->output('        customer search, pass &check_in=YYYY-MM-DD&check_out=YYYY-MM-DD.');
        }

        // ── Connectivity check ─────────────────────────────────────────
        $this->output('');
        $this->output('--- 2. Connectivity (GET /api/v1/ping) ---');

        $ping = $api->ping();
        if ($ping === null) {
            $this->output('  FAIL — HTTP ' . $client->getLastHttpCode() . ': ' . $client->getLastError());
            $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 200));
        } else {
            $this->output('  OK — keys: ' . implode(', ', array_keys($ping)));
        }

        // ── Auth check ─────────────────────────────────────────────────
        $this->output('');
        $this->output('--- 3. Auth check (GET /api/v1/me) ---');

        $me = $api->me();
        if ($me === null) {
            $httpCode = $client->getLastHttpCode();
            $this->output("  FAIL — HTTP {$httpCode}: " . $client->getLastError());
            $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 200));
            if ($httpCode === 401) {
                $this->output('  => api_key is invalid or expired.');
            }
        } else {
            $this->output('  OK — keys: ' . implode(', ', array_keys($me)));
        }

        // ── Search request ─────────────────────────────────────────────
        $this->output('');
        $this->output('--- 4. Search request (POST /api/v1/hotels/search) ---');

        $occupancy = [];
        for ($r = 0; $r < $rooms; $r++) {
            $occupancy[] = ['adults' => $adults, 'children_ages' => []];
        }

        $searchParams = [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'occupancy' => $occupancy,
            'currency' => $currency,
            'hotel_ids' => [$hotelId],
        ];

        $ignoreDomains = ConfigProvider::getIgnoreDomains();
        if ($ignoreDomains !== '') {
            $searchParams['ignore_domains'] = $ignoreDomains;
        }

        $this->output('  Request body: ' . $this->trunc(
            (string) json_encode($searchParams, JSON_UNESCAPED_UNICODE),
            400,
        ));

        // ── Search API response ────────────────────────────────────────
        $this->output('');
        $this->output('--- 5. Search API response ---');

        $searchResponse = $api->searchHotels($searchParams);
        $httpCode = $client->getLastHttpCode();
        $rawResponse = $client->getLastResponseRaw() ?? '';

        $this->output("  HTTP: {$httpCode}");
        $this->output('  Error: ' . ($client->getLastError() ?: '(none)'));
        $this->output('  Raw response: ' . $this->trunc($rawResponse, 800));

        if ($searchResponse === null) {
            $this->output('');
            if ($httpCode === 401) {
                $this->output('=== DIAGNOSIS: HTTP 401 — api_key is invalid or not authorized for hotel search. ===');
            } elseif ($httpCode === 400 || $httpCode === 422) {
                $this->output("=== DIAGNOSIS: HTTP {$httpCode} — request body rejected by API. See raw response above. ===");
            } elseif ($httpCode === 0) {
                $this->output('=== DIAGNOSIS: HTTP 0 — network/DNS error or connection timeout. Check api_base_url. ===');
            } else {
                $this->output("=== DIAGNOSIS: HTTP {$httpCode} — see error and raw response above. ===");
            }
            return ['success' => false, 'http_code' => $httpCode, 'error' => $client->getLastError()];
        }

        $searchId = TypeCoerce::toString($searchResponse['search_id'] ?? '');

        // The API now returns the search_id wrapped inside a `cursor` JWT instead
        // of a top-level field; the cursor is the opaque token used for polling.
        $cursorToken = TypeCoerce::toString($searchResponse['cursor'] ?? '');
        if ($searchId === '' && $cursorToken !== '') {
            $searchId = SphinxApi::extractSearchIdFromCursor($cursorToken);
        }

        $status = TypeCoerce::toString($searchResponse['status'] ?? '');
        $topKeys = implode(', ', array_keys($searchResponse));

        $this->output("  Parsed OK — keys: {$topKeys}");
        $this->output('  search_id = ' . ($searchId ?: '(not in response — extracted from cursor)'));
        $this->output("  status    = {$status}");

        if ($searchId === '' && $cursorToken === '') {
            $this->output('');
            $this->output('=== DIAGNOSIS: API returned HTTP 200 but no cursor/search_id in the response. ===');
            $this->output('    The search controller requires a cursor (or search_id) to proceed with polling.');
            $this->output('    Check the full raw response above for an error message from the Sphinx API.');
            return ['success' => false, 'error' => 'no cursor/search_id', 'response' => $topKeys];
        }

        // ── Poll for results (loop until completed or timeout) ─────────
        $this->output('');
        $this->output('--- 6. Polling results (GET /api/v1/hotels/results) ---');

        $poll = $this->pollAll($api, $cursorToken, $searchId, true);
        $pollStatus = $poll['status'];
        $allResults = $poll['results'];
        $polls = $poll['polls'];
        $count = count($allResults);
        $this->output('  Final status: ' . $pollStatus . " after {$polls} poll(s)");
        $this->output('  Results count: ' . $count);

        if ($count > 0) {
            $first = $allResults[0];
            $this->output('  First result keys : ' . implode(', ', array_keys($first)));
            $this->output('  First result name : ' . TypeCoerce::toString($first['name'] ?? $first['hotel_name'] ?? '(none)'));
            $this->output('  First result price: ' . TypeCoerce::toString($first['price'] ?? '(none)') . ' ' . TypeCoerce::toString($first['currency'] ?? ''));
            $this->output('');
            $this->output("=== DIAGNOSIS: search works — hotel [{$hotelId}] has {$count} offer(s). ===");
        } elseif ($pollStatus === 'pending') {
            $this->output('');
            $this->output('=== DIAGNOSIS: still pending after ' . self::POLL_TIMEOUT . 's — API is slow or stuck. ===');
            $this->output('    The storefront JS would keep polling every 2s until completed.');
        } else {
            $this->output('');
            $this->output('=== DIAGNOSIS: search completed with NO availability for these dates/occupancy. ===');
            $this->output('    The API works but this hotel has no offers. Try different dates or a different hotel.');
        }

        // ── 7. Search strategy comparison ──────────────────────────────
        // The storefront PDP search sends hotel_ids only (section 4 above).
        // The Sphinx API appears to require a destination_id to run a live
        // availability search, so compare the three strategies side by side.
        $this->output('');
        $this->output('--- 7. Search strategy comparison ---');

        if ($destinationId <= 0) {
            $this->output('  SKIP — no destination_id known for this hotel (not in local DB and not returned by the API).');
        } else {
            $this->output("  Using destination_id={$destinationId}.");

            $baseParams = [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'occupancy' => $occupancy,
                'currency' => $currency,
            ];
            if ($ignoreDomains !== '') {
                $baseParams['ignore_domains'] = $ignoreDomains;
            }

            $this->output("  (a) hotel_ids only → see sections 4-6 above (total {$count}).");
            $this->runVariant($api, 'b: destination_id only', $baseParams + ['destination_id' => $destinationId], $hotelId);
            $this->runVariant($api, 'c: destination_id + hotel_ids', $baseParams + ['destination_id' => $destinationId, 'hotel_ids' => [$hotelId]], $hotelId);

            $this->output('');
            $this->output('  => The storefront (search.php) queries by destination_id ONLY and narrows');
            $this->output('     to this hotel client-side. The API\'s hotel_ids filter returns an empty');
            $this->output('     set when combined with destination_id, which is why (c) is usually 0.');
            $this->output('     If (b) lists this hotel id above, availability works on the storefront.');
            $this->output('     If (b) does NOT list it, either the hotel genuinely has no offers for');
            $this->output('     these dates/occupancy, or its destination_id mapping is wrong.');
        }

        // ── Rate limit state ───────────────────────────────────────────
        $rlState = $client->getRateLimitState();
        $rlLimit = $rlState['limit'];
        if ($rlLimit !== null) {
            $this->output('');
            $this->output('--- 8. Rate limit ---');
            $this->output(
                '  limit=' . TypeCoerce::toString($rlLimit)
                . ', remaining=' . ($rlState['remaining'] !== null ? TypeCoerce::toString($rlState['remaining']) : '?')
                . ', reset_in=' . ($rlState['reset_in'] !== null ? TypeCoerce::toString($rlState['reset_in']) : '?') . 's',
            );
        }

        $this->output('');
        $this->output("=== Done: search_id={$searchId}, status={$pollStatus}, results={$count} ===");

        return [
            'success' => true,
            'hotel_id' => $hotelId,
            'search_id' => $searchId,
            'status' => $pollStatus,
            'results' => $count,
            'http_code' => $httpCode,
        ];
    }

    /**
     * Poll /api/v1/hotels/results until the search completes or the poll
     * timeout is reached, accumulating every result page.
     *
     * @return array{status: string, results: list<array<string, mixed>>, polls: int}
     */
    private function pollAll(SphinxApi $api, string $cursorToken, string $searchId, bool $verbose): array
    {
        $client = $api->getHttpClient();
        $deadline = time() + self::POLL_TIMEOUT;
        // Poll by cursor (mirrors the storefront flow): start from the cursor
        // token, then follow the cursor the API returns on each page.
        $cursor = $cursorToken !== '' ? $cursorToken : $searchId;
        $pollStatus = 'pending';
        /** @var list<array<string, mixed>> $allResults */
        $allResults = [];
        $polls = 0;

        while (time() < $deadline) {
            $polls++;
            $pollResponse = $api->getHotelResults('', $cursor);
            if ($pollResponse === null) {
                if ($verbose) {
                    $this->output('  POLL FAIL — HTTP ' . $client->getLastHttpCode() . ': ' . $client->getLastError());
                    $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 300));
                }
                $pollStatus = 'error';
                break;
            }

            $pollStatus = TypeCoerce::toString($pollResponse['status'] ?? 'completed');
            $batch = TypeCoerce::toRowList($pollResponse['results'] ?? $pollResponse['data'] ?? []);
            if ($batch !== []) {
                $allResults = array_merge($allResults, $batch);
            }

            $nextCursor = TypeCoerce::toString($pollResponse['cursor'] ?? $pollResponse['next_cursor'] ?? '');
            $cursor = $nextCursor !== '' ? $nextCursor : null;

            if ($verbose) {
                $this->output(sprintf(
                    '    poll #%d: status=%s, +%d result(s) (total %d)',
                    $polls,
                    $pollStatus,
                    count($batch),
                    count($allResults),
                ));
            }

            if ($pollStatus === 'completed' && $cursor === null) {
                break;
            }

            if (time() < $deadline) {
                sleep(self::POLL_INTERVAL);
            }
        }

        return ['status' => $pollStatus, 'results' => $allResults, 'polls' => $polls];
    }

    /**
     * Run one search variant end-to-end (search + poll) and print a concise
     * one-line summary including how many offers came back for the target hotel.
     *
     * @param array<string, mixed> $searchParams
     */
    private function runVariant(SphinxApi $api, string $label, array $searchParams, string $targetHotelId): void
    {
        $client = $api->getHttpClient();
        $this->output('');
        $this->output("  [{$label}] body: " . $this->trunc((string) json_encode($searchParams, JSON_UNESCAPED_UNICODE), 300));

        $resp = $api->searchHotels($searchParams);
        $httpCode = $client->getLastHttpCode();
        if ($resp === null) {
            $this->output("  [{$label}] search FAILED — HTTP {$httpCode}: " . ($client->getLastError() ?: '(none)'));
            return;
        }

        $cursorToken = TypeCoerce::toString($resp['cursor'] ?? '');
        $searchId = TypeCoerce::toString($resp['search_id'] ?? '');
        if ($searchId === '' && $cursorToken !== '') {
            $searchId = SphinxApi::extractSearchIdFromCursor($cursorToken);
        }
        if ($cursorToken === '' && $searchId === '') {
            $this->output("  [{$label}] no cursor/search_id in response — cannot poll.");
            return;
        }

        $poll = $this->pollAll($api, $cursorToken, $searchId, false);
        $results = $poll['results'];

        // Match on hotel_id OR id — the results endpoint is not consistent
        // about which key carries the hotel id, and matching only one key can
        // under-count (and mislead the operator into thinking a hotel that IS
        // present has no availability). This mirrors the storefront filter.
        $targetCount = 0;
        $sampleIds = [];
        foreach ($results as $r) {
            $rid = TypeCoerce::toString($r['hotel_id'] ?? $r['id'] ?? '');
            if ($rid === $targetHotelId) {
                $targetCount++;
            }
            if (count($sampleIds) < 15 && $rid !== '' && !in_array($rid, $sampleIds, true)) {
                $sampleIds[] = $rid;
            }
        }

        $this->output(sprintf(
            '  [%s] status=%s, %d poll(s), %d total result(s), %d for hotel [%s]',
            $label,
            $poll['status'],
            $poll['polls'],
            count($results),
            $targetCount,
            $targetHotelId,
        ));

        if ($results !== []) {
            $present = in_array($targetHotelId, $sampleIds, true) || $targetCount > 0;
            $this->output(
                '    distinct hotel ids in results (first 15): '
                . ($sampleIds === [] ? '(none — results carry no hotel_id/id field!)' : implode(', ', $sampleIds)),
            );
            $this->output(
                $present
                    ? "    => hotel [{$targetHotelId}] IS present in this destination's results."
                    : "    => hotel [{$targetHotelId}] is NOT among this destination's results.",
            );
        }
    }

    /**
     * Look up a hotel by partial name, print the match(es) found, and return
     * the hotel_id of the best (first) match, or '' if nothing found.
     */
    private function resolveHotelByName(string $name): string
    {
        $this->output("=== Looking up hotel by name: \"{$name}\" ===");

        $matches = Container::getHotelRepository()->searchByName($name, 5);

        if ($matches === []) {
            $this->output("  ERROR: no hotel found matching \"{$name}\" in the local DB.");
            $this->output('  Make sure the hotel has been synced (cron_mode=hotels) before running this command.');
            return '';
        }

        foreach ($matches as $i => $h) {
            $hid = TypeCoerce::toString($h['hotel_id'] ?? '');
            $hname = TypeCoerce::toString($h['name'] ?? '');
            $country = TypeCoerce::toString($h['country_code'] ?? '');
            $dest = TypeCoerce::toString($h['destination_name'] ?? '');
            $stars = TypeCoerce::toString($h['classification'] ?? '');
            $label = $i === 0 ? ' ← using this one' : '';
            $this->output("  [{$hid}] {$hname} ({$stars}*), {$dest}, {$country}{$label}");
        }

        $this->output('');
        return TypeCoerce::toString($matches[0]['hotel_id'] ?? '');
    }

    private function trunc(string $str, int $max): string
    {
        if (strlen($str) <= $max) {
            return $str;
        }
        return substr($str, 0, $max) . '… [+' . (strlen($str) - $max) . ' chars]';
    }
}
