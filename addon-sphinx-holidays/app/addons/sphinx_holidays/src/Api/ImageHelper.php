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
     * @return string[] Headers in "Name: Value" format for cURL
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
}
