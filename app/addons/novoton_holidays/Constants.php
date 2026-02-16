<?php
/**
 * Novoton Constants
 * 
 * Centralized constants for the addon to avoid magic strings and values.
 * 
 * @package NovotonHolidays
 * @since 2.7.0
 */

namespace Tygh\Addons\NovotonHolidays;

/**
 * Addon-wide constants
 */
final class Constants
{
    // Addon info
    public const ADDON_ID = 'novoton_holidays';
    public const VERSION = '2.7.0';
    
    // ========== Booking Status ==========
    
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    
    // Novoton API status values (hotel_res_RQ response codes)
    public const NOVOTON_STATUS_CONFIRMED = 'OK';      // Reservation accepted and confirmed
    public const NOVOTON_STATUS_ON_REQUEST = 'ASK';     // Reservation accepted with asking status
    public const NOVOTON_STATUS_CANCELLED = 'ST';       // Reservation cancelled
    public const NOVOTON_STATUS_WAITLIST = 'WT';        // Reservation with waiting status
    
    // ========== Board Types ==========
    // Canonical codes: use ValueObjects\BoardType for display-name lookups

    public const BOARD_AI = 'AI';           // All Inclusive
    public const BOARD_UAI = 'UAI';         // Ultra All Inclusive
    public const BOARD_FB = 'FB';           // Full Board
    public const BOARD_FB_PLUS = 'FB+';     // Full Board Plus
    public const BOARD_HB = 'HB';           // Half Board
    public const BOARD_HB_PLUS = 'HB+';     // Half Board Plus
    public const BOARD_BB = 'BB';           // Bed & Breakfast
    public const BOARD_RO = 'RO';           // Room Only
    public const BOARD_SC = 'SC';           // Self Catering

    /**
     * Board code => display name map.
     * @deprecated Use ValueObjects\BoardType::allWithAliases() instead.
     *             Kept for backward compatibility with templates referencing Constants::BOARD_NAMES.
     */
    public const BOARD_NAMES = [
        self::BOARD_AI => 'All Inclusive',
        'ALL INCL' => 'All Inclusive',
        self::BOARD_UAI => 'Ultra All Inclusive',
        self::BOARD_FB => 'Full Board',
        self::BOARD_FB_PLUS => 'Full Board Plus',
        self::BOARD_HB => 'Half Board',
        self::BOARD_HB_PLUS => 'Half Board Plus',
        self::BOARD_BB => 'Bed & Breakfast',
        self::BOARD_RO => 'Room Only',
        self::BOARD_SC => 'Self Catering',
    ];

    // ========== Reservation Status Mapping ==========
    // Maps Novoton API response codes (hotel_res_RQ / resinfo) to internal statuses

    public const NOVOTON_STATUS_TO_INTERNAL = [
        self::NOVOTON_STATUS_CONFIRMED  => self::STATUS_CONFIRMED,  // OK  -> confirmed
        'Confirmed'                     => self::STATUS_CONFIRMED,
        self::NOVOTON_STATUS_ON_REQUEST => 'ask',                   // ASK -> ask (on-request)
        'OnRequest'                     => 'ask',
        self::NOVOTON_STATUS_CANCELLED  => self::STATUS_CANCELLED,  // ST  -> cancelled
        'Cancelled'                     => self::STATUS_CANCELLED,
        self::NOVOTON_STATUS_WAITLIST   => 'waiting',               // WT  -> waiting
        'Waitlist'                      => 'waiting',
    ];
    
    // ========== Availability Status ==========
    
    public const AVAIL_OK = 'OK';
    public const AVAIL_RQ = 'RQ';           // On Request
    public const AVAIL_STOP = 'STOP';       // Stop Sale
    public const AVAIL_NA = 'NA';           // Not Available
    
    // ========== Age Types ==========
    
    public const AGE_ADULT = 'ADULT';
    public const AGE_CHILD = 'CHILD';
    public const AGE_INFANT = 'INFANT';
    
    // ========== Accommodation Types ==========
    
    public const ACC_REGULAR = 'REGULAR';
    public const ACC_SINGLE = 'SINGLE';
    public const ACC_EXTRA_BED = 'EXTRA_BED';
    
    // ========== Currency ==========
    
    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_BGN = 'BGN';
    public const CURRENCY_RON = 'RON';
    
    // Currency symbols and formatting are taken from CS-Cart currency settings
    // See: Administration → Currencies in CS-Cart admin panel
    // Default currency uses CART_PRIMARY_CURRENCY constant from CS-Cart
    
    // ========== Limits ==========
    
    public const MAX_ADULTS = 10;
    public const MAX_CHILDREN = 6;
    public const MAX_ROOMS = 5;
    public const MAX_NIGHTS = 30;
    public const MAX_CHILD_AGE = 17;
    public const MIN_CHILD_AGE = 0;
    
    // ========== Cache TTL (seconds) ==========
    // ONLY for live API calls - static data uses database storage
    
    public const CACHE_TTL_ROOM_PRICE = 300;        // 5 minutes (live booking prices)
    public const CACHE_TTL_AVAILABILITY = 180;      // 3 minutes (live availability)
    public const CACHE_TTL_SEARCH = 300;            // 5 minutes (search results)
    
    // Note: hotel_list, hotel_info, priceinfo are NOT cached
    // They are stored in database and synced via cron jobs
    
    // ========== API Functions ==========
    // Names match the Novoton XML API function identifiers exactly.
    // See Novoton API docs: each constant = the <function> value sent in the URL.

    // --- Catalog & Hotel Data ---
    public const API_FUNCTION_HOTEL_LIST        = 'hotel_list';         //  1. List hotel names
    public const API_FUNCTION_HOTEL_INFO        = 'hotelinfo';          //  2. Hotel services/rooms/packages
    public const API_FUNCTION_HOTEL_DESCRIPTION = 'hotel_description';  //  5. Hotel description text
    public const API_FUNCTION_HOTEL_IMAGES      = 'hotel_images';       //  6. Hotel pictures

    // --- Pricing & Availability ---
    public const API_FUNCTION_ROOM_PRICE        = 'room_price';         //  3. Real-time accommodation prices
    public const API_FUNCTION_HOTEL_QUOTA       = 'hotel_quota';        //  4. Free allotments (availability)
    public const API_FUNCTION_HOTEL_QUOTA_ADD   = 'hotel_quota_add';    // 21. Additional allotments
    public const API_FUNCTION_PRICE_INFO        = 'priceinfo';          // 13. Season prices
    public const API_FUNCTION_SPECIAL_OFFERS    = 'spo';                // 10. Early booking & discounts
    public const API_FUNCTION_OFFERS_UPDATE     = 'offers_update';      // 25. Updated/new offers (delta)

    // --- Reservations ---
    public const API_FUNCTION_RESERVATION       = 'hotel_res_RQ';       //  7. Submit reservation request
    public const API_FUNCTION_RES_INFO          = 'resinfo';            // 15. Reservation status check
    public const API_FUNCTION_CANCEL            = 'cancel_reservation'; //     Cancel reservation
    public const API_FUNCTION_INVOICE_HTML      = 'hotel_acc_RQ_html';  //  8. Invoice (HTML)
    public const API_FUNCTION_INVOICE_XML       = 'hotel_acc_RQ';       //  9. Invoice (XML)
    public const API_FUNCTION_LIST_INVOICES     = 'list_invoices';      // 14. List invoices

    // --- Alternatives ---
    public const API_FUNCTION_HOTEL_REQUEST     = 'hotel_request';      // 22. Request alternatives
    public const API_FUNCTION_ALTERNATIVE_RS    = 'alternative_RS';     // 23. Check alternative offers

    // --- Search & Destinations ---
    public const API_FUNCTION_SEARCH            = 'frmsearch';          //     Availability search
    public const API_FUNCTION_RESORT_LIST       = 'resort_list';        // 16. Destinations/resort names

    // --- Facilities ---
    public const API_FUNCTION_LIST_FACILITIES   = 'list_facilities';    // 26. All facilities catalog
    public const API_FUNCTION_HOTEL_FACILITIES  = 'hotel_facilities';   // 27. Facilities for a hotel

    // --- Commission ---
    public const API_FUNCTION_KICKBACK          = 'kickback_RS';        // 24. Commission/kickback info
    
    // ========== Database Tables (V3 Architecture) ==========

    public const TABLE_HOTELS = 'novoton_hotels';
    public const TABLE_PACKAGES = 'novoton_hotel_packages';  // Packages from hotelinfo API with priceinfo
    public const TABLE_BOOKINGS = 'novoton_bookings';
    public const TABLE_FACILITIES = 'novoton_hotel_facilities';
    public const TABLE_SYNC_LOG = 'novoton_sync_log';
    public const TABLE_CACHE = 'novoton_cache';
    
    // ========== Countries ==========
    
    public const COUNTRIES = [
        'ALBANIA',
        'BULGARIA',
        'CYPRUS',
        'EGYPT',
        'FRANCE',
        'GREECE',
        'ITALY',
        'MALDIVES',
        'SPAIN',
        'TURKEY',
        'UNITED ARAB EMIRATES',
        'UNITED KINGDOM',
    ];
    
    public const DEFAULT_COUNTRY = 'BULGARIA';
    
    // ========== Languages ==========
    
    public const LANG_EN = 'UK';
    public const LANG_BG = 'BG';
    public const LANG_RO = 'RO';
    
    public const DEFAULT_LANG = self::LANG_EN;
    
    // ========== Error Codes ==========
    
    public const ERROR_INVALID_DATA = 'E001';
    public const ERROR_API_FAILURE = 'E002';
    public const ERROR_NOT_AVAILABLE = 'E003';
    public const ERROR_PRICE_CHANGED = 'E004';
    public const ERROR_BOOKING_FAILED = 'E005';
    public const ERROR_RATE_LIMITED = 'E006';
    public const ERROR_UNAUTHORIZED = 'E007';
    
    // ========== Default Values ==========
    
    public const DEFAULT_ADULTS = 2;
    public const DEFAULT_CHILDREN = 0;
    public const DEFAULT_NIGHTS = 7;
    public const DEFAULT_ROOMS = 1;
    public const DEFAULT_COMMISSION = 0;
    
    // ========== Debug / Logging Settings Keys ==========

    /** Registry key for verbose service logging (default Y) */
    public const SETTING_DEBUG_LOGGING = 'addons.novoton_holidays.debug_logging';

    /** Registry key for full debug mode (default N) */
    public const SETTING_DEBUG_MODE = 'addons.novoton_holidays.debug_mode';

    // ========== File Paths ==========

    public const CACHE_DIR = 'var/cache/novoton/';
    public const LOG_DIR = 'var/log/';
    
    // Prevent instantiation
    private function __construct() {}
}
