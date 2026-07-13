<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Exceptions\GeocodeTransportException;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Minimal OSM Nominatim reverse-geocoding client.
 *
 * Turns stored hotel coordinates into an approximate street line for the
 * PDP location display — the Novoton API carries no street field. Uses the
 * Nominatim /reverse endpoint (jsonv2). Callers MUST pace requests to max
 * 1/second and identify themselves (User-Agent with contact) per the usage
 * policy: https://operations.osmfoundation.org/policies/nominatim/
 * Results are ODbL-licensed: storing them is permitted with OpenStreetMap
 * attribution ("© OpenStreetMap contributors" somewhere on the site).
 */
final class NominatimClient
{
    public const string DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org';

    private const int TIMEOUT_SECONDS = 15;

    /**
     * HTTP GET transport, injectable for tests.
     * Receives (url, userAgent), returns [httpCode, body]; throws
     * GeocodeTransportException on connection-level failure.
     *
     * @var callable(string, string): array{int, string}
     */
    private $transport;

    private string $endpoint;
    private string $userAgent;

    /**
     * @param callable(string, string): array{int, string}|null $transport
     */
    public function __construct(?callable $transport = null, string $endpoint = '', string $contactEmail = '')
    {
        $this->transport = $transport ?? static fn (string $url, string $userAgent): array => self::curlGet($url, $userAgent);
        $this->endpoint = rtrim($endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT, '/');
        // Nominatim policy: identify the application and provide a contact.
        $this->userAgent = 'NovotonHolidays/1.0' . ($contactEmail !== '' ? " ({$contactEmail})" : '');
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Reverse-geocode coordinates into an approximate street line
     * ("road" or "road house_number"), or null when the nearest mapped
     * address has nothing street-like.
     *
     * @throws GeocodeTransportException on HTTP/transport failure — the
     *                                   caller should stop its run
     */
    public function reverse(float $lat, float $lng): ?string
    {
        // zoom=18 asks for building/street-level detail; accept-language=ro
        // matches the storefront's primary locale for street names.
        $url = $this->endpoint
            . '/reverse?format=jsonv2&zoom=18&addressdetails=1'
            . '&lat=' . rawurlencode(number_format($lat, 7, '.', ''))
            . '&lon=' . rawurlencode(number_format($lng, 7, '.', ''))
            . '&accept-language=ro';

        [$httpCode, $body] = ($this->transport)($url, $this->userAgent);

        if ($httpCode !== 200) {
            throw new GeocodeTransportException("Nominatim reverse geocoding failed with HTTP {$httpCode}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new GeocodeTransportException('Nominatim returned an unparseable body');
        }

        $address = isset($decoded['address']) && is_array($decoded['address']) ? $decoded['address'] : [];

        return self::extractStreet($address);
    }

    /**
     * @param array<mixed> $address Nominatim "address" object
     */
    private static function extractStreet(array $address): ?string
    {
        $road = self::str($address['road'] ?? null);
        if ($road === '') {
            // The nearest mapped way may be a pedestrian zone or footpath;
            // failing those, a neighbourhood name still localizes the hotel.
            foreach (['pedestrian', 'footway', 'neighbourhood'] as $fallback) {
                $road = self::str($address[$fallback] ?? null);
                if ($road !== '') {
                    break;
                }
            }
        }

        if ($road === '') {
            return null;
        }

        $number = self::str($address['house_number'] ?? null);

        // Romanian postal style puts the number after the road: "Strada X 12".
        $street = $number !== '' ? "{$road} {$number}" : $road;

        return mb_substr($street, 0, 255);
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    /**
     * @return array{int, string}
     */
    private static function curlGet(string $url, string $userAgent): array
    {
        if ($url === '') {
            throw new GeocodeTransportException('Empty geocoding URL');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = TypeCoerce::toInt(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new GeocodeTransportException('Nominatim request failed: ' . $error);
        }

        return [$httpCode, is_string($response) ? $response : ''];
    }
}
