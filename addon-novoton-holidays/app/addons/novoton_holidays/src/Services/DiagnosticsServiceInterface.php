<?php

declare(strict_types=1);

/**
 * Diagnostics Service Interface
 *
 * Contract for API diagnostic and testing operations.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;

interface DiagnosticsServiceInterface
{
    /**
     * Test API connection and credentials.
     *
     * @return array{success: bool, config: non-empty-array<string, mixed>, message: string, hotels_count: int, sample_hotel: array<string, mixed>|null, error: string, last_request?: mixed, last_http_code?: int|null, raw_response_preview?: string}
     */
    public function testApiConnection(): array;

    /**
     * Test hotel list API call.
     *
     * @param string $country Country name
     * @param int $limit Max hotels to return
     * @return array{success: bool, total: int, hotels: list<array<string, mixed>>, error: string}
     */
    public function testHotelList(string $country = Constants::DEFAULT_COUNTRY, int $limit = 10): array;

    /**
     * Test room price API call.
     *
     * @param array<string, mixed> $params {hotel_id, room_id, board_id, check_in, check_out, adults}
     * @return array{success: bool, result: mixed, params: array<string, mixed>, price: float, price_with_commission: float, raw_response: string, error: string}
     */
    public function testRoomPrice(array $params): array;

    /**
     * Test availability search API call.
     *
     * @param array<string, mixed> $params {hotel_id?, check_in, check_out, adults, children}
     * @return array{success: bool, results: list<array<string, mixed>>, count: int, error: string}
     */
    public function testSearch(array $params): array;

    /**
     * Test facilities sync.
     *
     * @return array{success: bool, result: array<string, mixed>, facilities: list<array<string, mixed>>, error: string}
     */
    public function testFacilities(): array;

    /**
     * Test single product data retrieval.
     *
     * @param string $productCode Product code (e.g. NVT1603)
     * @return array{success: bool, product: array<string, mixed>|null, hotel_id: string, hotel_info: mixed, packages_db: list<array<string, mixed>>, error: string}
     */
    public function testProduct(string $productCode): array;
}
