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
     * Get the authentication headers required for watermark-free image access.
     *
     * @return array HTTP headers as key => value
     */
    public static function getAuthHeaders(): array
    {
        $apiKey = ConfigProvider::getApiKey();
        if ($apiKey === '') {
            return [];
        }
        return [
            'X-Copyright-Authorization' => 'Bearer ' . $apiKey,
        ];
    }

    /**
     * Get the cURL-formatted authentication header for watermark-free image access.
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
            'X-Copyright-Authorization: Bearer ' . $apiKey,
        ];
    }
}
