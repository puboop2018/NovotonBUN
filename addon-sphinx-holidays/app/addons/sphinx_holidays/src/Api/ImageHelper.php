<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Helper for Sphinx image URL handling.
 *
 * The Sphinx API provides watermark-free images when authenticated with
 * X-Copyright-Authorization header and watermark=0 query param.
 */
class ImageHelper
{
    /**
     * Last download error message, populated by fn_sphinx_holidays_add_product_image()
     * so cron callers can surface the HTTP code in console output without inspecting
     * the CS-Cart event log. Reset to '' at the start of every download attempt.
     */
    public static string $lastDownloadError = '';

    /**
     * Build an image URL with watermark disabled.
     *
     * The resulting URL still requires the X-Copyright-Authorization header
     * to be sent by the HTTP client — this method only adds the query param.
     */
    public static function withoutWatermark(string $imageUrl): string
    {
        if ($imageUrl === '') {
            return '';
        }
        $separator = str_contains($imageUrl, '?') ? '&' : '?';
        return $imageUrl . $separator . 'watermark=0';
    }

    /**
     * Return true if $imageUrl is hosted on the same server (or a sibling subdomain)
     * as the configured Sphinx API base URL.
     *
     * Sibling subdomain matching handles cases where images are served from
     * e.g. media.sphinx2.example.com while the API is at api.sphinx2.example.com.
     */
    public static function matchesApiHost(string $imageUrl, string $apiBaseUrl): bool
    {
        $apiHost = (string) parse_url($apiBaseUrl, PHP_URL_HOST);
        $imageHost = (string) parse_url($imageUrl, PHP_URL_HOST);
        if ($apiHost === '' || $imageHost === '') {
            return false;
        }
        if ($imageHost === $apiHost) {
            return true;
        }
        // Compare base domain after stripping the first subdomain component.
        $apiBase = implode('.', array_slice(explode('.', $apiHost), 1));
        $imageBase = implode('.', array_slice(explode('.', $imageHost), 1));
        return $apiBase !== '' && $apiBase === $imageBase;
    }

    /**
     * Get the authentication headers required for watermark-free image access.
     *
     * @return array<string, string> HTTP headers as key => value
     */
    public static function getAuthHeaders(): array
    {
        $apiKey = ConfigProvider::getApiKey();
        if ($apiKey === '') {
            return [];
        }
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'X-Copyright-Authorization' => 'Bearer ' . $apiKey,
        ];
    }

    /**
     * Get the cURL-formatted authentication headers for API-hosted image access.
     *
     * Sends both the standard Bearer token (required for auth) and the
     * copyright header (required for watermark-free delivery).
     *
     * @return list<string> Headers in "Name: Value" format for cURL
     */
    public static function getCurlAuthHeaders(): array
    {
        $apiKey = ConfigProvider::getApiKey();
        if ($apiKey === '') {
            return [];
        }
        return [
            'Authorization: Bearer ' . $apiKey,
            'X-Copyright-Authorization: Bearer ' . $apiKey,
        ];
    }

    /**
     * Download an external image URL and attach it to a CS-Cart product
     * (body of the fn_sphinx_holidays_add_product_image shell).
     *
     * Uses CS-Cart's image pipeline (via the travel_core attach helpers) to
     * generate thumbnails and store the image in the standard product
     * gallery. Only API-hosted images need auth headers + watermark removal
     * via cURL; CDN images are public and delegate to the URL approach.
     *
     * On failure, the short reason is exposed via self::$lastDownloadError
     * so cron callers can echo it without inspecting the CS-Cart event log.
     *
     * @param int $product_id CS-Cart product ID
     * @param string $image_url External image URL to download
     * @param bool $is_main True for main product image, false for additional
     * @return bool True on success
     */
    public static function attachToProduct(int $product_id, string $image_url, bool $is_main = false): bool
    {
        self::$lastDownloadError = '';
        if (empty($product_id) || empty($image_url)) {
            self::$lastDownloadError = 'empty args';
            return false;
        }

        $temp_file = fn_create_temp_file();
        $temp_file = is_string($temp_file) ? $temp_file : '';
        if ($temp_file === '') {
            self::$lastDownloadError = 'temp file failed';
            fn_log_event('general', 'runtime', ['message' => "Sphinx: fn_create_temp_file() returned empty for product #{$product_id}"]);
            return false;
        }

        $isApiHosted = self::matchesApiHost($image_url, ConfigProvider::getApiBaseUrl());

        if (!$isApiHosted) {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            return fn_travel_core_attach_images_from_urls($product_id, [$image_url], $is_main) > 0;
        }

        // API-hosted: must strip watermark param and send Bearer auth headers.
        // CS-Cart's URL-fetch path cannot carry custom headers, so cURL is required.
        $download_url = self::withoutWatermark($image_url);
        $headers = self::getCurlAuthHeaders();

        $fp = fopen($temp_file, 'wb');
        if ($fp === false) {
            self::$lastDownloadError = 'fopen failed';
            fn_log_event('general', 'runtime', ['message' => "Sphinx: fopen() failed for temp file '{$temp_file}' product #{$product_id}"]);
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            return false;
        }

        $ch = curl_init($download_url);
        if ($ch === false) {
            self::$lastDownloadError = 'curl init failed';
            fclose($fp);
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'CS-Cart/SphinxHolidays ImageSync/1.0',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode !== 200 || !file_exists($temp_file) || filesize($temp_file) < 1000) {
            self::$lastDownloadError = "HTTP {$httpCode}" . ($curlError !== '' ? " ({$curlError})" : '');
            fn_log_event('general', 'runtime', ['message' => "Sphinx: image download failed for product #{$product_id}: HTTP {$httpCode}, size=" . (file_exists($temp_file) ? filesize($temp_file) : 'N/A') . ", url={$download_url}" . ($curlError !== '' ? " ({$curlError})" : '')]);
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            return false;
        }

        return fn_travel_core_attach_product_image($product_id, $temp_file, 'sphinx', $is_main);
    }
}
