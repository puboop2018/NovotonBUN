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
 * Usage:
 *   cron_mode=diagnose_search&hotel_id=2249
 *   cron_mode=diagnose_search&hotel_id=2249&check_in=2026-07-05&check_out=2026-07-12
 *   cron_mode=diagnose_search&hotel_id=2249&adults=2&rooms=1
 */
class DiagnoseSearchCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Diagnose hotel search API call for a single hotel — shows raw request/response';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        if ($hotelId === '') {
            $this->output('ERROR: &hotel_id=<id> is required. Example: &cron_mode=diagnose_search&hotel_id=2249');
            return ['success' => false, 'error' => 'hotel_id required'];
        }

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

        $this->output("=== Diagnosing search for hotel [{$hotelId}] ===");
        $this->output("Dates: {$checkIn} → {$checkOut} | adults={$adults}, rooms={$rooms}");

        // ── 1. Configuration ──────────────────────────────────────────
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

        // ── 2. Connectivity check ─────────────────────────────────────
        $this->output('');
        $this->output('--- 2. Connectivity (GET /api/v1/ping) ---');

        $ping = $api->ping();
        if ($ping === null) {
            $this->output('  FAIL — HTTP ' . $client->getLastHttpCode() . ': ' . $client->getLastError());
            $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 200));
        } else {
            $this->output('  OK — keys: ' . implode(', ', array_keys($ping)));
        }

        // ── 3. Auth check ─────────────────────────────────────────────
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

        // ── 4. Search request ─────────────────────────────────────────
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

        // ── 5. Search API response ────────────────────────────────────
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

        // ── 6. Poll for results (single poll) ─────────────────────────
        $this->output('');
        $this->output("--- 6. Polling results (GET /api/v1/hotels/results?search_id={$searchId}) ---");

        $pollResponse = $api->getHotelResults($searchId);
        if ($pollResponse === null) {
            $this->output('  POLL FAIL — HTTP ' . $client->getLastHttpCode() . ': ' . $client->getLastError());
            $this->output('  Raw: ' . $this->trunc($client->getLastResponseRaw() ?? '', 300));
        } else {
            $pollStatus = TypeCoerce::toString($pollResponse['status'] ?? '');
            $this->output('  Poll status: ' . $pollStatus);
            $this->output('  Poll keys: ' . implode(', ', array_keys($pollResponse)));

            /** @var mixed $rawResults */
            $rawResults = $pollResponse['results'] ?? null;
            $results = is_array($rawResults) ? $rawResults : [];
            $this->output('  Results count: ' . count($results));

            if (!empty($results)) {
                $first = is_array($results[0]) ? $results[0] : [];
                $this->output('  First result keys: ' . implode(', ', array_keys($first)));
                $this->output('  First result name : ' . TypeCoerce::toString($first['name'] ?? $first['hotel_name'] ?? '(none)'));
                $this->output('  First result price: ' . TypeCoerce::toString($first['price'] ?? '(none)') . ' ' . TypeCoerce::toString($first['currency'] ?? ''));
            } elseif ($pollStatus === 'pending') {
                $this->output('  Results not ready yet (status=pending). In the browser, JS would continue polling every 2s.');
            } else {
                $this->output('  No results. Hotel may have no availability for these dates/occupancy.');
            }
        }

        // ── 7. Rate limit state ───────────────────────────────────────
        $rlState = $client->getRateLimitState();
        // getRateLimitState() returns array<string, mixed>; values are int|null at runtime.
        // TypeCoerce::toString handles mixed safely without a direct (string) cast.
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
        $this->output("=== Done: search_id={$searchId}, status={$status} ===");

        return [
            'success' => true,
            'hotel_id' => $hotelId,
            'search_id' => $searchId,
            'status' => $status,
            'http_code' => $httpCode,
        ];
    }

    private function trunc(string $str, int $max): string
    {
        if (strlen($str) <= $max) {
            return $str;
        }
        return substr($str, 0, $max) . '… [+' . (strlen($str) - $max) . ' chars]';
    }
}
