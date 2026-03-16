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
     * Get all package routes (paginated, with optional filters).
     *
     * @param array $departureIds Filter by departure IDs
     * @param array $destinationIds Filter by destination IDs
     * @param string|null $updatedSince Only return routes updated since this ISO 8601 datetime
     */
    public function getPackageRoutes(int $page = 1, int $perPage = 1000, array $departureIds = [], array $destinationIds = [], ?string $updatedSince = null): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if (!empty($departureIds)) {
            $query['departure_ids'] = $departureIds;
        }
        if (!empty($destinationIds)) {
            $query['destination_ids'] = $destinationIds;
        }
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        return $this->client->get('/api/v1/static/package-routes', $query);
    }

    /**
     * Get all circuits (paginated).
     *
     * @param string|null $updatedSince Only return circuits updated since this ISO 8601 datetime
     */
    public function getCircuits(int $page = 1, int $perPage = 1000, ?string $updatedSince = null): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        return $this->client->get('/api/v1/static/circuits', $query);
    }

    /**
     * Get all experiences (paginated).
     *
     * @param string|null $updatedSince Only return experiences updated since this ISO 8601 datetime
     */
    public function getExperiences(int $page = 1, int $perPage = 1000, ?string $updatedSince = null): ?array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($updatedSince !== null) {
            $query['updated_since'] = $updatedSince;
        }
        return $this->client->get('/api/v1/static/experiences', $query);
    }

    // ── Hotel Search & Booking ──

    /**
     * Initiate a hotel search.
     *
     * @param array $params {destination_id, check_in, check_out, occupancy, currency, ...}
     * @return array|null {cursor: string} — cursor used to poll results
     */
    public function searchHotels(array $params): ?array
    {
        return $this->client->post('/api/v1/hotels/search', $params);
    }

    /**
     * Get hotel search results (cursor-based polling).
     *
     * API returns {data: [...], cursor: string|null}. Poll until cursor is null.
     *
     * @param string $cursor Cursor from searchHotels() or previous getHotelResults()
     * @return array|null {data: array, cursor: string|null}
     */
    public function getHotelResults(string $cursor): ?array
    {
        return $this->client->get('/api/v1/hotels/results', ['cursor' => $cursor]);
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
     * @param array $bookingData {offer_id, reference_code?, price, currency, occupancy: [{room_code, guests: [{first_name, last_name, birth_date, gender}]}]}
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
     *
     * API returns {data: [...], cursor: string|null}. Poll until cursor is null.
     *
     * @param string $cursor Cursor from searchPackages() or previous getPackageResults()
     * @return array|null {data: array, cursor: string|null}
     */
    public function getPackageResults(string $cursor): ?array
    {
        return $this->client->get('/api/v1/packages/results', ['cursor' => $cursor]);
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

    // ── Circuit Search & Booking ──
    // Flow: rates → quote → customize (optional) → book

    /**
     * Get circuit rates (paginated catalog with pricing for 2 adults).
     *
     * @param array $params {circuit_ids?, transport_types?, durations?, departures?, destinatons?, months?, tags?, pagination?}
     */
    public function getCircuitRates(array $params = []): ?array
    {
        return $this->client->post('/api/v1/circuits/rates', $params);
    }

    /**
     * Get a quote for a specific circuit departure.
     *
     * @param array $params {circuit_id, departure_date, occupancy, departure_id}
     */
    public function getCircuitQuote(array $params): ?array
    {
        return $this->client->post('/api/v1/circuits/quote', $params);
    }

    /**
     * Customize a circuit offer (add optional services).
     *
     * @param array $data {offer_id, service_codes}
     */
    public function customizeCircuit(array $data): ?array
    {
        return $this->client->post('/api/v1/circuits/customize', $data);
    }

    /**
     * Book a circuit offer.
     *
     * @param array $bookingData {offer_id, reference_code?, price, currency, occupancy}
     */
    public function bookCircuit(array $bookingData): ?array
    {
        return $this->client->post('/api/v1/circuits/book', $bookingData);
    }

    // ── Experience Search & Booking ──
    // Flow: rates → quote → book (no customize step)

    /**
     * Get experience rates (paginated catalog with pricing for 1 adult).
     *
     * @param array $params {experience_ids?, durations?, destinatons?, pickup_points?, months?, from?, to?, tags?, pagination?}
     */
    public function getExperienceRates(array $params = []): ?array
    {
        return $this->client->post('/api/v1/experiences/rates', $params);
    }

    /**
     * Get a quote for a specific experience.
     *
     * @param array $params {experience_id, departure_date, occupancy, pickup_point_code?, pickup_point_time?}
     */
    public function getExperienceQuote(array $params): ?array
    {
        return $this->client->post('/api/v1/experiences/quote', $params);
    }

    /**
     * Book an experience offer.
     *
     * @param array $bookingData {offer_id, reference_code?, price, currency, occupancy}
     */
    public function bookExperience(array $bookingData): ?array
    {
        return $this->client->post('/api/v1/experiences/book', $bookingData);
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
