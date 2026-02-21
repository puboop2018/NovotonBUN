# Novoton Holidays - Changelog

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
