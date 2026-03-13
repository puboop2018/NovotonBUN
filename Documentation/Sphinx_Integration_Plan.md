# Sphinx API Integration — Addon Architecture Decision

**Version:** 1.0
**Date:** 2026-03-13
**Status:** Proposed
**Related:** `shared-travel-core-architecture.md`

---

## Context

The goal is to integrate the Sphinx REST API (Hotels + Packages first, Circuits + Experiences later). The key question is: **How many addons? 2 or 3?**

The existing `shared-travel-core-architecture.md` document already prescribes a **3-addon architecture**. This plan validates that recommendation.

---

## Answer: 3 Addons (as documented in shared-travel-core-architecture.md)

The architecture document at `Documentation/shared-travel-core-architecture.md` already defines this clearly:

```
travel_core (shared)
  ├── novoton_holidays (requires: travel_core)
  └── sphinx_holidays  (requires: travel_core)
```

### The 3 Addons

| # | Addon | Purpose | Contains |
|---|-------|---------|----------|
| 1 | **`travel_core`** | Shared foundation (~70% of code) | Feature mapping, booking form, guest entry, search UI, cart/checkout integration, email templates, admin panel, JS/CSS, provider registry |
| 2 | **`novoton_holidays`** | Novoton XML API adapter (~15%) | XML API clients, Novoton normalizer, cron sync commands, hotel data tables, commission calc, status mapping |
| 3 | **`sphinx_holidays`** | Sphinx REST API adapter (~15%) | REST/JSON API client, Sphinx normalizer, cron sync commands, hotel data tables, commission calc, status mapping |

### Why 3 Addons (Not 2)

**2 addons would mean** either:
- (a) Sphinx code inside `novoton_holidays` — violates separation of concerns, couples unrelated APIs
- (b) No shared core — duplicates ~70% of code between the two API addons

**3 addons gives you:**
- Install `travel_core` + `novoton_holidays` → Novoton only (current state)
- Install `travel_core` + `sphinx_holidays` → Sphinx only
- Install all three → both providers active simultaneously
- Add a 4th provider later (e.g., `eurosite_holidays`) without touching existing code

---

## What Goes Where

### `travel_core` — Shared Components
- `FeatureMapper` service + `travel_feature_map` / `travel_api_alias` DB tables
- `SearchParameterNormalizer` (date calc, multi-room parsing, age handling)
- `SearchResultFormatter` (template variable assignment)
- `GuestDataService` + `GuestDataNormalizer`
- `PriceChangeDetector`
- `TravelProviderRegistry` (resolves which adapter handles a given hotel)
- Booking form templates (Smarty), guest entry fields
- Cart/checkout display hooks
- Email templates
- React search UI, DOB masking JS, booking form validation JS, CSS
- Admin booking management (list, filter, view)
- Shared interfaces: `SearchAdapterInterface`, `BookingSubmitterInterface`, `PriceVerifierInterface`

### `novoton_holidays` — Novoton-Specific
- `NovotonHttpClient` (XML over HTTP POST, retry + circuit breaker)
- `NovotonApi` facade + domain API clients (Hotel, Pricing, Availability, Reservation, Destination)
- `NovotonXmlParser`
- `NovotonNormalizer` (implements `ProviderNormalizerInterface`)
- Cron commands (HotelListSync, HotelSync, PriceCompute, etc.)
- `novoton_hotels`, `novoton_bookings` DB tables
- `CommissionCalculator`
- Constants (board codes, status codes, age types)

### `sphinx_holidays` — Sphinx-Specific (to be built)
- `SphinxHttpClient` (REST/JSON, Bearer auth, rate limiting, cursor pagination)
- `SphinxApi` facade + domain clients
- `SphinxNormalizer` (implements `ProviderNormalizerInterface`)
- Cron commands (destination sync, hotel sync, route sync)
- `sphinx_hotels`, `sphinx_bookings`, `sphinx_destinations` DB tables
- Commission calculation
- Status mapping (`confirmed`/`pending`/`cancelled` → internal codes)

---

## Sphinx API — Complete Feature Map

### All 28 Endpoints

#### Authentication & Testing
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/ping` | GET | Connectivity test (no auth) |
| `/api/v1/me` | GET | Retrieve API user profile |

#### Static Data (for sync/caching)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/static/destinations` | GET | All destinations (tree: continent → country → region → city) |
| `/api/v1/static/destinations/:id` | GET | Single destination details |
| `/api/v1/static/hotels` | GET | All hotels with facilities, images, geolocation |
| `/api/v1/static/hotels/:id` | GET | Single hotel details |
| `/api/v1/static/circuits` | GET | All organized multi-day tours |
| `/api/v1/static/experiences` | GET | All activity-based offerings |
| `/api/v1/static/package-routes` | GET | Available flight/bus routes with dates |

#### Hotel Booking Flow (Phase 1)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/hotels/search` | POST | Initiate hotel search |
| `/api/v1/hotels/results` | GET | Get results (cursor-based pagination) |
| `/api/v1/hotels/verify` | GET | Verify offer (loads payment terms & cancellation fees) |
| `/api/v1/hotels/book` | POST | Book hotel |

#### Package Booking Flow (Phase 1)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/packages/search` | POST | Search flight+hotel or bus+hotel packages |
| `/api/v1/packages/results` | GET | Get package results (cursor-based) |
| `/api/v1/packages/verify` | GET | Verify package offer |
| `/api/v1/packages/customize` | POST | Add optional extras/services |
| `/api/v1/packages/book` | POST | Book package |

#### Circuits — Multi-Day Group Tours (Phase 2)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/circuits/rates` | POST | Get circuit rates (prices for 2 adults default) |
| `/api/v1/circuits/quote` | POST | Get quote for specific circuit |
| `/api/v1/circuits/customize` | POST | Add optional services |
| `/api/v1/circuits/book` | POST | Book circuit |

#### Experiences — Activity-Based Tours (Phase 2)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/experiences/rates` | POST | Get experience rates (1 adult default) |
| `/api/v1/experiences/quote` | POST | Quote specific experience |
| `/api/v1/experiences/book` | POST | Book experience |

#### Order Management
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/orders` | GET | List all orders (paginated) |
| `/api/v1/orders/:id` | GET | Get single order details |

#### Cache (Optimization)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/cache/hotels` | POST | Pre-cache hotel data |
| `/api/v1/cache/packages` | POST | Pre-cache package data |

### Hotel-Only vs Packages

| Feature | Hotel-Only | Packages |
|---------|-----------|----------|
| Scope | Worldwide — any `destination_id` from the full destination tree (~2,000 destinations) | Route-specific — only where Christian Tour operates charter flights/buses (from `package-routes` endpoint) |
| Includes | Accommodation only | Flight/Bus + Hotel + Transfers |
| Booking flow | Search → Verify → Book | Search → Verify → Customize → Book |
| Price structure | Room price only | Bundled price (transport + hotel + transfers) |
| Target customer | Independent travelers | Package holiday buyers |
| Revenue potential | Moderate | High (higher basket value) |

### Seaside & Exotic Destinations Found in API Examples

| Destination | Region | Country | Available Via |
|-------------|--------|---------|---------------|
| **Antalya** | Antalya | Turkey (TR) | Hotel-only + Packages |
| **Antalya City** | Antalya | Turkey (TR) | Hotel-only + Packages |
| **Side** | Antalya | Turkey (TR) | Hotel-only + Packages |
| **Corfu** | - | Greece (GR) | Packages |

Departure cities (Romania): Bucharest (OTP), Timisoara (TSR), Sibiu (SBZ)

### Key API Features
- **Cursor-based async search**: Results stream in as suppliers respond
- **Rate limiting**: Headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- **Verification step**: Must verify offers before booking (prices may change)
- **Cancellation policies**: Loaded during verification with free cancellation flags
- **Payment terms**: Rules and text loaded during verification
- **Commission**: Visible in pricing (`marketing_price`, `selling_price`, `commission`)
- **Labels/Tags**: Special deals, recommendations, seasonal tags
- **Extra services**: Optional add-ons during customize step
- **`ignore_domains`**: Deduplication parameter to avoid supplier chain duplicates

---

## CS-Cart addon.xml Dependencies

```xml
<!-- travel_core/addon.xml -->
<addon scheme="4.0" edition_type="ROOT,ULT:VENDOR">
    <id>travel_core</id>
</addon>

<!-- novoton_holidays/addon.xml -->
<addon scheme="4.0" edition_type="ROOT,ULT:VENDOR">
    <id>novoton_holidays</id>
    <dependencies>travel_core</dependencies>
</addon>

<!-- sphinx_holidays/addon.xml -->
<addon scheme="4.0" edition_type="ROOT,ULT:VENDOR">
    <id>sphinx_holidays</id>
    <dependencies>travel_core</dependencies>
</addon>
```

---

## Migration Strategy

The `shared-travel-core-architecture.md` recommends a **gradual extraction** approach:

1. **Phase 1**: Create `travel_core` addon with shared DB tables + interfaces
2. **Phase 2**: Move shared services from `novoton_holidays` → `travel_core` (one service at a time)
3. **Phase 3**: Build `sphinx_holidays` adapter against the shared interfaces (Hotels + Packages)
4. **Phase 4**: Test both providers running simultaneously
5. **Phase 5**: Add Circuits + Experiences to `sphinx_holidays`

Key principle: `travel_core` has **zero** API-specific code. It only knows about interfaces and generic travel concepts.

---

## Integration Priority

### Phase 1 — Core (Start Here)
1. Static data sync — destinations, hotels, package-routes
2. Hotel-only search + booking — widest destination coverage
3. Package search + booking — highest revenue product

### Phase 2 — Extended
4. Circuits — multi-day tours (new product type)
5. Experiences — activities (new product type)
6. Order management — track booking status

### Phase 3 — Optimization
7. Cache endpoints — pre-warm search results
8. Feature mapping — normalize Sphinx data to existing CS-Cart product features

---

## Verification Checklist

- [ ] `novoton_holidays` continues to work after extraction (regression test)
- [ ] `sphinx_holidays` can search hotels independently
- [ ] `sphinx_holidays` can search packages independently
- [ ] Both providers can run simultaneously via `TravelProviderRegistry`
- [ ] Feature mapping resolves aliases from both APIs to the same canonical codes
- [ ] Booking form works identically for both providers
- [ ] Cart/checkout displays correct provider-specific data
