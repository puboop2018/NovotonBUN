<?php
declare(strict_types=1);
/**
 * Novoton API Interface
 *
 * Contract for the API facade that coordinates domain-specific API clients.
 * Covers hotels, pricing, availability, reservations, and destinations.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\HotelApiClient;
use Tygh\Addons\NovotonHolidays\Api\PricingApiClient;
use Tygh\Addons\NovotonHolidays\Api\AvailabilityApiClient;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\Api\DestinationApiClient;

interface NovotonApiInterface
{
    // ── Domain client accessors ──

    public function hotels(): HotelApiClient;
    public function pricing(): PricingApiClient;
    public function availability(): AvailabilityApiClient;
    public function reservations(): ReservationApiClient;
    public function destinations(): DestinationApiClient;

    // ── Hotels ──

    public function getHotelList(string $country = '%', string $city = '%', string $hotel = '%', string $hotelType = '%'): \SimpleXMLElement;
    public function getHotelInfo(string $hotelId, string $lang = 'UK'): \SimpleXMLElement;
    public function getHotelInfoBatch(array $hotelIds, string $lang = 'UK', int $concurrency = 5): array;
    public function getHotelDescription(string $hotelId, string $lang = 'UK', bool $includePackage = false): \SimpleXMLElement;
    public function getHotelImages(string $hotelId, string $lang = 'UK'): \SimpleXMLElement;
    public function getHotelFacilities(string $hotelId): \SimpleXMLElement;
    public function listFacilities(): \SimpleXMLElement;

    // ── Pricing ──

    public function applyCommission(float $price): float;
    public function getRoomPrice(array $params): \SimpleXMLElement|false;
    public function getRoomPriceByResort(array $params): \SimpleXMLElement;
    public function getRoomPriceByResortRaw(array $params): string;
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): \SimpleXMLElement;
    public function getSpecialOffers(string $hotelId, string $packageName = '', string $lang = 'UK'): \SimpleXMLElement;

    // ── Availability ──

    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array;
    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): \SimpleXMLElement;
    public function getHotelQuotaAdditional(string $hotelId, string $roomId, string $checkIn, string $checkOut): \SimpleXMLElement;
    public function searchAvailability(array $params): array;

    // ── Reservations ──

    public function createReservation(array $bookingData): \SimpleXMLElement;
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false): \SimpleXMLElement|array;
    public function generateHotelRequestXml(array $requestData): string;
    public function getAlternatives(string $idNum, string $lang = 'UK'): \SimpleXMLElement;
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK'): \SimpleXMLElement;
    public function getInvoiceHtml(string $idNum, string $lang = 'UK'): string;
    public function getInvoiceXml(string $idNum, string $lang = 'UK'): \SimpleXMLElement;
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK'): \SimpleXMLElement;

    // ── Destinations ──

    public function getResortList(string $country = '', string $lang = 'UK'): \SimpleXMLElement;
    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = ''): \SimpleXMLElement;
    public function getKickbackInfo(string $lang = 'UK'): \SimpleXMLElement;

    // ── Debug ──

    public function getLastRequest(): string;
    public function getLastResponse(): string;
    public function getLastRequestFormatted(): array;
    public function getLastError(): string;
    public function getLastResponseRaw(): string;
    public function getLastHttpCode(): int;

    // ── Circuit breaker ──

    public function getCircuitStatus(): array;
    public function resetCircuitBreaker(): void;

    // ── Cache ──

    public function clearCache(?string $function = null): int;
}
