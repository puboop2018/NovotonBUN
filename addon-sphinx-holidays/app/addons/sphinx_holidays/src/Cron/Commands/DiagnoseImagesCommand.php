<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Api\ImageHelper;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\DebugLogger;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: diagnose image download for a specific hotel.
 *
 * Fetches the hotel from DB + Sphinx API, shows the images array,
 * then attempts to download each URL and reports the result.
 * Safe to run at any time — it does NOT attach images to the product.
 *
 * Usage:
 *   cron_mode=diagnose_images&hotel_id=99224
 *   cron_mode=diagnose_images&hotel_id=99224&attach=Y   — also attach on success
 */
class DiagnoseImagesCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getDescription(): string
    {
        return 'Diagnose image URLs and download for a single hotel (read-only unless &attach=Y)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        $doAttach = TypeCoerce::toString($params['attach'] ?? '') === 'Y';

        if ($hotelId === '') {
            $this->output('ERROR: &hotel_id=<id> is required. Example: &cron_mode=diagnose_images&hotel_id=99224');
            return ['success' => false, 'error' => 'hotel_id required'];
        }

        $this->output("=== Diagnosing hotel [{$hotelId}] ===");
        $this->output('Mode: ' . ($doAttach ? 'DIAGNOSE + ATTACH' : 'DIAGNOSE ONLY (add &attach=Y to also attach images)'));

        // ── 1. DB record ──────────────────────────────────────────────
        $hotel = Container::getHotelRepository()->getById($hotelId);

        if ($hotel === null) {
            $this->output("ERROR: Hotel [{$hotelId}] not found in sphinx_hotels table.");
            return ['success' => false, 'error' => 'hotel not found'];
        }

        $hotelName = TypeCoerce::toString($hotel['name'] ?? '');
        $productId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
        $imageUrl = TypeCoerce::toString($hotel['image_url'] ?? '');
        $imagesJson = TypeCoerce::toString($hotel['images_json'] ?? '');

        $this->output("DB: name={$hotelName}, product_id={$productId}");
        $this->output('DB: image_url=' . ($imageUrl ?: '(empty)'));
        $this->output('DB: images_json=' . (strlen($imagesJson) > 200 ? substr($imagesJson, 0, 200) . '…' : ($imagesJson ?: '(empty)')));

        // ── 2. Parse DB images ────────────────────────────────────────
        /** @var mixed $decoded */
        $decoded = json_decode($imagesJson, true);
        $images = is_array($decoded) ? $decoded : [];
        $this->output('DB images count: ' . count($images));

        // ── 3. API fallback ───────────────────────────────────────────
        $this->output('');
        $this->output('--- Fetching from API: /api/v1/static/hotels/' . $hotelId . ' ---');

        $api = Container::getApi();
        $fresh = $api->getHotel($hotelId);

        if ($fresh === null) {
            $httpCode = $api->getHttpClient()->getLastHttpCode();
            $apiError = $api->getHttpClient()->getLastError();
            $this->output("API: FAILED — HTTP {$httpCode}: {$apiError}");
        } else {
            $topKeys = implode(', ', array_keys($fresh));
            $this->output("API: OK — top-level keys: {$topKeys}");

            // Unwrap {"data": {...}} envelope if present
            /** @var mixed $dataEnvelope */
            $dataEnvelope = $fresh['data'] ?? null;
            $hotelData = is_array($dataEnvelope) ? $dataEnvelope : $fresh;

            /** @var mixed $rawImages */
            $rawImages = $hotelData['images'] ?? null;

            if (is_array($rawImages) && count($rawImages) > 0) {
                $this->output('API images count: ' . count($rawImages));
                if (empty($images)) {
                    $images = $rawImages;
                    $this->output('Using API images (DB was empty).');
                }
            } else {
                $dataKeys = implode(', ', array_keys($hotelData));
                $this->output("API: no images key found. hotelData keys: {$dataKeys}");
            }
        }

        // ── 4. Fallback to single image_url ───────────────────────────
        if (empty($images) && $imageUrl !== '') {
            $this->output('Falling back to single image_url from DB.');
            $images = [['url' => $imageUrl]];
        }

        if (empty($images)) {
            $this->output('');
            $this->output('RESULT: No images available from DB or API. Nothing to diagnose.');
            return ['success' => true, 'images_found' => 0];
        }

        // ── 5. Probe each image URL ───────────────────────────────────
        $apiBaseUrl = ConfigProvider::getApiBaseUrl();
        $this->output('');
        $this->output("API base URL (for host matching): {$apiBaseUrl}");
        $this->output('--- Probing ' . count($images) . ' image URL(s) ---');

        $passed = 0;
        $failed = 0;

        foreach ($images as $i => $img) {
            if (is_array($img)) {
                $url = TypeCoerce::toString($img['url'] ?? '');
            } elseif (is_string($img)) {
                $url = $img;
            } else {
                $url = '';
            }

            if ($url === '') {
                $this->output("[img #{$i}] SKIP — empty URL");
                continue;
            }

            $isApiHosted = ImageHelper::matchesApiHost($url, $apiBaseUrl);
            $downloadUrl = $isApiHosted ? ImageHelper::withoutWatermark($url) : $url;
            $headers = $isApiHosted ? ImageHelper::getCurlAuthHeaders() : [];

            $this->output('');
            // Print the full URL — a previous substr($url, 0, 120) truncated long CDN
            // URLs mid-extension (".jpg" → ".j"), which looked like a broken/stored URL
            // even though the value in the DB and the actual HTTP request were intact.
            $this->output("[img #{$i}] URL: " . $url);
            $this->output("[img #{$i}] API-hosted: " . ($isApiHosted ? 'YES (auth headers sent)' : 'NO (public CDN)'));

            // Download to temp file
            /** @var mixed $rawTempFile */
            $rawTempFile = fn_create_temp_file();
            $tempFile = is_string($rawTempFile) && $rawTempFile !== '' ? $rawTempFile : '';
            if ($tempFile === '') {
                $this->output("[img #{$i}] ERROR: fn_create_temp_file() failed");
                $failed++;
                continue;
            }

            $fp = fopen($tempFile, 'wb');
            if ($fp === false) {
                $this->output("[img #{$i}] ERROR: fopen() failed for temp file");
                $failed++;
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                continue;
            }

            $ch = curl_init($downloadUrl);
            // array_values() ensures integer keys, satisfying curl's CURLOPT_HTTPHEADER type
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_HTTPHEADER => array_values($headers),
                CURLOPT_USERAGENT => 'CS-Cart/SphinxHolidays ImageSync/1.0',
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);

            curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = TypeCoerce::toString(curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            $fileSize = file_exists($tempFile) ? (int) filesize($tempFile) : 0;
            $errSuffix = $curlError !== '' ? ", cURL error: {$curlError}" : '';

            $this->output("[img #{$i}] HTTP: {$httpCode}, Content-Type: {$contentType}, Size: {$fileSize} bytes{$errSuffix}");

            $ok = ($httpCode === 200 && $fileSize >= 1000);
            $this->output("[img #{$i}] Download: " . ($ok ? 'OK' : 'FAIL (need HTTP 200 + size >= 1000 bytes)'));

            if ($ok && $doAttach && $productId > 0) {
                $isMain = ($i === 0);
                DebugLogger::$lastImageAttachPath = '';
                $attached = fn_travel_core_attach_product_image($productId, $tempFile, 'sphinx', $isMain);
                $attachNote = $attached
                    ? 'OK [path: ' . DebugLogger::$lastImageAttachPath . ']'
                    : 'FAILED — ' . DebugLogger::$lastImageAttachError;
                $this->output("[img #{$i}] Attach to product #{$productId}: {$attachNote}");
                if ($attached) {
                    $passed++;
                } else {
                    $failed++;
                }
            } elseif ($ok) {
                $passed++;
            } else {
                $failed++;
            }

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        if (!$doAttach) {
            $this->output('');
            $this->output('NOTE: attach step was NOT tested. Re-run with &attach=Y to confirm images can actually be saved to CS-Cart.');
        }

        $this->output('');
        $this->output("=== Summary: {$passed} OK, {$failed} failed ===");

        return [
            'success' => true,
            'images_found' => count($images),
            'passed' => $passed,
            'failed' => $failed,
        ];
    }
}
