<?php
declare(strict_types=1);
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

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// =============================================================================
// SERVICE GETTERS
// =============================================================================

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\BookingServiceInterface
 */
function _nvt_booking_service(): \Tygh\Addons\NovotonHolidays\Services\BookingServiceInterface {
    return Container::getInstance()->bookingService();
}

/**
 * @return \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface
 */
function _nvt_guest_service(): \Tygh\Addons\TravelCore\Contracts\GuestDataServiceInterface {
    return Container::getInstance()->guestDataService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\SearchServiceInterface
 */
function _nvt_search_service(): \Tygh\Addons\NovotonHolidays\Services\SearchServiceInterface {
    return Container::getInstance()->searchService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\RoomPriceServiceInterface
 */
function _nvt_price_service(): \Tygh\Addons\NovotonHolidays\Services\RoomPriceServiceInterface {
    return Container::getInstance()->roomPriceService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface
 */
function _nvt_security_service(): \Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface {
    return Container::getInstance()->securityService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface
 */
function _nvt_cache_service(): \Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface {
    return Container::getInstance()->cacheService();
}

/**
 * @return \Tygh\Addons\TravelCore\Services\ValidationHelper
 */
function _nvt_validation_helper(): \Tygh\Addons\TravelCore\Services\ValidationHelper {
    return Container::getInstance()->validationHelper();
}

/**
 * @return \Tygh\Addons\TravelCore\Services\CurrencyService
 */
function _nvt_currency_service(): \Tygh\Addons\TravelCore\Services\CurrencyService {
    return Container::getInstance()->currencyService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\PriceInfoServiceInterface
 */
function _nvt_price_info_service(): \Tygh\Addons\NovotonHolidays\Services\PriceInfoServiceInterface {
    return Container::getInstance()->priceInfoService();
}

/**
 * @return \Tygh\Addons\TravelCore\Services\DateHelper
 */
function _nvt_date_helper(): \Tygh\Addons\TravelCore\Services\DateHelper {
    return Container::getInstance()->dateHelper();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\CronServiceInterface
 */
function _nvt_cron_service(): \Tygh\Addons\NovotonHolidays\Services\CronServiceInterface {
    return Container::getInstance()->cronService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\DiagnosticsServiceInterface
 */
function _nvt_diagnostics_service(): \Tygh\Addons\NovotonHolidays\Services\DiagnosticsServiceInterface {
    return Container::getInstance()->diagnosticsService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\AlternativeRequestServiceInterface
 */
function _nvt_alternative_request_service(): \Tygh\Addons\NovotonHolidays\Services\AlternativeRequestServiceInterface {
    return Container::getInstance()->alternativeRequestService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\BookingSubmissionServiceInterface
 */
function _nvt_booking_submission_service(): \Tygh\Addons\NovotonHolidays\Services\BookingSubmissionServiceInterface {
    return Container::getInstance()->bookingSubmissionService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\NovotonApi
 */
function _nvt_api(): \Tygh\Addons\NovotonHolidays\NovotonApi {
    return Container::getInstance()->novotonApi();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Services\AdminCronService
 */
function _nvt_admin_cron_service(): \Tygh\Addons\NovotonHolidays\Services\AdminCronService {
    return Container::getInstance()->adminCronService();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector
 */
function _nvt_property_type_detector(): \Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector {
    return Container::getInstance()->propertyTypeDetector();
}

// =============================================================================
// REPOSITORY GETTERS
// =============================================================================

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface
 */
function _nvt_hotel_repo(): \Tygh\Addons\NovotonHolidays\Repository\HotelRepositoryInterface {
    return Container::getInstance()->hotelRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface
 */
function _nvt_booking_repo(): \Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface {
    return Container::getInstance()->bookingRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\FacilityRepositoryInterface
 */
function _nvt_facility_repo(): \Tygh\Addons\NovotonHolidays\Repository\FacilityRepositoryInterface {
    return Container::getInstance()->facilityRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepositoryInterface
 */
function _nvt_sync_log_repo(): \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepositoryInterface {
    return Container::getInstance()->syncLogRepository();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface
 */
function _nvt_alternative_request_repo(): \Tygh\Addons\NovotonHolidays\Repository\AlternativeRequestRepositoryInterface {
    return Container::getInstance()->alternativeRequestRepository();
}

// =============================================================================
// HELPER GETTERS
// =============================================================================

/**
 * @return \Tygh\Addons\NovotonHolidays\Helpers\DatabaseIteratorInterface
 */
function _nvt_db_iterator(): \Tygh\Addons\NovotonHolidays\Helpers\DatabaseIteratorInterface {
    return Container::getInstance()->databaseIterator();
}

/**
 * @return \Tygh\Addons\NovotonHolidays\Helpers\SyncInterface
 */
function _nvt_batched_hotelinfo_sync(): \Tygh\Addons\NovotonHolidays\Helpers\SyncInterface {
    return Container::getInstance()->batchedHotelInfoSync();
}
