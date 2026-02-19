<?php
/**
 * Novoton Holidays - Service Loader
 *
 * Provides lazy-loaded singleton access to all service classes,
 * repositories, helpers, and value objects.
 *
 * Usage:
 *   // --- Services ---
 *   $bookingService    = _nvt_booking_service();
 *   $guestService      = _nvt_guest_service();
 *   $searchService     = _nvt_search_service();
 *   $priceService      = _nvt_price_service();
 *   $priceInfoService  = _nvt_price_info_service();
 *   $securityService   = _nvt_security_service();
 *   $cacheService      = _nvt_cache_service();
 *   $validationHelper  = _nvt_validation_helper();
 *   $dateHelper        = _nvt_date_helper();
 *   $cronService       = _nvt_cron_service();
 *   $diagnostics       = _nvt_diagnostics_service();
 *   $alternatives      = _nvt_alternative_request_service();
 *
 *   // --- Repositories ---
 *   $hotelRepo         = _nvt_hotel_repo();
 *   $bookingRepo       = _nvt_booking_repo();
 *   $facilityRepo      = _nvt_facility_repo();
 *   $syncLogRepo       = _nvt_sync_log_repo();
 *
 *   // --- Helpers ---
 *   $dbIterator        = _nvt_db_iterator();
 *   $batchSync         = _nvt_batched_hotelinfo_sync();
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\BookingService;
use Tygh\Addons\NovotonHolidays\Services\GuestDataService;
use Tygh\Addons\NovotonHolidays\Services\SearchService;
use Tygh\Addons\NovotonHolidays\Services\RoomPriceService;
use Tygh\Addons\NovotonHolidays\Services\SecurityService;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\ValidationHelper;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoService;
use Tygh\Addons\NovotonHolidays\Services\DateHelper;
use Tygh\Addons\NovotonHolidays\Services\CronService;
use Tygh\Addons\NovotonHolidays\Services\DiagnosticsService;
use Tygh\Addons\NovotonHolidays\Services\AlternativeRequestService;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Repository\FacilityRepository;
use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;
use Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepository;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Auto-load service classes
$services_dir = Registry::get('config.dir.addons') . 'novoton_holidays/Services/';
$repository_dir = Registry::get('config.dir.addons') . 'novoton_holidays/Repository/';
$helpers_dir = Registry::get('config.dir.addons') . 'novoton_holidays/Helpers/';
$vo_dir = Registry::get('config.dir.addons') . 'novoton_holidays/ValueObjects/';

// Load services
foreach (['BookingService', 'GuestDataService', 'SearchService', 'RoomPriceService',
          'SecurityService', 'CacheService', 'ValidationHelper', 'PriceInfoService',
          'DateHelper', 'CronService',
          'DiagnosticsService', 'AlternativeRequestService'] as $class) {
    $file = $services_dir . $class . '.php';
    if (file_exists($file) && !class_exists("Tygh\\Addons\\NovotonHolidays\\Services\\{$class}")) {
        require_once $file;
    }
}

// Load repositories
foreach (['HotelRepository', 'BookingRepository', 'FacilityRepository', 'SyncLogRepository'] as $class) {
    $file = $repository_dir . $class . '.php';
    if (file_exists($file) && !class_exists("Tygh\\Addons\\NovotonHolidays\\Repository\\{$class}")) {
        require_once $file;
    }
}

// Load helpers
foreach (['DatabaseIterator', 'BatchedHotelInfoSync'] as $class) {
    $file = $helpers_dir . $class . '.php';
    if (file_exists($file) && !class_exists("Tygh\\Addons\\NovotonHolidays\\Helpers\\{$class}")) {
        require_once $file;
    }
}

// Load value objects
foreach (['BoardType', 'RoomType'] as $class) {
    $file = $vo_dir . $class . '.php';
    if (file_exists($file) && !class_exists("Tygh\\Addons\\NovotonHolidays\\ValueObjects\\{$class}")) {
        require_once $file;
    }
}

// =============================================================================
// SERVICE GETTERS
// =============================================================================

/**
 * Get BookingService singleton
 * Handles booking creation, cart operations, order processing
 *
 * @return BookingService
 */
function _nvt_booking_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new BookingService();
    }
    return $instance;
}

/**
 * Get GuestDataService singleton
 * Handles guest data parsing, validation, formatting
 *
 * @return GuestDataService
 */
function _nvt_guest_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new GuestDataService();
    }
    return $instance;
}

/**
 * Get SearchService singleton
 * Handles search parameter parsing, availability search
 *
 * @return SearchService
 */
function _nvt_search_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new SearchService();
    }
    return $instance;
}

/**
 * Get RoomPriceService singleton
 * Handles real-time room price calculations, commission application
 *
 * @return RoomPriceService
 */
function _nvt_price_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new RoomPriceService();
    }
    return $instance;
}

/**
 * Get SecurityService singleton
 * Handles input validation, sanitization, CSRF protection
 *
 * @return SecurityService
 */
function _nvt_security_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new SecurityService();
    }
    return $instance;
}

/**
 * Get CacheService singleton
 * Handles API response caching
 *
 * @return CacheService
 */
function _nvt_cache_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new CacheService();
    }
    return $instance;
}

/**
 * Get ValidationHelper singleton
 * Handles booking data validation
 *
 * @return ValidationHelper
 */
function _nvt_validation_helper() {
    static $instance = null;
    if ($instance === null) {
        $instance = new ValidationHelper();
    }
    return $instance;
}

/**
 * Get PriceInfoService singleton
 * Handles season price info retrieval and formatting
 *
 * @return PriceInfoService
 */
function _nvt_price_info_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new PriceInfoService();
    }
    return $instance;
}

/**
 * Get DateHelper singleton
 * Date formatting and calculation utilities with Romanian locale support
 *
 * @return DateHelper
 */
function _nvt_date_helper() {
    static $instance = null;
    if ($instance === null) {
        $instance = new DateHelper();
    }
    return $instance;
}

/**
 * Get CronService singleton
 * Centralized service for cron job operations (resort_list sync, hotel_list sync, etc.)
 *
 * @return CronService
 */
function _nvt_cron_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new CronService();
    }
    return $instance;
}

// =============================================================================
// REPOSITORY GETTERS
// =============================================================================

/**
 * Get HotelRepository singleton
 * Database access for hotel data
 *
 * @return HotelRepository
 */
function _nvt_hotel_repo() {
    static $instance = null;
    if ($instance === null) {
        $instance = new HotelRepository();
    }
    return $instance;
}

/**
 * Get BookingRepository singleton
 * Database access for booking data
 *
 * @return BookingRepository
 */
function _nvt_booking_repo() {
    static $instance = null;
    if ($instance === null) {
        $instance = new BookingRepository();
    }
    return $instance;
}

/**
 * Get FacilityRepository singleton
 * Database access for hotel facilities (list_facilities / hotel_facilities API)
 *
 * @return FacilityRepository
 */
function _nvt_facility_repo() {
    static $instance = null;
    if ($instance === null) {
        $instance = new FacilityRepository();
    }
    return $instance;
}

/**
 * Get SyncLogRepository singleton
 * Database access for cron sync history logs
 *
 * @return SyncLogRepository
 */
function _nvt_sync_log_repo() {
    static $instance = null;
    if ($instance === null) {
        $instance = new SyncLogRepository();
    }
    return $instance;
}

// =============================================================================
// HELPER GETTERS
// =============================================================================

/**
 * Get DatabaseIterator singleton
 * Memory-efficient iteration over large datasets using PHP generators
 *
 * Usage:
 *   $iterator = _nvt_db_iterator();
 *   foreach ($iterator->iterateHotels(['country' => 'BULGARIA']) as $hotel) {
 *       // Process each hotel - only one row in memory at a time
 *   }
 *
 * @return DatabaseIterator
 */
function _nvt_db_iterator() {
    static $instance = null;
    if ($instance === null) {
        $instance = new DatabaseIterator();
    }
    return $instance;
}

/**
 * Get BatchedHotelInfoSync instance
 * Handles batched hotel info synchronization with resume capability
 *
 * Usage:
 *   $sync = _nvt_batched_hotelinfo_sync();
 *   $result = $sync->run();              // Auto-detect sync type
 *   $result = $sync->run(['force_full' => true]); // Force full sync
 *   $status = $sync->getStatus();        // Check progress
 *
 * @return \Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSync
 */
function _nvt_batched_hotelinfo_sync() {
    return new \Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSync();
}

/**
 * Get DiagnosticsService singleton
 * Handles API testing, hotel list testing, room price testing, etc.
 *
 * @return DiagnosticsService
 */
function _nvt_diagnostics_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new DiagnosticsService();
    }
    return $instance;
}

/**
 * Get AlternativeRequestService singleton
 * Handles alternative booking request creation (hotel_request API), email notifications
 *
 * @return AlternativeRequestService
 */
function _nvt_alternative_request_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new AlternativeRequestService();
    }
    return $instance;
}
