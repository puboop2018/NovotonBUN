# Novoton Holidays - CS-Cart Addon

**Version:** 3.0.0
**Last Updated:** February 10, 2026
**Compatibility:** CS-Cart 4.x
**Developer:** VacanteLitoral.ro

Complete hotel booking integration with Novoton XML API for CS-Cart.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin Panel Pages](#admin-panel-pages)
- [Cron Jobs](#cron-jobs)
- [Exchange Rates](#exchange-rates)
- [API Functions](#api-functions)
- [Database Schema](#database-schema)
- [Architecture](#architecture)
- [React Booking Engine](#react-booking-engine)
- [Troubleshooting](#troubleshooting)
- [Changelog](#changelog)

---

## Features

### Core Functionality
- Novoton XML API integration (27 functions)
- Real-time hotel availability search
- Multi-room booking with guest assignment
- Children age validation (smart price recalculation based on check-in date)
- Early booking discounts
- Flexible date search (+/- days option)
- Room quota display with availability indicators

### User Interface
- React 19 booking engine with Booking.com-style design
- Two-month calendar with range selection
- Multi-room guest picker with age selectors
- Mobile responsive design (yellow border styling)
- Bilingual support (English/Romanian auto-detection)

### Search Results Display
- Room Type with package name, MoreInfo, and Important warnings
- Board name formatting (Ultra All Inclusive, All Inclusive, etc.)
- Choices column with free cancellation date
- Payment terms with calculated amounts (e.g., "10% (150.00€) - due by 05.03.2026")
- Cancellation terms display
- Price per person and total price columns

### Admin Features
- Booking management with Novoton sync
- Hotel import wizard (admin + cron)
- Sync logs and diagnostics dashboard
- Excluded resorts management
- API response caching
- Email notifications with CSV reports
- **Automatic exchange rate updates from BNR API**

### Technical
- Service-based architecture
- Centralized constants
- Error handling and logging
- CSRF protection
- URL-encoded API parameters

---

## Installation

1. Upload addon files to your CS-Cart installation
2. Go to **Add-ons → Downloaded add-ons**
3. Find "Novoton Holidays" and click **Install**
4. Configure API credentials in addon settings

### Required Settings

| Setting | Description |
|---------|-------------|
| API Username | Novoton API username |
| API Password | Novoton API password |
| API URL | Novoton API endpoint |
| Commission % | Markup percentage on prices |
| Cron Access Key | Secret key for cron job authentication |
| Currency Risk Commission % | Exchange rate markup (0-5%, default 1.8%) |

---

## Configuration

### Addon Settings Location

**Admin Panel → Add-ons → Manage add-ons → Novoton Holidays → Settings**

### Settings Sections

1. **Novoton API** - API credentials and endpoint
2. **Pricing** - Commission settings
3. **Countries** - Select countries to sync
4. **Excluded Resorts** - Resorts to skip during sync
5. **Cron** - Cron access key and URLs
6. **Exchange Rates** - Currency risk commission percentage

---

## Admin Panel Pages

### Main Menu: Novoton Holidays

| Page | URL | Description |
|------|-----|-------------|
| Dashboard | `novoton_holidays.manage` | Overview, stats, and quick actions |
| Hotel Bookings | `novoton_bookings.manage` | Manage customer bookings |
| Alternative Requests | `novoton_alternatives.manage` | Alternative room requests |
| Hotels Sync | `novoton_sync.manage` | Hotel synchronization |
| Room Price Check | `novoton_prices.room_price` | Check hotel prices |
| Add Hotels as Products | `novoton_holidays.add_hotels_as_products` | Import hotels to CS-Cart |
| Facilities | `novoton_holidays.list_facilities` | Hotel facilities list |
| **Exchange Rates** | `novoton_exchange_rates.manage` | Currency rate management |
| Diagnostic | `novoton_diagnostic.index` | API connectivity test |
| Test Hotel Request | `novoton_holidays.test_hotel_request` | Debug hotel API calls |
| Test Alternative RS | `novoton_holidays.test_alternative_rs` | Debug alternative search |

### Dashboard Features

The dashboard (`novoton_holidays.manage`) displays:
- **Hotels Statistics** - Total, with prices, with packages, as products
- **Bookings Overview** - Pending, confirmed, cancelled counts
- **Last Sync Times** - Hotel List, Hotel Info, Prices, Offers Update, Facilities
- **Quick Actions** - Check Prices, Check Packages, Add Hotels, Manage Bookings
- **Recent Sync Activity** - Last 10 sync operations with details

---

## Cron Jobs

### Authentication

All cron URLs require the `access_key` parameter matching your configured **Cron Access Key**.

### Available Cron Modes

| Mode | Sync Type | Description |
|------|-----------|-------------|
| `hotel_info_batched` | `hotelinfo` | **[RECOMMENDED]** Smart batched hotel sync with resume |
| `sync_priceinfo_batched` | `priceinfo` | **[RECOMMENDED]** Smart batched priceinfo sync with resume |
| `hotel_list` | `hotellist` | Sync hotel list from API |
| `list_facilities` | `facilities` | Update hotel facilities list |
| `exchange_rates` | `exchange_rates` | Update BNR currency rates (daily) |
| `resinfo` | `resinfo` | Check ASK bookings status |
| `offers_update` | `offers_update` | Sync only changed offers (delta sync) |
| `add_hotels_as_products` | - | Import hotels as CS-Cart products |
| `room_price` | `prices` | Check which hotels have active prices |

### Cron URL Examples

#### 1. Hotel List Sync
Syncs basic hotel information from Novoton API.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_list"
```

#### 2. Hotel Info Batched Sync (Recommended)
Smart sync with resume capability. First run syncs all hotels, then daily syncs only new/changed hotels.

```bash
# Auto-detect sync type (recommended)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched"

# Check progress
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&status=1"

# Force full sync (all hotels)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&force_full=1"

# Shared hosting settings (smaller batches, shorter timeout)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&batch_size=50&max_time=120"

# CLI PHP with unlimited mode (no time limit - processes all in one run)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=hotel_info_batched unlimited=1 force_full=1
```

**Parameters:**
- `&status=1` - Check progress without processing
- `&force_full=1` - Force full sync of all hotels
- `&reset=1` - Cancel/reset in-progress sync
- `&batch_size=N` - Hotels per batch (default: 100)
- `&max_time=N` - Max seconds per run (default: 300)
- `&unlimited=1` - No time limit (for CLI PHP usage)

#### 3. Price Info Batched Sync (Recommended)
Smart priceinfo sync with resume capability. Full sync every 7 days, incremental for stale packages (>24h).

```bash
# Auto-detect sync type (recommended)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched"

# Check progress
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&status=1"

# Force full sync (all packages)
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&force_full=1"

# Shared hosting settings
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&batch_size=30&max_time=120"

# CLI PHP with unlimited mode (no time limit - processes all in one run)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=sync_priceinfo_batched unlimited=1 force_full=1
```

**Parameters:**
- `&status=1` - Check progress without processing
- `&force_full=1` - Force full sync of all packages
- `&reset=1` - Cancel/reset in-progress sync
- `&batch_size=N` - Packages per batch (default: 50)
- `&max_time=N` - Max seconds per run (default: 300)
- `&stale_hours=N` - Re-sync packages older than N hours (default: 24)
- `&unlimited=1` - No time limit (for CLI PHP usage)

#### 4. Price Check
Checks which hotels have active prices.

```bash
# All countries
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=room_price"

# Specific country
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=room_price&country=BULGARIA"
```

#### 5. Offers Update (Delta Sync)
Syncs only changed offers since last update.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=offers_update"
```

#### 6. Facilities Sync
Updates hotel facilities list.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=list_facilities"
```

#### 7. Add Hotels as Products
Imports hotels as CS-Cart products (no limit by default).

```bash
# All hotels for Bulgaria
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=add_hotels_as_products&country=BULGARIA"

# With limit
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=add_hotels_as_products&country=GREECE&limit=50"
```

### Recommended Crontab

```crontab
# Hotel Info Batched - every 5 minutes (self-manages sync type)
*/5 * * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_info_batched&batch_size=50&max_time=120" > /dev/null 2>&1

# Price Info Batched - every 5 minutes (self-manages sync type)
*/5 * * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=sync_priceinfo_batched&batch_size=30&max_time=120" > /dev/null 2>&1

# Hotel list sync - daily at 2 AM
0 2 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=hotel_list" > /dev/null 2>&1

# Booking status check - every 2 hours
0 */2 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo" > /dev/null 2>&1

# Facilities sync - weekly on Sunday at 4 AM
0 4 * * 0 curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=list_facilities" > /dev/null 2>&1

# Exchange rates - daily at 13:05 (after BNR publishes)
5 13 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_exchange_rates.cron&cron_password=YOUR_KEY" > /dev/null 2>&1
```

### CLI PHP Usage (Unlimited Mode)

For running full syncs from command line without time limits:

```bash
cd /path/to/cscart

# Full hotel info sync (no time limit)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=hotel_info_batched unlimited=1 force_full=1

# Full priceinfo sync (no time limit)
php index.php dispatch=novoton_cron.run access_key=YOUR_KEY mode=sync_priceinfo_batched unlimited=1 force_full=1
```

Benefits of CLI with `unlimited=1`:
- No time limits - processes ALL items in one run
- No HTTP timeout issues
- Better for initial setup or full re-syncs
- Memory-efficient (still uses batched processing internally)

---

## Exchange Rates

The addon includes automatic exchange rate updates from BNR (National Bank of Romania) API.

### Features

- Fetches EUR, USD, GBP rates from BNR XML API
- Applies configurable "Currency Risk Commission" (0-5%, default 1.8%)
- Updates CS-Cart currency coefficients automatically
- EUR is the primary currency (coefficient = 1)
- RON, USD, GBP coefficients are calculated relative to EUR

### Admin Page

**Admin → Novoton Holidays → Exchange Rates** (`novoton_exchange_rates.manage`)

Shows:
- Current currency coefficients
- Last update timestamp
- Commission percentage
- Manual update button
- Cron URLs for automation

### Cron URLs

```bash
# Frontend cron (requires cron_password)
curl "https://yoursite.com/index.php?dispatch=novoton_exchange_rates.cron&cron_password=YOUR_KEY"

# Admin cron (requires admin login)
# Access via browser when logged in as admin
```

### How It Works

1. Fetches XML from `https://www.bnr.ro/nbrfxrates.xml`
2. Parses EUR, USD, GBP rates (in RON)
3. Calculates coefficients relative to EUR
4. Applies commission percentage
5. Updates `cscart_currencies` table via `fn_update_currency()`

---

## API Functions

The `NovotonApi` class (`src/NovotonApi.php`) provides these methods:

### Hotel Information

| Method | Description |
|--------|-------------|
| `getHotelList($country, $city, $hotel, $hotelType)` | Get list of hotels |
| `getHotelInfo($hotelId, $lang)` | Get hotel details |
| `getHotelDescription($hotelId, $lang)` | Get hotel description |
| `getHotelImages($hotelId, $lang)` | Get hotel images |

### Availability & Pricing

| Method | Description |
|--------|-------------|
| `searchAvailability($params)` | Search available rooms |
| `getRoomPrice($params)` | Get room price with terms |
| `getHotelQuota($hotelId, $roomId, $checkIn, $checkOut)` | Get room availability |
| `getHotelQuotaAll($hotelId, $checkIn, $checkOut)` | Get all rooms availability |
| `getPriceInfo($hotelId, $packageName)` | Get price info for package |

### Booking

| Method | Description |
|--------|-------------|
| `createReservation($bookingData)` | Create a booking |
| `getReservationStatus($reservationId)` | Check booking status |
| `cancelReservation($reservationId)` | Cancel a booking |

### Utility

| Method | Description |
|--------|-------------|
| `applyCommission($price)` | Add commission to price |
| `clearCache($function)` | Clear API cache |
| `getLastRequest()` | Debug: last API request |
| `getLastResponse()` | Debug: last API response |
| `getLastError()` | Debug: last error message |
| `getCircuitStatus()` | Get circuit breaker status |

### API Resilience Features

The API client includes built-in resilience patterns:

#### Retry with Exponential Backoff
- **Max Retries:** 3 attempts
- **Initial Delay:** 1 second
- **Backoff Multiplier:** 2x (delays: 1s, 2s, 4s)
- **Retryable Errors:** Network timeouts, connection refused, 5xx errors, 429 rate limiting

#### Circuit Breaker Pattern
- **Threshold:** 5 consecutive failures opens circuit
- **Timeout:** 60 seconds before half-open retry
- **Auto-Recovery:** Successful request resets failure counter
- Monitor status: `$api->getCircuitStatus()`

### Webhook Callbacks (Not Supported)

The Novoton API does **not** support webhook callbacks. Based on analysis of all 27 API functions:

1. **No callback URL parameters** in any API request
2. **Polling-based architecture** - use `alternative_RS` to check for alternatives
3. **Manual status checks** - use `resinfo` to check booking status

**Recommended Polling Strategy:**
- Check `resinfo` for booking status changes every 30 minutes via cron
- Check `alternative_RS` for alternative offers every 6 hours
- Check `offers_update` for price changes daily

If Novoton adds webhook support in the future, contact them directly for documentation.

---

## Database Schema

### Main Tables

#### `cscart_novoton_hotels`
Stores synced hotel information.

| Column | Type | Description |
|--------|------|-------------|
| hotel_id | varchar(50) | Novoton hotel ID (PK) |
| product_id | int | Linked CS-Cart product ID |
| hotel_name | varchar(255) | Hotel name |
| country | varchar(100) | Country |
| city | varchar(100) | City/Resort |
| region | varchar(100) | Region |
| hotel_type | varchar(10) | Star rating |
| description_en | text | English description |
| description_ro | text | Romanian description |
| hotel_data | longtext | JSON hotel details (V3) |
| has_prices | enum('Y','N') | Has active prices |
| packages_count | int | Number of packages |
| hotelinfo_synced_at | datetime | Last hotelinfo sync |
| last_price_check | datetime | Last price check |
| created_at | datetime | Created timestamp |
| updated_at | datetime | Updated timestamp |

#### `cscart_novoton_hotel_packages`
Stores hotel packages (V3 architecture).

| Column | Type | Description |
|--------|------|-------------|
| hotel_id | varchar(50) | Hotel ID (FK) |
| package_id | varchar(100) | Package ID |
| package_name | varchar(255) | Package name |
| min_price | decimal(10,2) | Minimum price |
| has_early_booking | tinyint | Has early booking |
| priceinfo_data | longtext | JSON price info |
| synced_at | datetime | Last sync timestamp |
| created_at | datetime | Created timestamp |

#### `cscart_novoton_bookings`
Stores customer bookings.

| Column | Type | Description |
|--------|------|-------------|
| booking_id | int | Auto-increment PK |
| order_id | int | CS-Cart order ID |
| user_id | int | Customer user ID |
| product_id | int | CS-Cart product ID |
| hotel_id | varchar(50) | Novoton hotel ID |
| hotel_name | varchar(255) | Hotel name |
| room_id | varchar(100) | Room type code |
| room_type | varchar(255) | Room type name |
| board_id | varchar(20) | Board type code |
| board_name | varchar(100) | Board type display name |
| item_id | varchar(32) | CS-Cart order item ID |
| check_in | date | Check-in date |
| check_out | date | Check-out date |
| nights | int | Number of nights |
| adults | int | Number of adults |
| children | int | Number of children |
| children_ages | varchar(100) | Children ages JSON |
| num_rooms | int | Number of rooms |
| room_number | int | Room group number (for multi-group) |
| total_rooms | int | Total room groups in booking |
| rooms_data | text | JSON room details |
| guests_data | text | JSON guest details |
| holder_name | varchar(255) | Main guest name |
| guest_email | varchar(255) | Contact email |
| guest_phone | varchar(50) | Contact phone |
| total_price | decimal(10,2) | Total price EUR |
| novoton_reservation_id | varchar(100) | Novoton booking reference |
| novoton_status | varchar(50) | Novoton booking status |
| status | varchar(20) | Internal status |
| special_requests | text | Special requests |
| api_request | text | Sent API request |
| api_response | text | Received API response |
| created_at | datetime | Created timestamp |
| updated_at | datetime | Updated timestamp |

#### `cscart_novoton_facilities`
Stores facility definitions.

| Column | Type | Description |
|--------|------|-------------|
| facility_id | int | Facility ID (PK) |
| facility_name_en | varchar(255) | English name |
| facility_name_ro | varchar(255) | Romanian name |

#### `cscart_novoton_sync_log`
Logs synchronization history.

| Column | Type | Description |
|--------|------|-------------|
| log_id | int | Auto-increment PK |
| sync_type | varchar(50) | Type: hotellist, hotelinfo, prices, offers_update, facilities |
| sync_date | datetime | Sync timestamp |
| products_total | int | Total items processed |
| products_updated | int | Items updated |
| products_failed | int | Items failed |
| duration_seconds | int | Duration in seconds |
| status | varchar(20) | Status: completed, failed |

---

## Architecture

### Directory Structure

```
app/addons/novoton_holidays/
├── addon.xml                 # Addon definition
├── init.php                  # Initialization
├── func.php                  # Helper functions
├── hooks.php                 # CS-Cart hooks
├── cron.php                  # CLI cron entry
├── Constants.php             # Centralized constants
│
├── controllers/
│   ├── backend/              # Admin controllers
│   │   ├── novoton_holidays.php      # Main + dashboard
│   │   ├── novoton_hotels.php        # Hotel management
│   │   ├── novoton_prices.php        # Price management
│   │   ├── novoton_tools.php         # Tools & tests
│   │   ├── novoton_bookings.php      # Bookings
│   │   ├── novoton_alternatives.php  # Alternatives
│   │   ├── novoton_exchange_rates.php # Exchange rates
│   │   └── novoton_diagnostic.php    # Diagnostics
│   └── frontend/             # Customer controllers
│       ├── novoton_booking.php
│       ├── novoton_cron.php
│       ├── novoton_holidays.php
│       └── novoton_exchange_rates.php # Exchange rates cron
│
├── functions/                # Helper function files
│   ├── formatting.php        # Date/text formatting
│   ├── email.php             # Email helpers
│   └── exchange_rates.php    # BNR exchange rate functions
│
├── Repository/               # Data repositories
│   ├── HotelRepository.php
│   ├── BookingRepository.php
│   └── SyncLogRepository.php
│
├── services/                 # Service classes
│   ├── BookingService.php
│   ├── CacheService.php
│   ├── DateHelper.php
│   ├── GuestDataService.php
│   ├── PriceService.php
│   ├── SearchService.php
│   └── SecurityService.php
│
├── src/                      # Core classes
│   ├── NovotonApi.php        # API client
│   ├── HotelSync.php         # V3 Hotel synchronization
│   └── PriceInfoSync.php     # Priceinfo synchronization
│
├── Helpers/                  # Helper classes
│   ├── DatabaseIterator.php  # PHP Generators for memory-efficient iteration
│   ├── BatchedHotelInfoSync.php  # Batched hotel sync with resume
│   └── BatchedPriceInfoSync.php  # Batched priceinfo sync with resume
│
└── schemas/                  # CS-Cart schemas
    └── block_manager/
```

### Service Classes

| Service | Purpose |
|---------|---------|
| `BookingService` | Create, update, retrieve bookings |
| `CacheService` | API response caching |
| `GuestDataService` | Parse and format guest data |
| `PriceService` | Price calculations with commission |
| `SearchService` | Search parameter parsing |
| `SecurityService` | Input validation and CSRF |
| `DateHelper` | Date formatting and calculations |

### Booking Data Architecture (Single Source of Truth)

The addon uses `novoton_bookings` table as the **single source of truth** for all booking data.

#### Data Flow

```
Cart Add → novoton_bookings (order_id=0, pending)
     ↓
Checkout → place_order hook → UPDATE novoton_bookings (set order_id)
     ↓
API Submit → UPDATE novoton_bookings (novoton_status, novoton_invoice_id)
```

#### Key Principles

1. **One Canonical Source**: All booking data lives in `novoton_bookings` table
2. **No Duplicates**: place_order hook always UPDATEs existing bookings instead of INSERT
3. **Order Details for Display**: `order_details.extra` stores reference data for order display only
4. **Admin Reads from DB**: `getUnifiedBookings()` reads directly from `novoton_bookings` table

#### BookingRepository Methods

| Method | Description |
|--------|-------------|
| `findById($id)` | Find booking by ID |
| `findByOrderId($orderId)` | Find bookings for an order |
| `findByUserId($userId)` | Find bookings for a user |
| `getUnifiedBookings($params)` | Get bookings with filters and joins |
| `create($data)` | Create new booking |
| `update($id, $data)` | Update existing booking |
| `deleteOrphans($hours)` | Delete abandoned cart bookings |

#### Orphan Cleanup

Orphan bookings (order_id=0, older than 24h) are abandoned cart items that can be safely deleted via the "Cleanup Orphan Bookings" button in admin.

---

## React Booking Engine

The addon includes a React 19-based booking form for an enhanced user experience.

### Components

| Component | File | Purpose |
|-----------|------|---------|
| `BookingEngine` | booking-form.jsx | Main form container |
| `Calendar` | booking-form.jsx | Two-month date range picker |
| `GuestPicker` | booking-form.jsx | Multi-room adults/children selector |

### Features

- **Date Selection:** Two-month calendar with check-in/check-out range selection
- **Guest Management:** Multi-room support (up to 12 rooms), adults (1-9), children (0-4) per room
- **Age Validation:** Required age selection for children with visual error indicators
- **Localization:** Auto-detects Romanian/English based on page language
- **Responsive:** Mobile-friendly with collapsible popups

### Bundle Details

- **File:** `js/addons/novoton_holidays/react19-bundle.js`
- **Size:** ~218 KB (minified production build)
- **Includes:** React 19, ReactDOM, booking components

### Styling

- CSS file: `css/addons/novoton_holidays/booking-form-react.css`
- Yellow border design (Booking.com style)
- CSS variables for easy customization

---

## Troubleshooting

### Health Check Endpoint

Access: `admin.php?dispatch=novoton_diagnostic.health`

Returns JSON response for automated monitoring:

```json
{
  "status": "healthy",
  "timestamp": "2026-02-10T12:00:00+00:00",
  "version": "3.0.0-A84",
  "components": {
    "database": { "status": "healthy", "response_time_ms": 2.5 },
    "api": { "status": "healthy", "circuit_breaker": {...} },
    "cache": { "status": "healthy", "persistent_items": 42 },
    "sync": { "status": "healthy", "hours_since_sync": 2.5 }
  },
  "metrics": {
    "bookings_24h": 15,
    "pending_bookings": 3,
    "failed_bookings_24h": 0,
    "failure_rate_24h": "0%"
  }
}
```

**Status Values:**
- `healthy` - All systems operational
- `degraded` - Non-critical issues detected
- `unhealthy` - Critical system failure

Use this endpoint with monitoring tools (Uptime Robot, Pingdom, etc.) to track addon health.

### Debug Mode

Add `?debug_novoton=1` to any page URL to see debug information.

### Common Issues

#### "Service unavailable" when sending emails
- Check **Administration → Settings → E-mails** configuration
- Verify SMTP settings or PHP mail() is working

#### Hotels not syncing
- Check API credentials in addon settings
- View sync logs on Dashboard
- Test API: **Novoton Holidays → Diagnostic**

#### Prices showing 0
- Run price sync cron job (`mode=room_price`)
- Check if hotel has `has_prices = 'Y'`
- Verify commission setting is not 0%

#### Booking fails with "Not available"
- Room may have sold out between search and booking
- Try different dates or room type

#### Dashboard shows "Never" for Last Sync
- Run the corresponding cron job
- Check if cron jobs have the correct `access_key`

#### Exchange rates not updating
- Check BNR API connectivity
- Verify `cron_password` parameter
- Check commission is between 0-5%

### Log Files

Addon logs events to CS-Cart's logging system:
- **Administration → Logs** - Filter by "novoton"

---

## Changelog

### Version 3.0.0 (February 10, 2026)
- **API Resilience:** Added retry logic with exponential backoff (3 attempts, 1s/2s/4s delays)
- **API Resilience:** Added circuit breaker pattern (5 failures opens circuit for 60s)
- **Monitoring:** Added structured health check endpoint (`novoton_diagnostic.health`)
  - JSON response for automated monitoring
  - Component health: database, API, cache, sync
  - Key metrics: bookings, failures, hotels with prices
- **API:** Added `getCircuitStatus()` method to check circuit breaker state
- **Documentation:** Added webhook callback explanation (not supported by Novoton API)
- **Documentation:** Added API resilience features section
- **Fixed:** All code leftovers and inconsistencies from previous refactor

### Version 2.9.4 (February 10, 2026)
- **Architecture:** Implemented Single Source of Truth for booking data
  - `novoton_bookings` table is now the canonical source for all booking data
  - `getUnifiedBookings()` reads directly from database instead of parsing order_details.extra
  - place_order hook now UPDATEs existing bookings instead of creating duplicates
  - Simplified orphan cleanup (just DELETE old records without order_id)
- **Fixed:** XML packages extraction - multiple `<packages>` siblings now correctly iterated
- **Fixed:** XML seasons extraction - multiple `<seasons>` siblings now correctly iterated
- **Fixed:** 404 error on "Add Hotels as Products" admin page (controller return status)
- **Fixed:** Dashboard "Last Sync" showing "Never" for Hotel List and Prices
- **Fixed:** Smarty template error "Not matching {capture}{/capture}" in booking view
- **Fixed:** Booking view template now properly displays Order ID and prices
- **Added:** `room_number` and `total_rooms` fields for multi-group booking display
- **Updated:** BookingRepository with new methods and optimized queries
- **Updated:** Documentation with architecture details and database schema

### Version 2.9.3 (February 6, 2026)
- **Added:** `exchange_rates` mode to main cron controller for BNR rate updates
- **Removed:** Legacy `hotel_info` mode - use `hotel_info_batched` instead
- **Removed:** Legacy `sync_hotels` mode - use batched modes instead
- **Removed:** Legacy `check_packages` mode - redundant with `hotel_info_batched`
- **Simplified:** Cron architecture now uses only batched modes with resume capability
- **Improved:** All cron jobs now use consistent URL pattern (`novoton_cron.run&mode=XXX`)

### Version 2.9.2 (February 6, 2026)
- **Removed:** Legacy `sync_priceinfo` mode - use `sync_priceinfo_batched` instead
- **Cleaned:** Removed all legacy mode references from cron controller, backend, and documentation

### Version 2.9.1 (February 6, 2026)
- **Added:** `sync_priceinfo_batched` mode - Smart batched priceinfo sync with resume capability
- **Added:** `&unlimited=1` parameter for CLI PHP usage (no time limits)
- **Added:** PHP Generators for memory-efficient large dataset processing
- **Added:** CLI PHP usage documentation for running full syncs
- **Updated:** Recommended crontab now uses batched modes for both hotel info and priceinfo
- **Updated:** CRON_JOBS.txt with comprehensive documentation for all modes

### Version 2.9.0 (February 5, 2026)
- **Added:** Exchange rate auto-update from BNR (National Bank of Romania) API
- **Added:** Currency risk commission setting (0-5%, default 1.8%)
- **Added:** Exchange rates admin page with manual update button
- **Added:** Payment terms with calculated amounts display (e.g., "10% (150.00€)")
- **Added:** Cancellation terms display on order details and emails
- **Added:** All menu items with proper language keys (EN/RO)
- **Fixed:** 404 error on Add Hotels as Products admin page
- **Fixed:** Cron hotel_info mode limit=0 causing no processing
- **Fixed:** Cron add_hotels_as_products default limit (now unlimited)
- **Fixed:** Sync log entries now recorded for all cron modes
- **Fixed:** Dashboard Last Sync section showing correct sync types
- **Fixed:** Removed incorrect "deprecated" warning from hotel_info mode
- **Updated:** Dashboard shows Hotel List, Hotel Info, Prices, Offers Update, Facilities
- **Updated:** Date formatting to use CS-Cart settings

### Version 2.8.0-A80D (January 30, 2026)
- **Added:** Important field display in Room Type column with warning styling
- **Added:** MoreInfo field display in Room Type column with green checkmark
- **Fixed:** Board name formatting without emoji in Choices column
- **Fixed:** Payment and cancellation terms XML parsing

### Version 2.8.0-A80A (January 29, 2026)
- **Fixed:** Payment/cancellation terms variable naming
- **Fixed:** XML parsing rewrite for `<conditions>` structure

### Version 2.8.0-A79Y (January 28, 2026)
- **Added:** Payment Terms and Cancellation Terms columns in search results
- **Enhanced:** Choices column with 8 features including board name

### Previous Versions
- Dashboard 404 fixes
- Cron mapping corrections
- Database column additions
- API test error handling
- Excluded resorts UI
- Room code regex improvements
- URL encoding for API parameters

---

## Support

- **Developer:** VacanteLitoral.ro
- **Website:** https://vacantelitoral.ro

---

*Documentation last updated: February 10, 2026 - Version 3.0.0*
