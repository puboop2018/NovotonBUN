# Sphinx API — Complete Endpoint Reference

Extracted from `Sphinx API.txt` documentation.
All endpoints are implemented in `addon-sphinx-holidays/.../src/SphinxApi.php`.

Base URL: configured per instance (dev: `api.sphinx2.christiantour.dev.ploi.imementohub.com`)
Auth: `Authorization: Bearer {API_KEY}`

---

## Authentication & Testing

| Method | Endpoint                    | SphinxApi Method       | Description                |
|--------|-----------------------------|------------------------|----------------------------|
| GET    | /api/v1/ping                | ping()                 | Validate domain config     |
| GET    | /api/v1/me                  | me()                   | Validate authentication    |

## Static Data (cache locally, sync via cron)

| Method | Endpoint                         | SphinxApi Method                | Description                    |
|--------|----------------------------------|---------------------------------|--------------------------------|
| GET    | /api/v1/static/destinations      | getDestinations()               | List all destinations (paged)  |
| GET    | /api/v1/static/destinations/:id  | getDestination($id)             | Retrieve single destination    |
| GET    | /api/v1/static/hotels            | getHotels()                     | List all hotels (paged)        |
| GET    | /api/v1/static/hotels/:id        | getHotel($id)                   | Retrieve single hotel          |
| GET    | /api/v1/static/circuits          | getCircuits()                   | List all circuits (paged)      |
| GET    | /api/v1/static/experiences       | getExperiences()                | List all experiences (paged)   |
| GET    | /api/v1/static/package-routes    | getPackageRoutes()              | List all package routes        |

Supports `updated_since` parameter for incremental sync (destinations, hotels, circuits, experiences).

## Hotel Search & Booking

| Method | Endpoint                | SphinxApi Method              | Description                       |
|--------|-------------------------|-------------------------------|-----------------------------------|
| POST   | /api/v1/hotels/search   | searchHotels($params)         | Initiate hotel search → cursor    |
| GET    | /api/v1/hotels/results  | getHotelResults($id, $cursor) | Poll results (cursor-based)       |
| GET    | /api/v1/hotels/verify   | verifyHotelOffer($offerId)    | Verify offer before booking       |
| POST   | /api/v1/hotels/book     | bookHotel($bookingData)       | Place hotel booking               |

## Package Search & Booking

| Method | Endpoint                   | SphinxApi Method                 | Description                       |
|--------|----------------------------|----------------------------------|-----------------------------------|
| POST   | /api/v1/packages/search    | searchPackages($params)          | Initiate package search → cursor  |
| GET    | /api/v1/packages/results   | getPackageResults($id, $cursor)  | Poll results (cursor-based)       |
| GET    | /api/v1/packages/verify    | verifyPackageOffer($offerId)     | Verify offer before booking       |
| POST   | /api/v1/packages/customize | customizePackage($data)          | Add optional services to offer    |
| POST   | /api/v1/packages/book      | bookPackage($bookingData)        | Place package booking             |

## Circuit Rates & Booking

| Method | Endpoint                    | SphinxApi Method              | Description                         |
|--------|-----------------------------|-------------------------------|-------------------------------------|
| POST   | /api/v1/circuits/rates      | getCircuitRates($params)      | Get circuit rates (filterable)      |
| POST   | /api/v1/circuits/quote      | getCircuitQuote($params)      | Get priced quote for a departure    |
| POST   | /api/v1/circuits/customize  | customizeCircuit($data)       | Add optional services before book   |
| POST   | /api/v1/circuits/book       | bookCircuit($bookingData)     | Place circuit booking               |

## Experience Rates & Booking

| Method | Endpoint                      | SphinxApi Method                | Description                          |
|--------|-------------------------------|---------------------------------|--------------------------------------|
| POST   | /api/v1/experiences/rates     | getExperienceRates($params)     | Get experience rates (filterable)    |
| POST   | /api/v1/experiences/quote     | getExperienceQuote($params)     | Get priced quote for a departure     |
| POST   | /api/v1/experiences/book      | bookExperience($bookingData)    | Place experience booking             |

## Orders

| Method | Endpoint              | SphinxApi Method             | Description                        |
|--------|-----------------------|------------------------------|------------------------------------|
| GET    | /api/v1/orders        | getOrders($page, $perPage)   | List all orders (paged, filtered)  |
| GET    | /api/v1/orders/:id    | getOrder($orderId)           | Retrieve single order              |

## Cache (pre-warming)

| Method | Endpoint                | SphinxApi Method          | Description                |
|--------|-------------------------|---------------------------|----------------------------|
| POST   | /api/v1/cache/hotels    | cacheHotels($params)      | Pre-cache hotel data       |
| POST   | /api/v1/cache/packages  | cachePackages($params)    | Pre-cache package data     |

---

## Rate Limiting

- `X-RateLimit-Limit`: max requests per time frame
- `X-RateLimit-Remaining`: requests remaining
- `X-RateLimit-Reset`: reset timestamp
- `Retry-After`: seconds until reset
- HTTP 429 = rate limited; respect `Retry-After`

## Booking Statuses (from /orders)

| Status       | Description                                          |
|--------------|------------------------------------------------------|
| confirmed    | Booking confirmed                                    |
| unconfirmed  | Booking not confirmed                                |
| on_request   | Pending supplier confirmation                        |
| processing   | Under processing — poll again after 20s              |
| canceled     | Booking canceled                                     |
| errored      | Booking failed                                       |

## Total: 29 endpoints, all implemented in SphinxApi.php
