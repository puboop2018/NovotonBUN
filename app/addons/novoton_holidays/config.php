<?php
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Novoton Holidays Addon - Configuration Constants                       *
 *   Location: app/addons/novoton_holidays/config.php                       *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Addon version
 */
define('NOVOTON_HOLIDAYS_VERSION', '3.2.0');

/**
 * Addon ID
 */
define('NOVOTON_HOLIDAYS_ADDON_ID', 'novoton_holidays');

/**
 * API endpoints
 * Note: API base URL comes from addon settings (api_url), accessed via ConfigService::getApiUrl()
 */
define('NOVOTON_API_TIMEOUT', 30);
define('NOVOTON_API_CONNECT_TIMEOUT', 10);

/**
 * Cache settings (in seconds)
 */
define('NOVOTON_CACHE_HOTEL_INFO', 3600);      // 1 hour
define('NOVOTON_CACHE_PRICE_INFO', 1800);      // 30 minutes
define('NOVOTON_CACHE_SEARCH_RESULTS', 900);   // 15 minutes
define('NOVOTON_CACHE_FACILITIES', 86400);     // 24 hours

/**
 * Cron settings
 */
define('NOVOTON_CRON_BATCH_SIZE', 100);
define('NOVOTON_CRON_CHECK_INTERVAL', 86400);  // 24 hours

/**
 * Booking settings
 */
define('NOVOTON_MAX_ROOMS_PER_BOOKING', 5);
define('NOVOTON_MAX_ADULTS_PER_ROOM', 4);
define('NOVOTON_MAX_CHILDREN_PER_ROOM', 3);
define('NOVOTON_CHILD_MAX_AGE', 17);

/**
 * Default product code prefix
 */
define('NOVOTON_PRODUCT_CODE_PREFIX', 'NVT');

/**
 * Sync log retention (days)
 */
define('NOVOTON_SYNC_LOG_RETENTION_DAYS', 30);

/**
 * Debug settings (set via addon settings panel):
 * - debug_logging: Verbose logging for services (default Y)
 * - debug_mode: Full debug mode with API response dumps (default N)
 * Use Constants::SETTING_DEBUG_LOGGING / Constants::SETTING_DEBUG_MODE for keys.
 */
