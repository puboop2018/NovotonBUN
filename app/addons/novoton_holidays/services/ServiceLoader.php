<?php
/**
 * Novoton Holidays - Service Loader
 * 
 * Provides lazy-loaded singleton access to all service classes.
 * Include this file to get access to service getters.
 * 
 * Usage:
 *   $bookingService = _nvt_booking_service();
 *   $guestService = _nvt_guest_service();
 *   $searchService = _nvt_search_service();
 *   $priceService = _nvt_price_service();
 *   $securityService = _nvt_security_service();
 *   $cacheService = _nvt_cache_service();
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Addons\NovotonHolidays\Services\BookingService;
use Tygh\Addons\NovotonHolidays\Services\GuestDataService;
use Tygh\Addons\NovotonHolidays\Services\SearchService;
use Tygh\Addons\NovotonHolidays\Services\PriceService;
use Tygh\Addons\NovotonHolidays\Services\SecurityService;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\ValidationHelper;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoService;
use Tygh\Addons\NovotonHolidays\Repository\HotelRepository;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Auto-load service classes
$services_dir = Registry::get('config.dir.addons') . 'novoton_holidays/services/';
$repository_dir = Registry::get('config.dir.addons') . 'novoton_holidays/Repository/';
$helpers_dir = Registry::get('config.dir.addons') . 'novoton_holidays/Helpers/';

// Load services
foreach (['BookingService', 'GuestDataService', 'SearchService', 'PriceService',
          'SecurityService', 'CacheService', 'ValidationHelper', 'PriceInfoService'] as $class) {
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
 * Get PriceService singleton
 * Handles price calculations, commission application
 * 
 * @return PriceService
 */
function _nvt_price_service() {
    static $instance = null;
    if ($instance === null) {
        $instance = new PriceService();
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
 * Handles price info retrieval and formatting
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
