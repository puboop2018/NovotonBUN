<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: diagnose hotel search.
 *
 * Two modes — both make REAL search API calls and report the full
 * request/response so you can see exactly why searches fail or return
 * no results. Safe to run at any time: never creates bookings or
 * modifies any data.
 *
 *  1. Single hotel  — &hotel_id=2249
 *     Detailed step-by-step diagnosis (config, ping, auth, search, poll).
 *
 *  2. Scan a country — &country=MT
 *     Walks the synced hotels for that country, searches each one, and
 *     reports the FIRST hotel that actually returns availability. Use
 *     this to find a working hotel to test with. Add &all=Y to list
 *     every hotel scanned, &limit=N to cap how many to try (default 20).
 *
 * Usage:
 *   cron_mode=diagnose_search&hotel_id=2249
 *   cron_mode=diagnose_search&hotel_id=2249&check_in=2026-07-05&check_out=2026-07-12
 *   cron_mode=diagnose_search&country=MT
 *   cron_mode=diagnose_search&country=MT&limit=30&all=Y
 */
class DiagnoseSearchCommand extends AbstractSyncCommand
{
    /** How long to poll a single search for results before giving up (seconds). */
    private const int POLL_TIMEOUT = 20;

    /** Delay between poll attempts (seconds). */
    private const int POLL_INTERVAL = 2;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Diagnose hotel search — &hotel_id=X for one hotel, or &country=MT to find a hotel with availability';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        $country = strtoupper(TypeCoerce::toString($params['country'] ?? ''));

        $checkIn = TypeCoerce::toString($params['check_in'] ?? '');
        $checkOut = TypeCoerce::toString($params['check_out'] ?? '');
        $adults = max(1, TypeCoerce::toInt($params['adults'] ?? 2));
        $rooms = max(1, TypeCoerce::toInt($params['rooms'] ?? 1));

        if ($checkIn === '') {
            $checkIn = date('Y-m-d', (int) strtotime('+30 days'));
        }
        if ($checkOut === '') {
            $checkOut = date('Y-m-d', (int) strtotime($checkIn . ' +7 days'));
        }

        // ── Configuration (shared by both modes) ──────────────────────
        $apiBaseUrl = ConfigProvider::getApiBaseUrl();
        $apiKey = ConfigProvider::getApiKey();
        $currency = ConfigProvider::getDefaultCurrency();
        $maskedKey = $apiKey !== ''
            ? substr($apiKey, 0, 8) . '…' . substr($apiKey, -4)
            : '(not set)';

        $this->output('--- Configuration ---');
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

        if ($hotelId === '' && $country === '') {
            $this->output('');
            $this->output('ERROR: provide &hotel_id=<id> (single hotel) or &country=MT (scan for availability).');
            return ['success' => false, 'error' => 'hotel_id or country required'];
        }

        if ($hotelId === '') {
            return $this->scanCountry($country, $checkIn, $checkOut, $adults, $rooms, $currency, $params);
        }

        return $this->diagnoseSingle($hotelId, $checkIn, $checkOut, $adults, $rooms, $currency);
    }

    /**
     * Scan a country's synced hotels and report the first one with availability.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function scanCountry(
        string $country,
        string $checkIn,
        string $checkOut,
        int $adults,
        int $rooms,
        string $currency,
        array $params,
    ): array {
        $limit = max(1, TypeCoerce::toInt($params['limit'] ?? 20));
        $listAll = TypeCoerce::toString($params['all'] ?? '') === 'Y';

        $this->output('');
        $this->output("=== Scanning country [{$country}] for a hotel with availability ===");
        $this->output("Dates: {$checkIn} → {$checkOut} | adults={$adults}, rooms={$rooms} | trying up to {$limit} hotels");

        $repo = Container::getHotelRepository();
        $page = $repo->getFiltered($country, 0, 0, 'synced', '', 1, $limit);
        $hotels = $page['items'];

        if ($hotels === []) {
            // Fall back to any sync_status if none are explicitly "synced".
            $page = $repo->getFiltered($country, 0, 0, '', '', 1, $limit);
            $hotels = $page['items'];
        }

        $this->output('  Hotels available in DB for this country: ' . $page['total']);

        if ($hotels === []) {
            $this->output('');
            $this->output("=== DIAGNOSIS: no hotels synced for country '{$country}'. Run cron_mode=hotels&country={$country} first. ===");
            return ['success' => false, 'error' => 'no hotels for country', 'country' => $country];
        }

        $api = Container::getApi();
        $client = $api->getHttpClient();

        $tried = 0;
        $found = [];
        $occupancy = $this->buildOccupancy($adults, $rooms);

        foreach ($hotels as $hotel) {
            $hid = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $name = TypeCoerce::toString($hotel['name'] ?? '(unnamed)');
            if ($hid === '') {
                continue;
            }
            $tried++;

            $searchParams = [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'occupancy' => $occupancy,
                'currency' => $currency,
                'hotel_id' => $hid,
            ];

            $resp = $api->searchHotels($searchParams);
            $http = $client->getLastHttpCode();

            if ($resp === null) {
                $this->output(sprintf('  [%s] %s — search FAILED (HTTP %d: %s)', $hid, $name, $http, $client->getLastError()));
                continue;
            }

            $searchId = TypeCoerce::toString($resp['search_id'] ?? '');
            if ($searchId === '') {
                $this->output(sprintf('  [%s] %s — no search_id (HTTP %d)', $hid, $name, $http));
                continue;
            }

            $poll = $this->pollForResults($searchId, self::POLL_TIMEOUT);
            $count = count($poll['results']);

            if ($count > 0) {
                $first = $poll['results'][0];
                $price = TypeCoerce::toString($first['price'] ?? '(n/a)');
                $this->output(sprintf(
                    '  [%s] %s — ✓ AVAILABLE: %d offer(s), from %s %s (search_id=%s)',
                    $hid,
                    $name,
                    $count,
                    $price,
                    $currency,
                    $searchId,
                ));
                $found[] = ['hotel_id' => $hid, 'name' => $name, 'offers' => $count, 'search_id' => $searchId];
                if (!$listAll) {
                    $this->output('');
                    $this->output('=== FOUND a hotel with availability — stopping (add &all=Y to keep scanning). ===');
                    $this->output("    Test the storefront search with: &hotel_id={$hid}");
                    $this->output("    Or run a detailed diagnosis: &cron_mode=diagnose_search&hotel_id={$hid}");
                    break;
                }
            } else {
                $this->output(sprintf('  [%s] %s — no availability (status=%s)', $hid, $name, $poll['status']));
            }
        }

        $this->output('');
        $this->output("=== Scan complete: tried {$tried} hotel(s), found " . count($found) . ' with availability. ===');

        return [
            'success' => $found !== [],
            'country' => $country,
            'tried' => $tried,
            'found' => $found,
        ];
    }

    /**
     * Detailed step-by-step diagnosis of a single hotel search.
     *
     * @return array<string, mixed>
     */
    private function diagnoseSingle(
        string $hotelId,
        string $checkIn,
        string $checkOut,
        int $adults,
        int $rooms,
        string $currency,
    ): array {
        $this->output('');
        $this->output("=== Diagnosing search for hotel [{$hotelId}] ===");
        $this->output("Dates: {$checkIn} → {$checkOut} | adults={$adults}, rooms={$rooms}");

        $api = Container::getApi();
        $client = $api->getHttpClient();

        // ── Connectivity check ────────────────────────────────────────
        $this->output('');
        $this->output('--- 1. Connectivity (GET /api/v1/ping) ---');

        $ping = $api->ping();
        if ($ping === null) {
            $this->output('  FAIL — HTTP ' . $client->getLastHttpCode() . ': ' . $client->getLastError());
            $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 200));
        } else {
            $this->output('  OK — keys: ' . implode(', ', array_keys($ping)));
        }

        // ── Auth check ────────────────────────────────────────────────
        $this->output('');
        $this->output('--- 2. Auth check (GET /api/v1/me) ---');

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

        // ── Search request ────────────────────────────────────────────
        $this->output('');
        $this->output('--- 3. Search request (POST /api/v1/hotels/search) ---');

        $searchParams = [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'occupancy' => $this->buildOccupancy($adults, $rooms),
            'currency' => $currency,
            'hotel_id' => $hotelId,
        ];

        $ignoreDomains = ConfigProvider::getIgnoreDomains();
        if ($ignoreDomains !== '') {
            $searchParams['ignore_domains'] = $ignoreDomains;
        }

        $this->output('  Request body: ' . $this->trunc(
            (string) json_encode($searchParams, JSON_UNESCAPED_UNICODE),
            400,
        ));

        // ── Search API response ───────────────────────────────────────
        $this->output('');
        $this->output('--- 4. Search API response ---');

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
        $status = TypeCoerce::toString($searchResponse['status'] ?? '');
        $topKeys = implode(', ', array_keys($searchResponse));

        $this->output("  Parsed OK — keys: {$topKeys}");
        $this->output('  search_id = ' . ($searchId ?: '(empty — this is why the search page shows an error)'));
        $this->output("  status    = {$status}");

        if ($searchId === '') {
            $this->output('');
            $this->output('=== DIAGNOSIS: API returned HTTP 200 but no search_id in the response. ===');
            $this->output('    The search controller requires search_id to proceed with polling.');
            $this->output('    Check the full raw response above for an error message from the Sphinx API.');
            return ['success' => false, 'error' => 'no search_id', 'response' => $topKeys];
        }

        // ── Poll for results (loop until completed or timeout) ─────────
        $this->output('');
        $this->output("--- 5. Polling results (GET /api/v1/hotels/results?search_id={$searchId}) ---");

        $poll = $this->pollForResults($searchId, self::POLL_TIMEOUT, true);
        $results = $poll['results'];
        $count = count($results);

        $this->output('  Final status: ' . $poll['status'] . " after {$poll['polls']} poll(s)");
        $this->output('  Results count: ' . $count);

        if ($count > 0) {
            $first = $results[0];
            $this->output('  First result keys: ' . implode(', ', array_keys($first)));
            $this->output('  First result name : ' . TypeCoerce::toString($first['name'] ?? $first['hotel_name'] ?? '(none)'));
            $this->output('  First result price: ' . TypeCoerce::toString($first['price'] ?? '(none)') . ' ' . TypeCoerce::toString($first['currency'] ?? ''));
            $this->output('');
            $this->output("=== DIAGNOSIS: search works — hotel [{$hotelId}] has {$count} offer(s). ===");
        } elseif ($poll['status'] === 'pending') {
            $this->output('');
            $this->output('=== DIAGNOSIS: still pending after ' . self::POLL_TIMEOUT . 's. The API is slow or stuck; the storefront would keep polling. ===');
        } else {
            $this->output('');
            $this->output('=== DIAGNOSIS: search works but hotel has NO availability for these dates/occupancy. ===');
            $this->output('    Try other dates, or use &country=<code> to find a hotel that does have availability.');
        }

        // ── Rate limit state ──────────────────────────────────────────
        $rlState = $client->getRateLimitState();
        $rlLimit = $rlState['limit'];
        if ($rlLimit !== null) {
            $this->output('');
            $this->output('--- 6. Rate limit ---');
            $this->output(
                '  limit=' . TypeCoerce::toString($rlLimit)
                . ', remaining=' . ($rlState['remaining'] !== null ? TypeCoerce::toString($rlState['remaining']) : '?')
                . ', reset_in=' . ($rlState['reset_in'] !== null ? TypeCoerce::toString($rlState['reset_in']) : '?') . 's',
            );
        }

        return [
            'success' => true,
            'hotel_id' => $hotelId,
            'search_id' => $searchId,
            'status' => $poll['status'],
            'results' => $count,
            'http_code' => $httpCode,
        ];
    }

    /**
     * Build the occupancy array for N identical rooms.
     *
     * @return list<array<string, mixed>>
     */
    private function buildOccupancy(int $adults, int $rooms): array
    {
        $occupancy = [];
        for ($r = 0; $r < $rooms; $r++) {
            $occupancy[] = ['adults' => $adults, 'children_ages' => []];
        }
        return $occupancy;
    }

    /**
     * Poll a search until it completes or the timeout elapses, accumulating
     * results across cursor pages exactly like the storefront JS does.
     *
     * @return array{status: string, results: list<array<string, mixed>>, polls: int}
     */
    private function pollForResults(string $searchId, int $timeoutSeconds, bool $verbose = false): array
    {
        $api = Container::getApi();
        $deadline = time() + $timeoutSeconds;
        $cursor = null;
        $status = 'pending';
        /** @var list<array<string, mixed>> $all */
        $all = [];
        $polls = 0;

        while (time() < $deadline) {
            $polls++;
            $resp = $api->getHotelResults($searchId, $cursor);
            if ($resp === null) {
                $status = 'error';
                break;
            }

            $status = TypeCoerce::toString($resp['status'] ?? 'completed');
            $batch = TypeCoerce::toRowList($resp['results'] ?? []);
            if ($batch !== []) {
                $all = array_merge($all, $batch);
            }

            $next = isset($resp['next_cursor']) ? TypeCoerce::toString($resp['next_cursor']) : '';
            $cursor = $next !== '' ? $next : null;

            if ($verbose) {
                $this->output(sprintf('    poll #%d: status=%s, +%d result(s) (total %d)', $polls, $status, count($batch), count($all)));
            }

            if ($status === 'completed' && $cursor === null) {
                break;
            }

            if (time() < $deadline) {
                sleep(self::POLL_INTERVAL);
            }
        }

        return ['status' => $status, 'results' => $all, 'polls' => $polls];
    }

    private function trunc(string $str, int $max): string
    {
        if (strlen($str) <= $max) {
            return $str;
        }
        return substr($str, 0, $max) . '… [+' . (strlen($str) - $max) . ' chars]';
    }
}
