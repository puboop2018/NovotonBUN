# Novoton Holidays - CS-Cart Addon

**Version:** 2.8.0-A80D  
**Last Updated:** January 30, 2026  
**Compatibility:** CS-Cart 4.x  
**Developer:** VacanteLitoral.ro

Complete hotel booking integration with Novoton XML API for CS-Cart.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin Panel Pages](#admin-panel-pages)
- [Cron Jobs](#cron-jobs)
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
- Payment and cancellation terms display
- Price per person and total price columns

### Admin Features
- Booking management with Novoton sync
- Hotel import wizard
- Sync logs and diagnostics
- Excluded resorts management
- API response caching
- Email notifications with CSV reports

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

---

## Admin Panel Pages

### Main Menu: Novoton Holidays

| Page | URL | Description |
|------|-----|-------------|
| Dashboard | `novoton_holidays.manage` | Overview and quick actions |
| Hotels List | `novoton_holidays.hotels` | View synced hotels |
| Add Hotels as Products | `novoton_holidays.add_hotels_as_products` | Import hotels to CS-Cart |
| Bookings | `novoton_bookings.manage` | Manage customer bookings |
| Facilities | `novoton_holidays.list_facilities` | Hotel facilities list |
| Alternatives | `novoton_alternatives.manage` | Alternative room requests |

### Tools & Diagnostics

| Page | URL | Description |
|------|-----|-------------|
| Sync Logs | `novoton_admin.sync_logs` | View synchronization history |
| API Diagnostics | `novoton_diagnostic.index` | Test API connectivity |
| Test Hotel Request | `novoton_holidays.test_hotel_request` | Debug hotel API calls |
| Test Alternatives | `novoton_holidays.test_alternative_rs` | Debug alternative search |

---

## Cron Jobs

### Authentication

All cron URLs require the `access_key` parameter matching your configured **Cron Access Key**.

### Available Cron Modes

#### 1. Hotel Sync (ResInfo)
Syncs hotel list from Novoton API.

```bash
# URL method
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo"

# CLI method
php /path/to/cscart/app/addons/novoton_holidays/cron.php access_key=YOUR_KEY mode=resinfo

# With country filter
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo&country=BULGARIA"
```

#### 2. Price Sync
Updates hotel prices.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=prices"
```

#### 3. Offers Update (Delta Sync)
Syncs only changed offers since last update.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=offers_update"
```

#### 4. Facilities Sync
Updates hotel facilities list.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=facilities"
```

#### 5. Detailed Info Sync
Fetches detailed info for hotels without packages data.

```bash
# Fetch all hotels without data
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=detailed_info"

# With limit
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=detailed_info&detail_limit=100"
```

#### 6. CSV Export
Exports hotel features to CSV.

```bash
curl "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=export_csv"
```

### Recommended Crontab

```crontab
# Hotel sync - daily at 3 AM
0 3 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo"

# Price sync - every 6 hours
0 */6 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=prices"

# Offers update - every 2 hours
0 */2 * * * curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=offers_update"

# Facilities sync - weekly on Sunday at 4 AM
0 4 * * 0 curl -s "https://yoursite.com/index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=facilities"
```

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
| city | varchar(100) | City |
| region | varchar(100) | Region |
| stars | tinyint | Star rating (1-5) |
| description_en | text | English description |
| description_ro | text | Romanian description |
| packages_data | text | JSON packages info |
| rooms_data | text | JSON rooms info |
| boards_data | text | JSON board types |
| has_prices | enum('Y','N') | Has active prices |
| last_sync | datetime | Last sync timestamp |

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
| check_in | date | Check-in date |
| check_out | date | Check-out date |
| nights | int | Number of nights |
| adults | int | Number of adults |
| children | int | Number of children |
| children_ages | varchar(100) | Children ages JSON |
| num_rooms | int | Number of rooms |
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

#### `cscart_novoton_hotel_facilities`
Links hotels to facilities.

| Column | Type | Description |
|--------|------|-------------|
| hotel_id | varchar(50) | Hotel ID (FK) |
| facility_id | int | Facility ID (FK) |

#### `cscart_novoton_sync_log`
Logs synchronization history.

| Column | Type | Description |
|--------|------|-------------|
| log_id | int | Auto-increment PK |
| sync_type | varchar(50) | Type of sync |
| sync_date | datetime | Sync timestamp |
| hotels_synced | int | Hotels processed |
| hotels_added | int | Hotels added |
| hotels_updated | int | Hotels updated |
| errors | int | Error count |
| duration | int | Duration seconds |
| details | text | JSON details |

#### `cscart_novoton_cache`
Stores API response cache.

| Column | Type | Description |
|--------|------|-------------|
| cache_key | varchar(255) | Cache key (PK) |
| cache_data | longtext | Cached data |
| expires_at | datetime | Expiration time |
| created_at | datetime | Created timestamp |

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
│   │   ├── novoton_holidays.php
│   │   ├── novoton_bookings.php
│   │   ├── novoton_admin.php
│   │   ├── novoton_alternatives.php
│   │   └── novoton_diagnostic.php
│   └── frontend/             # Customer controllers
│       ├── novoton_booking.php
│       ├── novoton_cron.php
│       └── novoton_holidays.php
│
├── services/                 # Service classes
│   ├── BookingService.php
│   ├── CacheService.php
│   ├── DateHelper.php
│   ├── ErrorHandler.php
│   ├── GuestDataService.php
│   ├── LoggerTrait.php
│   ├── PriceInfoService.php
│   ├── PriceService.php
│   ├── SearchService.php
│   ├── SecurityService.php
│   └── ValidationHelper.php
│
├── src/                      # Core classes
│   ├── NovotonApi.php        # API client
│   ├── HotelSync.php         # V3 Hotel synchronization
│   └── PriceInfoSync.php     # Priceinfo synchronization
│
└── schemas/                  # CS-Cart schemas
    ├── block_manager/
    └── static_templates/
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
| `ErrorHandler` | Centralized error handling |

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

### Loading Locations

The React bundle loads on:
1. Product detail pages (booking block)
2. Search results page (search form modification)
3. Homepage booking widget (optional)

---

## Troubleshooting

### Debug Mode

Add `?debug_novoton=1` to any page URL to see debug information.

**Examples:**
- Order details: `orders.details&order_id=123&debug_novoton=1`
- Booking form: `novoton_booking.booking_form&product_id=456&debug_novoton=1`

### Common Issues

#### "Service unavailable" when sending emails
- Check **Administration → Settings → E-mails** configuration
- Verify SMTP settings or PHP mail() is working

#### Hotels not syncing
- Check API credentials in addon settings
- View sync logs: `novoton_admin.sync_logs`
- Test API: `novoton_diagnostic.index`

#### Prices showing 0
- Run price sync cron job
- Check if hotel has `has_prices = 'Y'`
- Verify commission setting is not 0%

#### Booking fails with "Not available"
- Room may have sold out between search and booking
- Try different dates or room type

#### Smarty template errors with curly braces
- Ensure CSS/JS are in external files, not inline
- Use `{literal}...{/literal}` for any inline scripts

### Log Files

Addon logs events to CS-Cart's logging system:
- **Administration → Logs** - Filter by "novoton"

### API Debugging

Use the diagnostic page to test API calls:
- **Novoton Holidays → API Diagnostics**

Or use test pages:
- `novoton_holidays.test_hotel_request` - Test hotel info requests
- `novoton_holidays.test_alternative_rs` - Test alternative search

---

## Changelog

### Version 2.8.0-A80D (January 30, 2026)
- **Added:** Important field display in Room Type column with warning styling (⚠️ yellow box)
- **Added:** MoreInfo field display in Room Type column with green checkmark (✓)
- **Fixed:** Board name formatting without emoji in Choices column
- **Fixed:** Payment and cancellation terms XML parsing for Novoton API format
- **Enhanced:** Choices column shows only free cancellation date

### Version 2.8.0-A80A (January 29, 2026)
- **Fixed:** Payment/cancellation terms variable naming (`payment_terms`, `cancellation_terms`)
- **Fixed:** XML parsing rewrite for `<conditions>` structure

### Version 2.8.0-A79Y (January 28, 2026)
- **Added:** Payment Terms and Cancellation Terms columns in search results
- **Enhanced:** Choices column with 8 features including board name

### Version 2.8.0-A79X (January 27, 2026)
- **Fixed:** Facilities sync array handling error
- **Fixed:** Country selection logic in admin settings

### Previous Versions (A79T-A79W)
- Dashboard 404 fixes
- Cron mapping corrections
- Database column additions
- API test error handling
- Excluded resorts UI
- Room code regex improvements
- Booking view template fixes
- SQL NULLS FIRST compatibility
- URL encoding for API parameters

---

## Support

- **Developer:** VacanteLitoral.ro
- **Website:** https://vacantelitoral.ro

---

*Documentation last updated: January 30, 2026 - Version 2.8.0-A80D*
