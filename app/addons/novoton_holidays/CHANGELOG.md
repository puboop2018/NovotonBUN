# Novoton Holidays - Changelog

## A91 — Dashboard Statistics Documentation

### Documentation

- **DOCUMENTED:** Dashboard statistics "Real-time (room_price) available" and "Season prices (priceinfo) available" — explained what populates each counter and the DB conditions behind them
- **NOTED:** Both counters can show values without running "Check Prices" actions — Hotel Info Sync (`hotel_info_batched`) sets `has_prices` and `packages_count` when `getHotelInfo` API returns packages
- **NOTED:** Check Prices (Resort-based) and Check Prices (Per-Hotel) currently return the same number of hotels with prices — no discrepancies exist between the two methods in the current dataset

### Files Changed (1)

Documentation: `README.md`

---

## A90 — Code Smell Refactor: DI Consistency, Deduplication, Error Suppression Cleanup

### Dependency Injection (Container / ServiceLoader)

- **CHANGED:** Replaced 23 direct `new ClassName()` instantiations across controllers and functions with Container/ServiceLoader calls
- **ADDED:** `Container::novotonApi()` — singleton NovotonApi instance
- **ADDED:** `Container::adminCronService()` — AdminCronService with injected API
- **ADDED:** `Container::propertyTypeDetector()` — PropertyTypeDetector singleton
- **ADDED:** `_nvt_api()` ServiceLoader helper for procedural code
- **ADDED:** `_nvt_admin_cron_service()` ServiceLoader helper
- **ADDED:** `_nvt_property_type_detector()` ServiceLoader helper
- **ADDED:** `_nvt_alternative_request_repo()` ServiceLoader helper
- **CHANGED:** `BookingSubmissionService` now receives `NovotonApi` via Container (`$this->novotonApi()`) instead of `new NovotonApi()`
- **CHANGED:** `NovotonApi` constructor uses `Container::getInstance()->cacheService()` instead of `new CacheService('file')`

### Duplicated Logic

- **ADDED:** `fn_novoton_match_price_from_xml()` helper in `functions/helpers.php` — shared XML price matching for flat room_price responses
- **FIXED:** Inconsistent room matching between `ajax_recalculate_price.php` (`strcasecmp`) and `novoton_price_compare.php` (`===`) — both now use `strcasecmp` via shared helper
- **CHANGED:** Helper handles both `//IdBoard` and `//Board` xpath variants automatically

### Magic Strings → Constants

- **CHANGED:** Hardcoded `'novoton_holidays'` in DB queries replaced with `Constants::ADDON_ID` using parameterized `?s` placeholders (novoton_diagnostic, novoton_holidays controller, install.php)

### Error Suppression Cleanup

- **CHANGED:** Removed redundant `@` error suppression from `db_query()` calls inside `try-catch` blocks (`PriceInfoService::precomputeCalendarPrices()`)
- **CHANGED:** Removed redundant `@` from `mkdir()` / `file_put_contents()` inside `try-catch` blocks (product_hooks.php error logging)
- **CHANGED:** Removed `@` from `file_get_contents()` calls already guarded by `file_exists()` (StateManager load/restoreFromBackup)
- **CHANGED:** Replaced `@copy()` with `is_readable()` guard (StateManager save)
- **CHANGED:** Removed `@` from `mkdir()` already guarded by `!is_dir()` (DirectoryManager, BatchedHotelInfoSync, BatchedHotelFacilitiesSync, BatchedPriceInfoSync)
- **KEPT:** Intentional `@` suppression in StateManager atomic file ops (race conditions), install.php schema migrations, and PriceInfoService optional column checks

### Files Changed (27)

Controllers: `novoton_admin`, `novoton_alternatives`, `novoton_diagnostic`, `novoton_holidays`, `novoton_hotels`, `novoton_price_compare`, `ajax_recalculate_price`, `novoton_cron`
Functions: `helpers`, `bookings`, `formatting`, `install`
Hooks: `product_hooks`, `order_hooks`, `cart_hooks`
Services: `Container`, `ServiceLoader`, `PriceInfoService`, `DirectoryManager`
Core: `NovotonApi`, `HotelSync`, `AbstractBatchedSync`, `BatchedHotelInfoSync`, `BatchedHotelFacilitiesSync`, `BatchedPriceInfoSync`, `StateManager`
Entry: `cron.php`

---

## A89 — Facility-to-Feature Routing, Travel Group & Beach Access, Adults-Only Detection

### New Features

- **NEW:** Adults-only hotel detection via `AdultOnlyDetector` — scans hotel names for patterns like "Adults Only", "18+", "No Children"
- **NEW:** `is_adults_only` column on `novoton_hotels` table, populated during hotel list and hotel info sync
- **NEW:** Travel Group feature type (`travel_group`) — maps adults_only, pets (facility 3), families (facility 26), disabilities (facility 23)
- **NEW:** Beach Access feature type (`beach_access`) — maps beachfront/first line (facility 31) displayed as "Beachfront" / "La malul mării"
- **NEW:** Data-driven facility routing — hotel facility IDs are routed to their target feature type via `hotel_feature_mappings` table lookup, not hardcoded constants
- **NEW:** `findFeatureTypeForCode()` method on `FeatureMappingRepository` for routing lookup
- **NEW:** Property type assignment during product sync

### Admin UI Improvements

- **CHANGED:** Settings tab renamed from "Feature Mapping" to "Feature IDs Mapping"
- **CHANGED:** All 8 feature ID settings converted from input fields to selectbox dropdowns showing CS-Cart feature name + ID + type
- **NEW:** Variant Name column on Feature Mappings manage page — shows resolved CS-Cart variant name
- **CHANGED:** Resort names displayed as Title Case everywhere (normalizer, sync, seed)

### Seed Data

- **ADDED:** Travel Group seed entries: Adults only, Pets allowed, Suitable for families with children, Suitable for people with disabilities
- **ADDED:** Beach Access seed entry: Beachfront (facility 31)
- **CHANGED:** Hotel facility seed skips rerouted facility IDs (3, 23, 26, 31)
- **CHANGED:** Resort seed applies Title Case to display names

### Settings

- **ADDED:** `feature_id_travel_group` setting (default: 14)
- **ADDED:** `feature_id_beach_access` setting (default: 3)

---

## A88 — Codebase Audit + Storefront ID Fix

### Bug Fixes

- **FIXED:** "ID-ul magazinului este necesar (parametrul storefront_id)" error on frontend
  - Root cause: manual `fetch()` AJAX calls bypassed CS-Cart's `fn_url` URL builder, missing the required `storefront_id` parameter for multi-storefront setups
  - `booking_form.tpl` (both themes): replaced manual URL construction with `fn_url` Smarty function
  - `scripts.post.tpl` (both themes): added `NovotonConfig.ajaxRecalcUrl` — pre-built URL via `fn_url` exposed to JavaScript
  - `booking-form-validation.js`: uses `NovotonConfig.ajaxRecalcUrl` with fallback
  - `dob-validation.js`: uses `NovotonConfig.ajaxRecalcUrl` with fallback

### Code Quality (Codebase Audit)

- **REMOVED:** Empty leftover template files (`country_selector.tpl`, `update_prices_button.tpl`)
- **CHANGED:** Replaced hardcoded English strings in `novoton_bookings/manage.tpl` with `__()` language calls (cleanup confirm, orphan bookings button, incomplete bookings toggle)
- **CHANGED:** Replaced hardcoded strings in `settings/cron_info.tpl` with language variables (headings, descriptions, table headers)
- **ADDED:** 11 new translation keys to both EN and RO `.po` language files

---

## Version 3.2.0

Complete hotel booking integration with Novoton XML API.
Features React 19 booking engine with Booking.com-style interface.

### Features

- Novoton XML API integration (27 functions)
- Real-time hotel availability search
- Multi-room booking with guest assignment
- React 19 booking engine
- Early booking discounts
- Flexible date search
- Mobile responsive design
- Admin booking management
- API response caching
- Service-based architecture
- Security hardening
- Performance optimizations

---

## A86 — PHP Warning Fix + UI Polish

### Bug Fixes

- **FIXED:** PHP warnings corrupting AJAX JSON response (root cause fix)
  - `dob-validation.js`: replaced dirty URL (`Tygh.current_url.replace`) with clean baseUrl + dispatch-only URL construction
  - `booking-form-validation.js`: already had clean URL (no change)
  - Controller: scoped `set_error_handler()` replaces blanket `error_reporting(0)` — warnings logged, not suppressed
  - Controller top: sanitize `$_REQUEST`/`$_GET` arrays (`children_ages[]`, `ages[]`) to comma-separated strings before CS-Cart `__()` can choke
- **FIXED:** DOB event handler race condition with multi-room support
- **FIXED:** AJAX URL leaking booking page params (`children_ages[]`, etc.)

### UI / Frontend

- **CHANGED:** Removed dark backgrounds from search results page CSS
- **NEW:** Visual Editor integration for search button color
- **CHANGED:** Replaced hardcoded modal text and Early Booking labels with translation keys (NovotonTranslations)

---

## A71–A72 — Service Integration Cleanup

- **NEW:** Service getters (`_nvt_get_*_service`) for lazy-loaded singletons
- **NEW:** `_nvt_get_cached_hotel_info()` adds 30-min caching to hotel info
- **KEPT:** Validation helpers `_nvt_parse_and_validate_guests`, `_nvt_parse_dob`
- **KEPT:** XML utility `_nvt_get_xml_value`
- **REMOVED:** Duplicate helpers that overlap with existing services
- Use `SearchService::parseSearchParams()` directly
- Use `SearchService::calculateRoomTotals()` directly
- Use `BookingService::createBooking()` and `addToCart()` directly
- Use `RoomPriceService::getRoomPrice()` directly (has built-in caching)
- Use `CacheService::remember()` for caching patterns
- Code reduced by ~280 lines through proper service delegation

---

## A70 — Order Details Terms Display

- **NEW:** Display Terms of Payment in order details (frontend)
- **NEW:** Display Cancellation Terms in order details (frontend)
- **NEW:** PHP hook `fn_novoton_holidays_get_order_info` formats terms
- Terms displayed after `orders:extra_list` hook
- Proper formatting of XML terms into readable text

---

## A69 — Child Age Validation + Auto Price Update

- **CHANGED:** Child age >= 18 at check-in now BLOCKED (not just warning)
- **NEW:** Server-side validation rejects children >= 18 at check-in
- **FIXED:** Price recalculates only when DOB age differs from selected age
- Price unchanged if same bracket (e.g., child 5→7 in 2–11 bracket)
- Added translations for child age validation errors
- Form submission blocked until valid child DOB entered

---

## A68 — Server-Side DOB Validation

- **NEW:** Server-side DOB future date validation in controller
- **NEW:** `validateBirthday()` helper in GuestDataService
- Defense-in-depth: both client and server reject future DOB
- Logs invalid DOB attempts for security monitoring

---

## A67 — Desktop/Mobile + DOB Validation

- **FIXED:** Desktop showing both desktop AND mobile layouts
- Added stronger CSS media queries with `!important`
- Added `position:absolute left:-9999px` for mobile hiding
- **NEW:** `dob-validation.js` — client-side DOB future date prevention
- Added JS failsafe for desktop/mobile visibility on resize
- Added MutationObserver for dynamically added guest fields
- Added `dobCannotBeFuture` translation key

---

## A20 — Complete Optimization

### Phase 1–3: Architecture & Performance

- **NEW:** Service classes for code organization
  - `SearchService` — search/availability
  - `BookingService` — booking operations
  - `GuestDataService` — guest handling
  - `RoomPriceService` — real-time prices
  - `PriceInfoService` — season price lists
  - `CacheService` — API response caching
- **NEW:** API response caching (configurable TTL)
- **NEW:** Database indexes for common queries
- **FIXED:** Booking data sync to database

### Phase 4: Frontend Optimization

- **NEW:** `utils.js` — shared utility functions
- **NEW:** `lazy-loader.js` — on-demand component loading
- Reduced duplicate code across JS files
- Improved load performance

### Phase 5: Security

- **NEW:** SecurityService with input validation
- **NEW:** Rate limiting for API/booking requests
- **NEW:** CSRF token validation
- **NEW:** Data encryption helpers
- Sanitized all user inputs

### Phase 6: Code Quality

- **NEW:** `Constants.php` — centralized constants
- **NEW:** `LoggerTrait` — consistent PSR-3-like logging
- Removed magic strings
- Improved error handling
- Better code documentation
