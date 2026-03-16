<?php
declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays;

use Tygh\Addons\SphinxHolidays\Api\SphinxHttpClient;

/**
 * Sphinx API facade.
 *
 * Provides a clean interface to all Sphinx API endpoints.
 * Mirrors the Novoton NovotonApi pattern but for REST/JSON.
 */
class SphinxApi
{
    private SphinxHttpClient $client;

    public function __construct(SphinxHttpClient $client)
    {
        $this->client = $client;
    }

    // ── Connectivity ──

    /**
     * Test API connectivity (no auth required).
     */
    public function ping(): ?array
    {
        return $this->client->get('/api/v1/ping');
    }

    /**
     * Get authenticated user profile.
     */
    public function me(): ?array
    {
        return $this->client->get('/api/v1/me');
    }

    // ── Static Data ──

    /**
     * Get all destinations (paginated).
     *
     * @param string|null $updatedSince Only return destinations updated since this ISO 8601 datetime
     */
    public function getDestinations(int $page = 1, int $perPage = 1000, ?string $updatedSince = null): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        return $this->client->get('/api/v1/static/destinations', $query);
    }

    /**
     * Get a single destination by ID.
     */
    public function getDestination(int $id): ?array
    {
        return $this->client->get("/api/v1/static/destinations/{$id}");
    }

    /**
     * Get all hotels (paginated).
     *
     * @param string|null $updatedSince Only return hotels updated since this ISO 8601 datetime
     * @param int[] $destinationIds Only return hotels belonging to these destination IDs
     */
    public function getHotels(int $page = 1, int $perPage = 1000, ?string $updatedSince = null, array $destinationIds = []): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        if (!empty($destinationIds)) {
            $query['destination_ids'] = $destinationIds;
        }
        return $this->client->get('/api/v1/static/hotels', $query);
    }

    /**
     * Get a single hotel by ID.
     */
    public function getHotel(string $id): ?array
    {
        return $this->client->get("/api/v1/static/hotels/{$id}");
    }

    /**
     * Get all package routes.
     */
    public function getPackageRoutes(int $page = 1, int $perPage = 1000): ?array
    {
        return $this->client->get('/api/v1/static/package-routes', [
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Get all circuits (paginated).
     */
    public function getCircuits(int $page = 1, int $perPage = 1000): ?array
    {
        return $this->client->get('/api/v1/static/circuits', [
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Get all experiences (paginated).
     */
    public function getExperiences(int $page = 1, int $perPage = 1000): ?array
    {
        return $this->client->get('/api/v1/static/experiences', [
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    // ── Hotel Search & Booking ──

    /**
     * Initiate a hotel search.
     *
     * @param array $params {destination_id, check_in, check_out, occupancy, currency, ...}
     * @return array|null {search_id, ...}
     */
    public function searchHotels(array $params): ?array
    {
        return $this->client->post('/api/v1/hotels/search', $params);
    }

    /**
     * Get hotel search results (cursor-based polling).
     *
     * @param string $searchId Search ID from searchHotels()
     * @param string|null $cursor Cursor for pagination
     */
    public function getHotelResults(string $searchId, ?string $cursor = null): ?array
    {
        $query = ['search_id' => $searchId];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        return $this->client->get('/api/v1/hotels/results', $query);
    }

    /**
     * Verify a hotel offer before booking.
     *
     * @param string $offerId Offer ID from search results
     */
    public function verifyHotelOffer(string $offerId): ?array
    {
        return $this->client->get('/api/v1/hotels/verify', ['offer_id' => $offerId]);
    }

    /**
     * Book a hotel offer.
     *
     * NOTE: When building cart integration, set both 'travel_booking' => true
     * and 'sphinx_booking' => true in the cart product extras. The travel_booking
     * flag enables travel_core shared hooks (rooms_data decode, display formatting).
     *
     * @param array $bookingData {offer_id, guests, contact, ...}
     */
    public function bookHotel(array $bookingData): ?array
    {
        return $this->client->post('/api/v1/hotels/book', $bookingData);
    }

    // ── Package Search & Booking ──

    /**
     * Initiate a package search.
     *
     * @param array $params {departure_id, destination_id, date, nights, occupancy, ...}
     */
    public function searchPackages(array $params): ?array
    {
        return $this->client->post('/api/v1/packages/search', $params);
    }

    /**
     * Get package search results (cursor-based polling).
     */
    public function getPackageResults(string $searchId, ?string $cursor = null): ?array
    {
        $query = ['search_id' => $searchId];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        return $this->client->get('/api/v1/packages/results', $query);
    }

    /**
     * Verify a package offer.
     */
    public function verifyPackageOffer(string $offerId): ?array
    {
        return $this->client->get('/api/v1/packages/verify', ['offer_id' => $offerId]);
    }

    /**
     * Customize a package (add optional services).
     */
    public function customizePackage(array $data): ?array
    {
        return $this->client->post('/api/v1/packages/customize', $data);
    }

    /**
     * Book a package.
     */
    public function bookPackage(array $bookingData): ?array
    {
        return $this->client->post('/api/v1/packages/book', $bookingData);
    }

    // ── Orders ──

    /**
     * Get all orders (paginated, with optional filters).
     *
     * @param int $page Page number
     * @param int $perPage Items per page (1-50)
     * @param array $filters Optional filters: reference_code, type, created_after
     */
    public function getOrders(int $page = 1, int $perPage = 50, array $filters = []): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if (!empty($filters['reference_code'])) {
            $query['reference_code'] = $filters['reference_code'];
        }
        if (!empty($filters['type'])) {
            $query['type'] = $filters['type'];
        }
        if (!empty($filters['created_after'])) {
            $query['created_after'] = $filters['created_after'];
        }
        return $this->client->get('/api/v1/orders', $query);
    }

    /**
     * Get a single order by ID.
     */
    public function getOrder(string $orderId): ?array
    {
        return $this->client->get("/api/v1/orders/{$orderId}");
    }

    // ── Cache ──

    /**
     * Pre-cache hotel data.
     */
    public function cacheHotels(array $params): ?array
    {
        return $this->client->post('/api/v1/cache/hotels', $params);
    }

    /**
     * Pre-cache package data.
     */
    public function cachePackages(array $params): ?array
    {
        return $this->client->post('/api/v1/cache/packages', $params);
    }

    /**
     * Get the underlying HTTP client (for diagnostics).
     */
    public function getHttpClient(): SphinxHttpClient
    {
        return $this->client;
    }
}
