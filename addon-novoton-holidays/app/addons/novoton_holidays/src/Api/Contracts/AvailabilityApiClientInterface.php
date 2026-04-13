<?php
declare(strict_types=1);
/**
 * Contract for the Novoton Availability API sub-client.
 *
 * Covers hotel quota (free allotment) and search (frmsearch) endpoints.
 *
 * NOTE on commission: `searchAvailability()` / `searchAvailabilityBatch()` return
 * results with commission **already applied** to `total_price` and `price_per_night`.
 * Callers must NOT call `applyCommission()` on those prices a second time.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Api\Contracts;

interface AvailabilityApiClientInterface
{
    /**
     * 4. hotel_quota — Free allotments for all rooms.
     *
     * @return array<string, string> room_id => quota value (numeric string or "RQ")
     */
    public function getHotelQuotaAll(string $hotelId, string $checkIn, string $checkOut): array;

    /** 4. hotel_quota — Free allotments for a single room. */
    public function getHotelQuota(string $hotelId, string $roomId, string $checkIn, string $checkOut, string $roomType = ''): \SimpleXMLElement;

    /** 21. hotel_quota_add — Additional allotments. */
    public function getHotelQuotaAdditional(string $hotelId, string $roomId, string $checkIn, string $checkOut): \SimpleXMLElement;

    /**
     * Search availability using the frmsearch endpoint.
     *
     * @return list<array<string, mixed>> List of offers with commission already applied.
     * @param array<string, mixed> $params
     */
    public function searchAvailability(array $params): array;

    /**
     * Batch availability search using curl_multi.
     *
     * @param array<string, array<string, mixed>> $paramsList Keyed array: key => search params
     * @return array<string, list<array<string, mixed>>> key => parsed search results (commission already applied)
     */
    public function searchAvailabilityBatch(array $paramsList, int $concurrency = 5): array;
}
