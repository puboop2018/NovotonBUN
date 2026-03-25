<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Services\CommissionCalculator;

/**
 * Service layer wrapping Sphinx cache API endpoints.
 *
 * Fetches hotel/package deals from the Sphinx cache endpoints,
 * applies commission markup, and stores results in local DB cache
 * for frontend widget display.
 */
class CacheEndpointService
{
    private SphinxApi $api;
    private float $commission;

    /** Cache TTL for deals in seconds (default 4 hours) */
    private const DEALS_CACHE_TTL = 14400;

    public function __construct(SphinxApi $api, float $commission = 0)
    {
        $this->api = $api;
        $this->commission = $commission;
    }

    /**
     * Get hotel deals with commission applied.
     *
     * @param array $filters {destination_id?: int, stars?: int, limit?: int, sort_by?: string}
     * @return array Normalized deal entries with commission-applied prices
     */
    public function getHotelDeals(array $filters = []): array
    {
        $cacheKey = 'deals:hotels:' . md5(json_encode($filters));
        $cached = CacheService::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->api->cacheHotels($filters);
        if (empty($response) || !is_array($response)) {
            return [];
        }

        $deals = $this->normalizeDeals($response['results'] ?? $response['hotels'] ?? []);

        if (!empty($deals)) {
            CacheService::set($cacheKey, $deals, self::DEALS_CACHE_TTL);
        }

        return $deals;
    }

    /**
     * Get package deals with commission applied.
     *
     * @param array $filters {destination_id?: int, type?: string, limit?: int}
     * @return array Normalized deal entries with commission-applied prices
     */
    public function getPackageDeals(array $filters = []): array
    {
        $cacheKey = 'deals:packages:' . md5(json_encode($filters));
        $cached = CacheService::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->api->cachePackages($filters);
        if (empty($response) || !is_array($response)) {
            return [];
        }

        $deals = $this->normalizeDeals($response['results'] ?? $response['packages'] ?? []);

        if (!empty($deals)) {
            CacheService::set($cacheKey, $deals, self::DEALS_CACHE_TTL);
        }

        return $deals;
    }

    /**
     * Refresh all cached deals (called by cron).
     *
     * @return array Stats: {hotels_count, packages_count, errors}
     */
    public function refreshAll(): array
    {
        $stats = ['hotels_count' => 0, 'packages_count' => 0, 'errors' => 0];

        try {
            $hotels = $this->getHotelDeals(['limit' => 50]);
            $stats['hotels_count'] = count($hotels);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'CacheEndpointService::refreshAll hotels failed: ' . $e->getMessage(),
            ]);
            $stats['errors']++;
        }

        try {
            $packages = $this->getPackageDeals(['limit' => 50]);
            $stats['packages_count'] = count($packages);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'CacheEndpointService::refreshAll packages failed: ' . $e->getMessage(),
            ]);
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * Normalize and apply commission to deal entries.
     */
    private function normalizeDeals(array $items): array
    {
        $calculator = null;
        if ($this->commission > 0) {
            $roundPrices = ConfigProvider::shouldRoundPrices() ? 'Y' : 'N';
            $calculator = new CommissionCalculator($this->commission, $roundPrices);
        }

        $deals = [];
        foreach ($items as $item) {
            $price = (float)($item['price'] ?? 0);
            if ($price <= 0) continue;

            $deal = [
                'hotel_id' => $item['hotel_id'] ?? '',
                'hotel_name' => $item['hotel_name'] ?? '',
                'destination' => $item['destination'] ?? $item['destination_name'] ?? '',
                'star_rating' => (int)($item['star_rating'] ?? $item['stars'] ?? 0),
                'image' => $item['image'] ?? $item['hotel_image'] ?? '',
                'check_in' => $item['check_in'] ?? '',
                'check_out' => $item['check_out'] ?? '',
                'nights' => (int)($item['nights'] ?? 0),
                'room_name' => $item['room_name'] ?? $item['room_type'] ?? '',
                'board_name' => $item['board_name'] ?? $item['board_type'] ?? '',
                'original_price' => $price,
                'price' => $calculator ? $calculator->apply($price) : $price,
                'currency' => $item['currency'] ?? ConfigProvider::getDefaultCurrency(),
                'offer_id' => $item['offer_id'] ?? '',
            ];
            $deals[] = $deal;
        }

        return $deals;
    }
}
