<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Novoton Holidays Addon - Configuration                                 *
 *   Location: app/addons/novoton_holidays/config.php                       *
 *                                                                          *
 *   NOTE: All addon constants live in Constants.php (single source of      *
 *   truth). This file only defines legacy global defines() for backward    *
 *   compatibility with code that hasn't migrated to Constants:: yet.       *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\NovotonHolidays\Constants;

// Legacy global defines — prefer Constants::* in new code

if (class_exists(Constants::class)) {
    if (!defined('NOVOTON_HOLIDAYS_VERSION'))       { define('NOVOTON_HOLIDAYS_VERSION',       Constants::VERSION); }
    if (!defined('NOVOTON_HOLIDAYS_ADDON_ID'))      { define('NOVOTON_HOLIDAYS_ADDON_ID',      Constants::ADDON_ID); }
    if (!defined('NOVOTON_API_TIMEOUT'))             { define('NOVOTON_API_TIMEOUT',             Constants::API_TIMEOUT); }
    if (!defined('NOVOTON_API_CONNECT_TIMEOUT'))     { define('NOVOTON_API_CONNECT_TIMEOUT',     Constants::API_CONNECT_TIMEOUT); }
    if (!defined('NOVOTON_CACHE_HOTEL_INFO'))        { define('NOVOTON_CACHE_HOTEL_INFO',        Constants::CACHE_TTL_HOTEL_INFO); }
    if (!defined('NOVOTON_CACHE_PRICE_INFO'))        { define('NOVOTON_CACHE_PRICE_INFO',        Constants::CACHE_TTL_PRICE_INFO); }
    if (!defined('NOVOTON_CACHE_SEARCH_RESULTS'))    { define('NOVOTON_CACHE_SEARCH_RESULTS',    Constants::CACHE_TTL_SEARCH_RESULTS); }
    if (!defined('NOVOTON_CACHE_FACILITIES'))        { define('NOVOTON_CACHE_FACILITIES',        Constants::CACHE_TTL_FACILITIES); }
    if (!defined('NOVOTON_CRON_BATCH_SIZE'))         { define('NOVOTON_CRON_BATCH_SIZE',         Constants::CRON_BATCH_SIZE); }
    if (!defined('NOVOTON_CRON_CHECK_INTERVAL'))     { define('NOVOTON_CRON_CHECK_INTERVAL',     Constants::CRON_CHECK_INTERVAL); }
    if (!defined('NOVOTON_MAX_ROOMS_PER_BOOKING'))   { define('NOVOTON_MAX_ROOMS_PER_BOOKING',   Constants::MAX_ROOMS); }
    if (!defined('NOVOTON_MAX_ADULTS_PER_ROOM'))     { define('NOVOTON_MAX_ADULTS_PER_ROOM',     Constants::MAX_ADULTS_PER_ROOM); }
    if (!defined('NOVOTON_MAX_CHILDREN_PER_ROOM'))   { define('NOVOTON_MAX_CHILDREN_PER_ROOM',   Constants::MAX_CHILDREN_PER_ROOM); }
    if (!defined('NOVOTON_CHILD_MAX_AGE'))           { define('NOVOTON_CHILD_MAX_AGE',           Constants::MAX_CHILD_AGE); }
    if (!defined('NOVOTON_PRODUCT_CODE_PREFIX'))     { define('NOVOTON_PRODUCT_CODE_PREFIX',     Constants::PRODUCT_CODE_PREFIX); }
    if (!defined('NOVOTON_SYNC_LOG_RETENTION_DAYS')) { define('NOVOTON_SYNC_LOG_RETENTION_DAYS', Constants::SYNC_LOG_RETENTION_DAYS); }
} else {
    // Fallback if Constants class not yet autoloaded
    if (!defined('NOVOTON_HOLIDAYS_VERSION'))       { define('NOVOTON_HOLIDAYS_VERSION',       '3.2.0'); }
    if (!defined('NOVOTON_HOLIDAYS_ADDON_ID'))      { define('NOVOTON_HOLIDAYS_ADDON_ID',      'novoton_holidays'); }
    if (!defined('NOVOTON_API_TIMEOUT'))             { define('NOVOTON_API_TIMEOUT',             30); }
    if (!defined('NOVOTON_API_CONNECT_TIMEOUT'))     { define('NOVOTON_API_CONNECT_TIMEOUT',     10); }
    if (!defined('NOVOTON_CACHE_HOTEL_INFO'))        { define('NOVOTON_CACHE_HOTEL_INFO',        3600); }
    if (!defined('NOVOTON_CACHE_PRICE_INFO'))        { define('NOVOTON_CACHE_PRICE_INFO',        1800); }
    if (!defined('NOVOTON_CACHE_SEARCH_RESULTS'))    { define('NOVOTON_CACHE_SEARCH_RESULTS',    900); }
    if (!defined('NOVOTON_CACHE_FACILITIES'))        { define('NOVOTON_CACHE_FACILITIES',        86400); }
    if (!defined('NOVOTON_CRON_BATCH_SIZE'))         { define('NOVOTON_CRON_BATCH_SIZE',         100); }
    if (!defined('NOVOTON_CRON_CHECK_INTERVAL'))     { define('NOVOTON_CRON_CHECK_INTERVAL',     86400); }
    if (!defined('NOVOTON_MAX_ROOMS_PER_BOOKING'))   { define('NOVOTON_MAX_ROOMS_PER_BOOKING',   5); }
    if (!defined('NOVOTON_MAX_ADULTS_PER_ROOM'))     { define('NOVOTON_MAX_ADULTS_PER_ROOM',     4); }
    if (!defined('NOVOTON_MAX_CHILDREN_PER_ROOM'))   { define('NOVOTON_MAX_CHILDREN_PER_ROOM',   3); }
    if (!defined('NOVOTON_CHILD_MAX_AGE'))           { define('NOVOTON_CHILD_MAX_AGE',           17); }
    if (!defined('NOVOTON_PRODUCT_CODE_PREFIX'))     { define('NOVOTON_PRODUCT_CODE_PREFIX',     'NVT'); }
    if (!defined('NOVOTON_SYNC_LOG_RETENTION_DAYS')) { define('NOVOTON_SYNC_LOG_RETENTION_DAYS', 30); }
}
