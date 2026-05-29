<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
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
            $this->output('  sync_status      = ' . TypeCoerce::toString($hotel['sync_status'] ?? ''));
        }

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
        }

        $this->output('');
        $this->output("=== Diagnosing search for hotel [{$hotelId}] ===");
        $this->output("Dates: {$checkIn} → {$checkOut} | adults={$adults}, rooms={$rooms}");

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
        $this->output("--- 6. Polling results (GET /api/v1/hotels/results?search_id={$searchId}) ---");

        $deadline = time() + self::POLL_TIMEOUT;
        $cursor = null;
        $pollStatus = 'pending';
        /** @var list<array<string, mixed>> $allResults */
        $allResults = [];
        $polls = 0;

        while (time() < $deadline) {
            $polls++;
            $pollResponse = $api->getHotelResults($searchId, $cursor);
            if ($pollResponse === null) {
                $this->output('  POLL FAIL — HTTP ' . $client->getLastHttpCode() . ': ' . $client->getLastError());
                $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 300));
                $pollStatus = 'error';
                break;
            }

            $pollStatus = TypeCoerce::toString($pollResponse['status'] ?? 'completed');
            $batch = TypeCoerce::toRowList($pollResponse['results'] ?? []);
            if ($batch !== []) {
                $allResults = array_merge($allResults, $batch);
            }

            $nextCursor = isset($pollResponse['next_cursor']) ? TypeCoerce::toString($pollResponse['next_cursor']) : '';
            $cursor = $nextCursor !== '' ? $nextCursor : null;

            $this->output(sprintf(
                '    poll #%d: status=%s, +%d result(s) (total %d)',
                $polls,
                $pollStatus,
                count($batch),
                count($allResults),
            ));

            if ($pollStatus === 'completed' && $cursor === null) {
                break;
            }

            if (time() < $deadline) {
                sleep(self::POLL_INTERVAL);
            }
        }

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

        // ── Rate limit state ───────────────────────────────────────────
        $rlState = $client->getRateLimitState();
        $rlLimit = $rlState['limit'];
        if ($rlLimit !== null) {
            $this->output('');
            $this->output('--- 7. Rate limit ---');
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
