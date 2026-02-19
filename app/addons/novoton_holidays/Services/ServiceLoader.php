<?php
/**
 * Novoton Holidays - Service Loader
 *
 * Provides global accessor functions that delegate to the DI Container.
 * These functions exist for use in procedural code (hooks, controllers)
 * where constructor injection is not possible.
 *
 * @package NovotonHolidays
 * @since 2.8.0 (container-backed since 3.3.0)
 */

use Tygh\Addons\NovotonHolidays\Services\Container;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// =============================================================================
// SERVICE GETTERS
// =============================================================================

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\BookingService
 */
function _nvt_booking_service() {
    return Container::getInstance()->bookingService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\GuestDataService
 */
function _nvt_guest_service() {
    return Container::getInstance()->guestDataService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\SearchService
 */
function _nvt_search_service() {
    return Container::getInstance()->searchService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\RoomPriceService
 */
function _nvt_price_service() {
    return Container::getInstance()->roomPriceService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\SecurityService
 */
function _nvt_security_service() {
    return Container::getInstance()->securityService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\CacheService
 */
function _nvt_cache_service() {
    return Container::getInstance()->cacheService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\ValidationHelper
 */
function _nvt_validation_helper() {
    return Container::getInstance()->validationHelper();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\PriceInfoService
 */
function _nvt_price_info_service() {
    return Container::getInstance()->priceInfoService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\DateHelper
 */
function _nvt_date_helper() {
    return Container::getInstance()->dateHelper();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\CronService
 */
function _nvt_cron_service() {
    return Container::getInstance()->cronService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\DiagnosticsService
 */
function _nvt_diagnostics_service() {
    return Container::getInstance()->diagnosticsService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\AlternativeRequestService
 */
function _nvt_alternative_request_service() {
    return Container::getInstance()->alternativeRequestService();
}

// =============================================================================
// REPOSITORY GETTERS
// =============================================================================

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\HotelRepository
 */
function _nvt_hotel_repo() {
    return Container::getInstance()->hotelRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\BookingRepository
 */
function _nvt_booking_repo() {
    return Container::getInstance()->bookingRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\FacilityRepository
 */
function _nvt_facility_repo() {
    return Container::getInstance()->facilityRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository
 */
function _nvt_sync_log_repo() {
    return Container::getInstance()->syncLogRepository();
}

// =============================================================================
// HELPER GETTERS
// =============================================================================

/**
 * @return \Tygh\Addons\NovotonHolidays\Helpers\DatabaseIterator
 */
function _nvt_db_iterator() {
    return Container::getInstance()->databaseIterator();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Helpers\BatchedHotelInfoSync
 */
function _nvt_batched_hotelinfo_sync() {
    return Container::getInstance()->batchedHotelInfoSync();
}
