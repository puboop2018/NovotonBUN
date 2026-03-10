# Novoton Holidays CS-Cart Addon — Architecture Audit & Recommendations

**Date:** 2026-03-10
**Addon Version:** 3.2.0
**Scope:** Full codebase audit — PHP backend, React frontend, architecture, security, performance

---

## Executive Summary

The codebase is **well-structured for a CS-Cart addon** with good separation of concerns: a proper DI container, repository pattern, value objects, domain-specific API clients, and a clear cron command architecture. The code quality is above average for the CS-Cart ecosystem.

Below are prioritized recommendations grouped by category.

---

## 1. HIGH PRIORITY — Security & Data Integrity

### 1.1 Encryption Key Fallback Is Weak
**File:** `src/Services/SecurityService.php:530-542`

When no `crypt_key` or `api_key` is available, the fallback uses `hash('sha256', __DIR__ . php_uname('n'))` — a deterministic, guessable value. Any attacker who knows the server hostname and addon install path can derive the key.

**Recommendation:** Generate and persist a random key on first use (e.g., write to a protected file or database setting). Never derive encryption keys from predictable inputs.

### 1.2 `unserialize()` in Cache & Rate Limiting
**Files:** `src/Services/CacheService.php:197,417,502` | `src/Services/SecurityService.php:505`

While `['allowed_classes' => false]` is used (good), `serialize/unserialize` remains a potential attack vector if cache storage is compromised. PHP object injection risks are mitigated but not eliminated.

**Recommendation:** Replace `serialize/unserialize` with `json_encode/json_decode` for cache storage. JSON is safer, faster for simple data types, and interoperable.

### 1.3 AJAX Search Response Executes Inline Scripts (XSS Risk)
**File:** `react-src/src/BookingEngine.jsx:275-284`

The AJAX search handler parses fetched HTML and **re-executes inline `<script>` tags** by creating new script elements. If the server response is ever compromised or includes user-controlled content, this becomes a direct XSS vector.

**Recommendation:** Avoid script re-execution entirely. Use a structured JSON API response for search results instead of HTML scraping. If HTML must be used, sanitize with a DOMPurify-like approach and never re-execute scripts.

### 1.4 HTTP API Communication
**File:** `src/NovotonHttpClient.php:49-53`

The client defaults to `http://` when no scheme is provided. API credentials (user/password/key) are sent over this connection. Even with the comment about the provider specifying HTTP, credentials should never traverse unencrypted.

**Recommendation:** Log a warning when HTTP is used, and strongly recommend HTTPS configuration. Consider refusing HTTP in production environments.

---

## 2. HIGH PRIORITY — Architecture & Maintainability

### 2.1 Mixed Procedural & OOP — Dual-Track Architecture
**Files:** `func.php`, `functions/*.php` vs `src/Services/*.php`

The codebase has two parallel architectures:
- **Legacy procedural:** `fn_novoton_holidays_*()` functions in `functions/` (~180+ functions)
- **Modern OOP:** Services, Repositories, Value Objects in `src/`

Services sometimes call procedural functions (e.g., `BookingService` calls `fn_novoton_holidays_get_hotel_data()`, `fn_novoton_holidays_format_room_type()`), and procedural functions create service instances. This creates circular dependencies and makes testing difficult.

**Recommendation:**
- Migrate procedural functions into services incrementally. Priority targets: `functions/helpers.php`, `functions/hotels.php`, `functions/bookings.php`
- Create thin wrappers in the procedural functions that delegate to services (for backward compatibility with CS-Cart hook system)
- Goal: procedural layer becomes a pure adapter over services

### 2.2 Constructor Dependency on Global State
**Files:** `src/Services/BookingService.php:48`, `src/HotelSync.php:38-39`

Multiple classes call `fn_novoton_holidays_get_api()` or `new NovotonApi()` inside constructors rather than accepting dependencies via injection. This makes unit testing impossible without a full CS-Cart bootstrap.

**Recommendation:** Accept `NovotonApi` (or `NovotonApiInterface`) as a constructor parameter. The container already wires this correctly — extend the pattern consistently.

### 2.3 Static State in ConfigProvider
**File:** `src/Services/ConfigProvider.php`

`ConfigProvider` is fully static. While convenient, this:
- Makes testing require `ConfigProvider::reset()` between tests
- Prevents different configurations in the same process (e.g., multi-store)
- Violates the container pattern used everywhere else

**Recommendation:** Convert to an instance-based service registered in the Container. Keep static methods as thin facades over the instance for backward compatibility during migration.

### 2.4 NovotonApi Facade Has Excessive Boilerplate
**File:** `src/NovotonApi.php`

Every method follows the same pattern: delegate to sub-client, call `syncFrom()`. This is 250+ lines of pure boilerplate.

**Recommendation:** Use `__call()` magic method with a routing map, or generate the delegates. Alternatively, deprecate the facade and have callers use domain clients directly via `$api->hotels()->getHotelList()`.

---

## 3. MEDIUM PRIORITY — Performance

### 3.1 N+1 Query Pattern in Hotel Sync
**File:** `src/HotelSync.php:284-287`

Inside the `syncHotelInfo` loop, each hotel triggers a `SELECT hotel_name FROM novoton_hotels` query — but the hotel name was already fetched in the `$hotelIds` query.

**Recommendation:** Fetch `hotel_id, hotel_name` pairs upfront in a single query.

### 3.2 Unbounded Memory Cache
**File:** `src/Services/CacheService.php:83-86`

`self::$memory_cache` is a static array that grows without bound during long-running cron jobs. Each API response gets cached in memory.

**Recommendation:** Implement LRU eviction or a max-size limit (similar to `BookingRepository::HYDRATED_CACHE_MAX`). This is especially important for cron commands processing hundreds of hotels.

### 3.3 File Cache Cleanup Reads Every Cache File
**File:** `src/Services/CacheService.php:498-509`

`cleanup()` does `file_get_contents` + `unserialize` on every `.cache` file. With thousands of cached API responses, this is extremely I/O intensive.

**Recommendation:** Use `filemtime()` as a fast heuristic — if file's mtime + max_ttl < now, it's definitely expired. Only unserialize uncertain cases.

### 3.4 No Database Query Batching in Package Sync
**File:** `src/HotelSync.php:427-440`

Each package upsert is an individual `INSERT ... ON DUPLICATE KEY UPDATE` query. With hundreds of packages per sync, this generates hundreds of queries.

**Recommendation:** Batch package upserts similar to `executeBatchHotelUpsert()` already used for hotels.

### 3.5 `curl_multi` Doesn't Use HTTP/2 Multiplexing
**File:** `src/NovotonHttpClient.php:231-240`

The batch request implementation creates separate handles per chunk but doesn't set `CURLOPT_HTTP_VERSION` to `CURL_HTTP_VERSION_2_0`. If the server supports HTTP/2, multiplexing would significantly reduce latency.

**Recommendation:** Add `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0` to batch requests.

---

## 4. MEDIUM PRIORITY — Testing & Quality

### 4.1 Very Low Test Coverage
**Files:** `tests/Unit/` — only 4 test files

Only `PriceInfoCalculator`, `PriceInfoFormatter`, `PriceChangeDetector`, and `SecurityService` have unit tests. Critical paths like booking creation, API communication, hotel sync, and search have zero coverage.

**Recommendation:** Priority test targets:
1. `BookingService::createBooking()` / `verifyPrice()` — money is involved
2. `CommissionCalculator::apply()` — pricing accuracy
3. `NovotonXmlParser` — XML parsing edge cases
4. `SearchParameterNormalizer` — input validation
5. `CronDispatcher` + individual cron commands — sync reliability

### 4.2 No Integration/Smoke Tests
There are no integration tests that verify the full flow (search → price check → booking → API submission).

**Recommendation:** Create a small integration test suite with mocked API responses that verifies end-to-end flows.

### 4.3 No Static Analysis Configuration
No PHPStan, Psalm, or PHP_CodeSniffer configuration exists.

**Recommendation:** Add `phpstan.neon` at level 5+ to catch type errors, dead code, and potential bugs automatically. This is the highest ROI quality improvement.

---

## 5. MEDIUM PRIORITY — Code Quality

### 5.1 Inconsistent Error Handling in Cron Commands
**File:** `src/HotelSync.php:154-166`

Each catch block in sync methods follows the same pattern (log error, increment failure count). This is duplicated across every cron command class.

**Recommendation:** Extract a `trySyncItem(callable $work, string $context)` helper in `AbstractCronCommand` or a trait.

### 5.2 Public Debug Properties
**Files:** `src/Api/ApiClientBase.php:27-32`, `src/NovotonHttpClient.php:36-38`

Debug state (`lastRequest`, `lastResponse`, `lastError`, etc.) is exposed as public properties and manually synced between objects via `syncFrom()`. This is fragile.

**Recommendation:** Encapsulate debug state in a `RequestDebugInfo` value object. Pass it by reference or return it alongside results.

### 5.3 Duplicate Constants Between ConfigProvider and Constants
**File:** `src/Services/ConfigProvider.php:32-36`

`ConfigProvider` re-declares constants that already exist in `Constants` (`IMAGE_BASE_URL`, `PRODUCT_CODE_PREFIX`). These are marked with comments saying "canonical value in Constants::" but they're still duplicated.

**Recommendation:** Remove the duplicates from `ConfigProvider` and reference `Constants::` directly everywhere.

### 5.4 Calendar Component Missing Accessibility
**File:** `react-src/src/BookingEngine.jsx`

The booking engine uses `aria-expanded` and `aria-haspopup` (good), but the Calendar and GuestPicker popups likely need:
- Focus trap when open
- Escape key to close
- `role="dialog"` / `aria-label`

**Recommendation:** Add keyboard navigation and WCAG 2.1 compliance to the calendar/guest picker components.

---

## 6. LOW PRIORITY — Nice-to-Have Improvements

### 6.1 TypeScript Migration for React
The React booking engine uses plain JSX without type checking. With only 8 source files, migrating to TypeScript would be quick and prevent prop-type bugs.

### 6.2 Structured Logging
Logging currently uses CS-Cart's `fn_log_event()` with inconsistent message formats. A structured logger (PSR-3 compatible wrapper) would improve log parsing and monitoring.

### 6.3 Database Migration System
Schema changes are handled in `functions/install.php`. A proper migration system (numbered migration files with up/down) would make upgrades more reliable and reversible.

### 6.4 API Response DTOs
API responses are currently returned as `SimpleXMLElement` or arrays. Typed DTOs (e.g., `HotelInfo`, `RoomPrice`, `SearchResult`) would improve IDE support and catch data access errors at development time.

### 6.5 Circuit Breaker State Persistence
**File:** `src/NovotonHttpClient.php:31-33`

Circuit breaker state is stored in static properties — it resets on every PHP process. For a web app with many short-lived processes, the circuit breaker rarely triggers.

**Recommendation:** Persist circuit breaker state in cache (APCu or file) so it survives across requests.

---

## Summary Matrix

| # | Category | Severity | Effort | Impact |
|---|----------|----------|--------|--------|
| 1.1 | Security | HIGH | Low | Prevents key derivation attacks |
| 1.2 | Security | HIGH | Medium | Eliminates serialization risks |
| 1.3 | Security | HIGH | Medium | Prevents XSS via AJAX |
| 1.4 | Security | MEDIUM | Low | Protects API credentials |
| 2.1 | Architecture | HIGH | High | Enables testing & maintenance |
| 2.2 | Architecture | HIGH | Low | Enables unit testing |
| 2.3 | Architecture | MEDIUM | Medium | Better testability |
| 2.4 | Architecture | LOW | Low | Reduces boilerplate |
| 3.1 | Performance | MEDIUM | Low | Eliminates N+1 queries |
| 3.2 | Performance | MEDIUM | Low | Prevents memory exhaustion |
| 3.3 | Performance | MEDIUM | Low | Faster cache cleanup |
| 3.4 | Performance | MEDIUM | Medium | Fewer DB round-trips |
| 3.5 | Performance | LOW | Low | Better HTTP throughput |
| 4.1 | Testing | HIGH | High | Catch regressions |
| 4.2 | Testing | MEDIUM | Medium | Verify full flows |
| 4.3 | Quality | MEDIUM | Low | Automated bug detection |
| 5.1 | Quality | MEDIUM | Low | DRY error handling |
| 5.2 | Quality | LOW | Medium | Cleaner debug interface |
| 5.3 | Quality | LOW | Low | Remove duplication |
| 5.4 | Quality | MEDIUM | Medium | Accessibility compliance |

---

## Recommended Action Order

1. **Quick wins (1-2 days):** 1.1, 1.4, 2.2, 3.1, 3.2, 4.3, 5.3
2. **Short-term (1-2 weeks):** 1.2, 1.3, 3.3, 3.4, 5.1, 5.4
3. **Medium-term (1-2 months):** 2.1, 2.3, 4.1, 4.2
4. **Long-term (ongoing):** 6.1-6.5
