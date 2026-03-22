#!/usr/bin/env php
<?php
/**
 * Sphinx API — Board Data Audit (Greece Hotels)
 *
 * Checks 4 endpoints for board/meal data availability:
 *   1. GET  /api/v1/static/hotels          (static hotel data)
 *   2. POST /api/v1/cache/hotels           (pre-cached deals)
 *   3. POST /api/v1/hotels/search + GET /results  (live search)
 *   4. GET  /api/v1/hotels/verify          (offer verification)
 *
 * Usage: php audit_board_data.php
 */
declare(strict_types=1);

// ── Configuration ──
$apiBaseUrl = 'https://api.sphinx2.christiantour.dev.ploi.imementohub.com';
$apiKey     = '51|q3s6ZrK7212SFwQVBh5PkIOsPN9XS9WKJ7BtL9Puafaa6857';

// Search parameters
$checkIn  = date('Y-m-d', strtotime('+30 days'));
$checkOut = date('Y-m-d', strtotime('+37 days'));
$currency = 'EUR';
$maxVerifyOffers = 5;
$searchPollInterval = 3;
$searchMaxPolls = 20;

// ── HTTP Helpers ──

function apiGet(string $baseUrl, string $token, string $endpoint, array $query = []): ?array
{
    $url = $baseUrl . $endpoint;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    return apiRequest('GET', $url, $token);
}

function apiPost(string $baseUrl, string $token, string $endpoint, array $body = []): ?array
{
    return apiRequest('POST', $baseUrl . $endpoint, $token, $body);
}

function apiRequest(string $method, string $url, string $token, ?array $body = null): ?array
{
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        echo "  [ERROR] cURL error: {$error}\n";
        return null;
    }

    if ($httpCode === 429) {
        echo "  [WARN] Rate limited. Waiting 60 seconds...\n";
        sleep(60);
        return apiRequest($method, $url, $token, $body);
    }

    if ($httpCode !== 200) {
        $short = substr((string)$response, 0, 300);
        echo "  [ERROR] HTTP {$httpCode}: {$short}\n";
        return null;
    }

    $data = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  [ERROR] JSON decode: " . json_last_error_msg() . "\n";
        return null;
    }

    return $data;
}

/**
 * Recursively search array keys for board/meal related fields.
 */
function findBoardKeys(array $data, string $prefix = ''): array
{
    $boardKeywords = ['board', 'meal', 'pension', 'catering', 'inclusive'];
    $found = [];

    foreach ($data as $key => $value) {
        $fullKey = $prefix ? "{$prefix}.{$key}" : (string)$key;
        $keyLower = strtolower((string)$key);

        foreach ($boardKeywords as $kw) {
            if (str_contains($keyLower, $kw)) {
                $found[$fullKey] = is_array($value) ? json_encode($value) : (string)$value;
            }
        }

        if (is_array($value) && !is_int($key)) {
            $found = array_merge($found, findBoardKeys($value, $fullKey));
        }
    }

    return $found;
}

// ══════════════════════════════════════════════
echo "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
echo "  SPHINX API BOARD DATA AUDIT — GREECE HOTELS\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "══════════════════════════════════════════════════════════════════════\n\n";

// ── PHASE 1: Get Greece Region Destinations ──
echo "── Phase 1: Greece Destinations (regions only) ──\n";

$greeceRegions = [];
$greeceDestinations = [];
$page = 1;
$lastPage = 1;

// Only scan until we find enough GR regions (they appear scattered across pages)
// Optimization: first scan for regions only, then pick popular ones
do {
    $result = apiGet($apiBaseUrl, $apiKey, '/api/v1/static/destinations', [
        'page' => $page,
        'per_page' => 1000,
    ]);

    if ($result === null) {
        echo "  [ERROR] Failed to fetch page {$page}.\n";
        break;
    }

    if (isset($result['meta']['last_page'])) {
        $lastPage = (int)$result['meta']['last_page'];
    }

    foreach ($result['data'] ?? [] as $dest) {
        if (($dest['country_code'] ?? '') === 'GR') {
            $type = $dest['type'] ?? 'unknown';
            if ($type === 'region') {
                $greeceRegions[] = [
                    'id'   => $dest['id'],
                    'name' => $dest['name'],
                    'type' => $type,
                ];
            }
            $greeceDestinations[] = $dest['id'];
        }
    }

    echo "  Page {$page}/{$lastPage} — GR regions so far: " . count($greeceRegions) . ", destinations: " . count($greeceDestinations) . "\n";

    // Once we have at least 5 regions and many destinations, we can stop
    if (count($greeceRegions) >= 10 && count($greeceDestinations) >= 500) {
        echo "  [INFO] Enough data collected, stopping early.\n";
        break;
    }

    $page++;
    usleep(200000);
} while ($page <= $lastPage);

echo "  Found: " . count($greeceRegions) . " GR regions, " . count($greeceDestinations) . " total GR destinations\n";

if (!empty($greeceRegions)) {
    echo "  Regions:\n";
    foreach ($greeceRegions as $r) {
        echo "    - {$r['name']} (ID: {$r['id']})\n";
    }
}

// Pick popular tourist regions for testing (not random first 3)
$preferredNames = ['Creta', 'South Aegean', 'Ionian', 'Peloponnese', 'Attica'];
$searchRegions = [];
foreach ($preferredNames as $pref) {
    foreach ($greeceRegions as $r) {
        if (str_contains(strtolower($r['name']), strtolower($pref)) && count($searchRegions) < 3) {
            $searchRegions[] = $r;
            break;
        }
    }
}
// Fallback if preferred not found
if (empty($searchRegions)) {
    $searchRegions = array_slice($greeceRegions, 0, 3);
}
echo "\n  Selected for search: " . implode(', ', array_column($searchRegions, 'name')) . "\n";

echo "\n";

// ── PHASE 2: Static Hotel Endpoint ──
echo "── Phase 2: Static Hotel Endpoint (/api/v1/static/hotels) ──\n";

$staticBoardFields = [];
$staticHotelCount = 0;

if (!empty($searchRegions)) {
    // Use the first search region (should be popular like Creta)
    $destId = $searchRegions[0]['id'];
    echo "  Fetching static hotels for region '{$searchRegions[0]['name']}' (ID: {$destId})...\n";

    $staticResult = apiGet($apiBaseUrl, $apiKey, '/api/v1/static/hotels', [
        'page' => 1,
        'per_page' => 10,
        'destination_ids' => [$destId],
    ]);

    if ($staticResult !== null) {
        $staticHotelCount = $staticResult['meta']['total'] ?? $staticResult['total'] ?? count($staticResult['data'] ?? []);
        echo "  Hotels in this region: {$staticHotelCount}\n";

        $hotels = $staticResult['data'] ?? [];
        if (!empty($hotels)) {
            echo "  Sample hotel keys: " . implode(', ', array_keys($hotels[0])) . "\n";

            // Deep search all sample hotels for board fields
            $allBoardKeys = [];
            foreach ($hotels as $h) {
                $found = findBoardKeys($h);
                $allBoardKeys = array_merge($allBoardKeys, $found);
            }

            if (!empty($allBoardKeys)) {
                echo "  Board-related fields FOUND across " . count($hotels) . " hotels:\n";
                foreach ($allBoardKeys as $k => $v) {
                    echo "    {$k} = " . substr($v, 0, 80) . "\n";
                    $staticBoardFields[$k] = $v;
                }
            } else {
                echo "  Board-related fields: NONE found in any of " . count($hotels) . " hotels\n";
            }

            // Also dump first hotel raw for inspection
            echo "\n  First hotel raw dump (abbreviated):\n";
            $first = $hotels[0];
            foreach ($first as $k => $v) {
                if (is_array($v)) {
                    $jsonV = json_encode($v);
                    echo "    {$k}: " . substr($jsonV, 0, 100) . (strlen($jsonV) > 100 ? '...' : '') . "\n";
                } else {
                    echo "    {$k}: {$v}\n";
                }
            }
        }
    }

    // Get total Greece hotel count
    echo "\n  Fetching total Greece hotel count...\n";
    $allStaticResult = apiGet($apiBaseUrl, $apiKey, '/api/v1/static/hotels', [
        'page' => 1,
        'per_page' => 10,
        'destination_ids' => array_slice($greeceDestinations, 0, 50),
    ]);
    $totalGR = $allStaticResult['meta']['total'] ?? '?';
    echo "  Total Greece hotels (static): {$totalGR}\n";
    $staticHotelCount = $totalGR;
}

echo "\n";

// ── PHASE 3: Cache Endpoint ──
echo "── Phase 3: Cache Endpoint (/api/v1/cache/hotels) ──\n";

$cacheHotels = [];
$cacheMealDist = [];
$cacheHotelsWithMeal = [];
$cacheOurMealDist = [];

// Try without filter first
echo "  Fetching cache deals (no filter)...\n";
$cacheResult = apiPost($apiBaseUrl, $apiKey, '/api/v1/cache/hotels', []);

if ($cacheResult !== null) {
    $items = $cacheResult['data'] ?? $cacheResult['results'] ?? $cacheResult['hotels'] ?? [];
    echo "  Response top-level keys: " . implode(', ', array_keys($cacheResult)) . "\n";
    echo "  Deals returned (no filter): " . count($items) . "\n";

    if (!empty($items)) {
        echo "  Sample deal keys: " . implode(', ', array_keys($items[0])) . "\n";

        // Dump first deal
        echo "\n  First cache deal dump:\n";
        foreach ($items[0] as $k => $v) {
            if (is_array($v)) {
                $jsonV = json_encode($v);
                echo "    {$k}: " . substr($jsonV, 0, 100) . (strlen($jsonV) > 100 ? '...' : '') . "\n";
            } else {
                echo "    {$k}: " . substr((string)$v, 0, 100) . "\n";
            }
        }

        echo "\n";

        foreach ($items as $item) {
            $hotelId = $item['id'] ?? $item['hotel_id'] ?? '';
            $mealName = $item['meal_name'] ?? $item['board_name'] ?? $item['board_type'] ?? '';
            $ourMealName = $item['our_meal_name'] ?? '';
            $countryCode = $item['country_code'] ?? '';

            if ($hotelId) {
                $cacheHotels[$hotelId] = [
                    'name' => $item['name'] ?? $item['hotel_name'] ?? $hotelId,
                    'country' => $countryCode,
                    'meal_name' => $mealName,
                    'our_meal_name' => $ourMealName,
                ];
            }

            if ($mealName !== '') {
                $cacheMealDist[$mealName] = ($cacheMealDist[$mealName] ?? 0) + 1;
                if ($hotelId) $cacheHotelsWithMeal[$hotelId] = true;
            }
            if ($ourMealName !== '') {
                $cacheOurMealDist[$ourMealName] = ($cacheOurMealDist[$ourMealName] ?? 0) + 1;
            }
        }
    }
}

// Try with country_code filter
echo "  Fetching cache deals (country_code=GR)...\n";
$cacheGR = apiPost($apiBaseUrl, $apiKey, '/api/v1/cache/hotels', [
    'country_code' => 'GR',
]);
if ($cacheGR !== null) {
    $grItems = $cacheGR['data'] ?? $cacheGR['results'] ?? [];
    echo "    Deals with country_code=GR filter: " . count($grItems) . "\n";
    if (!empty($grItems)) {
        $grCountries = array_unique(array_column($grItems, 'country_code'));
        echo "    Countries in result: " . implode(', ', $grCountries) . "\n";
        foreach ($grItems as $item) {
            $hotelId = $item['id'] ?? $item['hotel_id'] ?? '';
            $mealName = $item['meal_name'] ?? '';
            $ourMealName = $item['our_meal_name'] ?? '';
            $countryCode = $item['country_code'] ?? '';
            if ($hotelId) {
                $cacheHotels[$hotelId] = [
                    'name' => $item['name'] ?? $hotelId,
                    'country' => $countryCode,
                    'meal_name' => $mealName,
                    'our_meal_name' => $ourMealName,
                ];
            }
            if ($mealName !== '') {
                $cacheMealDist[$mealName] = ($cacheMealDist[$mealName] ?? 0) + 1;
                if ($hotelId) $cacheHotelsWithMeal[$hotelId] = true;
            }
            if ($ourMealName !== '') {
                $cacheOurMealDist[$ourMealName] = ($cacheOurMealDist[$ourMealName] ?? 0) + 1;
            }
        }
    }
}
usleep(300000);

// Try per Greece region
foreach ($searchRegions as $region) {
    echo "  Fetching cache for {$region['name']} (ID: {$region['id']})...\n";
    $destCache = apiPost($apiBaseUrl, $apiKey, '/api/v1/cache/hotels', [
        'destination_id' => $region['id'],
    ]);

    if ($destCache !== null) {
        $items = $destCache['data'] ?? $destCache['results'] ?? $destCache['hotels'] ?? [];
        echo "    Deals: " . count($items) . "\n";

        $regionGR = 0;
        foreach ($items as $item) {
            $hotelId = $item['id'] ?? $item['hotel_id'] ?? '';
            $mealName = $item['meal_name'] ?? $item['board_name'] ?? '';
            $ourMealName = $item['our_meal_name'] ?? '';
            $countryCode = $item['country_code'] ?? '';

            if ($countryCode === 'GR') $regionGR++;

            if ($hotelId) {
                $cacheHotels[$hotelId] = [
                    'name' => $item['name'] ?? $item['hotel_name'] ?? $hotelId,
                    'country' => $countryCode,
                    'meal_name' => $mealName,
                    'our_meal_name' => $ourMealName,
                ];
            }
            if ($mealName !== '') {
                $cacheMealDist[$mealName] = ($cacheMealDist[$mealName] ?? 0) + 1;
                if ($hotelId) $cacheHotelsWithMeal[$hotelId] = true;
            }
            if ($ourMealName !== '') {
                $cacheOurMealDist[$ourMealName] = ($cacheOurMealDist[$ourMealName] ?? 0) + 1;
            }
        }
        echo "    Greece hotels in result: {$regionGR}\n";
    }
    usleep(300000);
}

// Count Greece hotels in cache
$cacheGRHotels = array_filter($cacheHotels, fn($h) => ($h['country'] ?? '') === 'GR');

echo "\n  Cache Endpoint Summary:\n";
echo "    Total unique hotels (all countries): " . count($cacheHotels) . "\n";
echo "    Greece unique hotels: " . count($cacheGRHotels) . "\n";
echo "    Hotels WITH meal_name: " . count($cacheHotelsWithMeal) . " / " . count($cacheHotels) . "\n";
$pct = count($cacheHotels) > 0 ? round(count($cacheHotelsWithMeal) / count($cacheHotels) * 100) : 0;
echo "    Meal coverage: {$pct}%\n";
echo "    Unique meal_name values: " . count($cacheMealDist) . "\n";
if (!empty($cacheMealDist)) {
    arsort($cacheMealDist);
    echo "    meal_name distribution:\n";
    foreach ($cacheMealDist as $meal => $count) {
        echo "      \"{$meal}\": {$count} deals\n";
    }
}
echo "    Unique our_meal_name values: " . count($cacheOurMealDist) . "\n";
if (!empty($cacheOurMealDist)) {
    arsort($cacheOurMealDist);
    echo "    our_meal_name distribution:\n";
    foreach ($cacheOurMealDist as $meal => $count) {
        echo "      \"{$meal}\": {$count} deals\n";
    }
}

echo "\n";

// ── PHASE 4: Search + Results ──
echo "── Phase 4: Search Results (/api/v1/hotels/search + /results) ──\n";
echo "  Search params: check_in={$checkIn}, check_out={$checkOut}, 2 adults, {$currency}\n";

$searchHotels = [];
$searchMealDist = [];
$searchBoardFields = [];
$searchTotalOffers = 0;
$searchHotelsWithMeal = [];
$searchOffers = [];

// Use regions for search (more likely to have hotels)
foreach ($searchRegions as $region) {
    echo "\n  Searching: {$region['name']} (ID: {$region['id']})...\n";

    $searchResponse = apiPost($apiBaseUrl, $apiKey, '/api/v1/hotels/search', [
        'destination_id' => $region['id'],
        'check_in'       => $checkIn,
        'check_out'      => $checkOut,
        'occupancy'      => [['adults' => 2, 'children_ages' => []]],
        'currency'       => $currency,
    ]);

    if ($searchResponse === null) {
        echo "    [ERROR] Search request failed.\n";
        continue;
    }

    echo "    Response keys: " . implode(', ', array_keys($searchResponse)) . "\n";

    // The API returns 'cursor' for polling (not search_id)
    $cursor = $searchResponse['cursor'] ?? $searchResponse['search_id'] ?? null;
    if ($cursor === null) {
        echo "    [ERROR] No cursor/search_id in response.\n";
        continue;
    }

    echo "    Cursor received, polling for results...\n";

    // Poll for results
    $pollCount = 0;
    $destResults = [];
    $currentCursor = $cursor;

    do {
        if ($pollCount > 0) {
            sleep($searchPollInterval);
        }
        $pollCount++;

        // Try cursor-based polling (API returns cursor from search init)
        $pollResponse = apiGet($apiBaseUrl, $apiKey, '/api/v1/hotels/results', [
            'cursor' => $currentCursor,
        ]);

        if ($pollResponse === null) {
            echo "    Poll #{$pollCount}: [ERROR] null response\n";
            break;
        }

        // Debug: dump response structure on first poll
        if ($pollCount === 1) {
            echo "    Response top-level keys: " . implode(', ', array_keys($pollResponse)) . "\n";
            if (isset($pollResponse['meta'])) {
                echo "    meta: " . json_encode($pollResponse['meta']) . "\n";
            }
            // Show first 500 chars of raw response for debugging
            $debugJson = json_encode($pollResponse);
            echo "    Raw (first 500): " . substr($debugJson, 0, 500) . "\n";
        }

        $results = $pollResponse['results'] ?? $pollResponse['data'] ?? [];
        $status = $pollResponse['status'] ?? $pollResponse['meta']['status'] ?? 'unknown';
        $nextCursor = $pollResponse['next_cursor'] ?? $pollResponse['cursor'] ?? $pollResponse['meta']['cursor'] ?? null;

        echo "    Poll #{$pollCount}: " . count($results) . " results, status={$status}\n";

        if (!empty($results) && $pollCount === 1) {
            echo "    Sample result keys: " . implode(', ', array_keys($results[0])) . "\n";

            // Deep search first result for board/meal fields
            $sampleBoardKeys = findBoardKeys($results[0]);
            echo "    Board/meal fields in first result:\n";
            if (!empty($sampleBoardKeys)) {
                foreach ($sampleBoardKeys as $k => $v) {
                    echo "      {$k} = " . substr($v, 0, 80) . "\n";
                }
            } else {
                echo "      NONE\n";
            }

            // Dump first result
            echo "\n    First result dump:\n";
            foreach ($results[0] as $k => $v) {
                if (is_array($v)) {
                    $jsonV = json_encode($v);
                    echo "      {$k}: " . substr($jsonV, 0, 120) . (strlen($jsonV) > 120 ? '...' : '') . "\n";
                } else {
                    echo "      {$k}: " . substr((string)$v, 0, 120) . "\n";
                }
            }
            echo "\n";
        }

        foreach ($results as $r) {
            $destResults[] = $r;
        }

        if ($status === 'completed' || $status === 'done') break;

        // If we got results, keep going; if no cursor and not pending, stop
        if ($nextCursor !== null) {
            $currentCursor = $nextCursor;
        } elseif (empty($results) && $status !== 'pending' && $status !== 'in_progress' && $status !== 'unknown') {
            break;
        }
        // For 'unknown' status, keep polling with same cursor (results may not be ready yet)
    } while ($pollCount < $searchMaxPolls);

    echo "    Total offers for {$region['name']}: " . count($destResults) . "\n";

    // Analyze
    foreach ($destResults as $r) {
        $hotelId = $r['hotel_id'] ?? $r['id'] ?? '';
        if ($hotelId) {
            $searchHotels[$hotelId] = $r['hotel_name'] ?? $r['name'] ?? $hotelId;
        }

        $searchTotalOffers++;

        // Check all possible board/meal field names
        $mealFields = ['meal_name', 'our_meal_name', 'meal_type_name', 'meal_type_category_id',
                       'board_name', 'board_type', 'board_code'];
        foreach ($mealFields as $field) {
            if (isset($r[$field]) && $r[$field] !== '' && $r[$field] !== null) {
                $searchBoardFields[$field] = ($searchBoardFields[$field] ?? 0) + 1;
            }
        }

        // Collect meal values
        $mealVal = $r['meal_type_name'] ?? $r['meal_name'] ?? $r['board_name'] ?? $r['board_type'] ?? '';
        if ($mealVal !== '') {
            $searchMealDist[$mealVal] = ($searchMealDist[$mealVal] ?? 0) + 1;
            if ($hotelId) $searchHotelsWithMeal[$hotelId] = true;
        }

        $searchOffers[] = $r;
    }

    usleep(500000);
}

echo "\n  Search Results Summary:\n";
echo "    Total offers: {$searchTotalOffers}\n";
echo "    Unique hotels: " . count($searchHotels) . "\n";
echo "    Hotels WITH meal data: " . count($searchHotelsWithMeal) . "\n";
$pct = count($searchHotels) > 0 ? round(count($searchHotelsWithMeal) / count($searchHotels) * 100) : 0;
echo "    Meal coverage: {$pct}%\n";

if (!empty($searchBoardFields)) {
    echo "    Board/meal field presence:\n";
    foreach ($searchBoardFields as $field => $count) {
        $pct2 = $searchTotalOffers > 0 ? round($count / $searchTotalOffers * 100) : 0;
        echo "      {$field}: {$count}/{$searchTotalOffers} ({$pct2}%)\n";
    }
}

echo "    Unique meal values: " . count($searchMealDist) . "\n";
if (!empty($searchMealDist)) {
    arsort($searchMealDist);
    echo "    Meal distribution:\n";
    foreach ($searchMealDist as $meal => $count) {
        echo "      \"{$meal}\": {$count} offers\n";
    }
}

echo "\n";

// ── PHASE 5: Verify Endpoint ──
echo "── Phase 5: Verify (/api/v1/hotels/verify) ──\n";

$verifyBoardFields = [];

// Pick offers to verify
$offersToVerify = [];
foreach ($searchOffers as $offer) {
    $offerId = $offer['offer_id'] ?? '';
    if ($offerId !== '' && count($offersToVerify) < $maxVerifyOffers) {
        $offersToVerify[] = $offer;
    }
}

if (empty($offersToVerify)) {
    echo "  [SKIP] No offers available to verify.\n";
} else {
    echo "  Verifying " . count($offersToVerify) . " offers...\n";

    foreach ($offersToVerify as $i => $offer) {
        $offerId = $offer['offer_id'];
        $hotelName = $offer['hotel_name'] ?? $offer['name'] ?? '?';
        echo "\n  Offer " . ($i + 1) . ": {$hotelName}\n";
        echo "    offer_id: " . substr($offerId, 0, 40) . "...\n";

        $verifyResult = apiGet($apiBaseUrl, $apiKey, '/api/v1/hotels/verify', [
            'offer_id' => $offerId,
        ]);

        if ($verifyResult === null) {
            echo "    [ERROR] Verification failed.\n";
            continue;
        }

        echo "    Response keys: " . implode(', ', array_keys($verifyResult)) . "\n";

        // Check nested structure (may have 'data' wrapper)
        $verifyData = $verifyResult['data'] ?? $verifyResult;

        // Deep search for board fields
        $boardKeysFound = findBoardKeys($verifyData);
        if (!empty($boardKeysFound)) {
            echo "    Board/meal fields:\n";
            foreach ($boardKeysFound as $k => $v) {
                echo "      {$k} = " . substr($v, 0, 80) . "\n";
                $verifyBoardFields[$k] = ($verifyBoardFields[$k] ?? 0) + 1;
            }
        } else {
            echo "    Board/meal fields: NONE\n";
        }

        // Dump key fields
        echo "    Key fields:\n";
        foreach (['meal_type_name', 'meal_name', 'our_meal_name', 'board_name', 'board_type', 'board_code'] as $f) {
            if (isset($verifyData[$f])) {
                echo "      {$f} = {$verifyData[$f]}\n";
            }
        }

        usleep(500000);
    }
}

echo "\n  Verify Summary:\n";
echo "    Offers verified: " . count($offersToVerify) . "\n";
if (!empty($verifyBoardFields)) {
    echo "    Board/meal fields found across verified offers:\n";
    foreach ($verifyBoardFields as $field => $count) {
        echo "      {$field}: {$count}/" . count($offersToVerify) . " offers\n";
    }
} else {
    echo "    Board/meal fields: NONE\n";
}

echo "\n";

// ══════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════
echo "══════════════════════════════════════════════════════════════════════\n";
echo "  SUMMARY COMPARISON\n";
echo "══════════════════════════════════════════════════════════════════════\n\n";

// Cache meal field name
$cacheMealField = !empty($cacheMealDist) ? 'meal_name' : 'NONE';
$cacheOurField = !empty($cacheOurMealDist) ? ', our_meal_name' : '';

// Search meal fields
$searchFieldNames = !empty($searchBoardFields) ? implode(', ', array_keys($searchBoardFields)) : 'NONE';

// Verify fields
$verifyFieldNames = !empty($verifyBoardFields) ? implode(', ', array_keys($verifyBoardFields)) : 'NONE';

$rows = [
    ['Static hotels',  (string)$staticHotelCount, !empty($staticBoardFields) ? implode(', ', array_keys($staticBoardFields)) : 'NONE', (string)count($staticBoardFields), !empty($staticBoardFields) ? '?' : '0%'],
    ['Cache hotels',   (string)count($cacheHotels), $cacheMealField . $cacheOurField, (string)count($cacheMealDist), (count($cacheHotels) > 0 ? round(count($cacheHotelsWithMeal) / count($cacheHotels) * 100) : 0) . '%'],
    ['Search results', (string)count($searchHotels), $searchFieldNames, (string)count($searchMealDist), (count($searchHotels) > 0 ? round(count($searchHotelsWithMeal) / count($searchHotels) * 100) : 0) . '%'],
    ['Verify',         (string)count($offersToVerify), $verifyFieldNames, '-', !empty($verifyBoardFields) ? '100%' : '0%'],
];

echo sprintf("%-20s | %-8s | %-35s | %-8s | %s\n", 'Endpoint', 'Hotels', 'Board/Meal Field(s)', 'Uniq Val', 'Coverage');
echo str_repeat('-', 95) . "\n";
foreach ($rows as $row) {
    echo sprintf("%-20s | %-8s | %-35s | %-8s | %s\n", $row[0], $row[1], $row[2], $row[3], $row[4]);
}

echo "\n";

// Key field name mapping
echo "  KEY FINDING — Field Name Mapping:\n";
echo "    Cache endpoint uses:  meal_name, our_meal_name\n";
echo "    Search endpoint uses: (check results above)\n";
echo "    Verify endpoint uses: (check results above)\n";
echo "    CacheEndpointService.php maps: board_name ← (board_name ?? board_type)\n";
echo "    add_to_cart.php maps: board_name ← (board_name ?? board_type)\n";

echo "\n══════════════════════════════════════════════════════════════════════\n";
echo "  Audit completed at " . date('Y-m-d H:i:s') . "\n";
echo "══════════════════════════════════════════════════════════════════════\n";
