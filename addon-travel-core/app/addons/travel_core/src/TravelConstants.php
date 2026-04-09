<?php
declare(strict_types=1);
/**
 * Travel Core Constants
 *
 * Shared constants for all travel provider addons.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore;

final class TravelConstants
{
    // ========== Booking Status ==========

    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_CONFIRMED = 'confirmed';
    public const string STATUS_CANCELLED = 'cancelled';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED    = 'failed';
    public const string STATUS_ASK       = 'ask';
    public const string STATUS_WAITING   = 'waiting';

    // ========== Limits ==========

    public const int MAX_ADULTS    = 10;
    public const int MAX_CHILDREN  = 6;
    public const int MAX_ROOMS     = 5;
    public const int MAX_NIGHTS    = 30;
    public const int MAX_CHILD_AGE = 17;
    public const int MIN_CHILD_AGE = 0;

    // ========== Defaults ==========

    public const int DEFAULT_ADULTS   = 2;
    public const int DEFAULT_CHILDREN = 0;
    public const int DEFAULT_NIGHTS   = 7;
    public const int DEFAULT_ROOMS    = 1;

    // ========== Currency ==========

    public const string CURRENCY_EUR = 'EUR';
    public const string CURRENCY_USD = 'USD';
    public const string CURRENCY_GBP = 'GBP';
    public const string CURRENCY_BGN = 'BGN';
    public const string CURRENCY_RON = 'RON';

    // ========== Batch Processing ==========

    public const int BATCH_SIZE_MIN     = 50;
    public const int BATCH_SIZE_DEFAULT = 500;
    public const int BATCH_SIZE_MAX     = 2000;

    // ========== External URLs ==========

    public const string BNR_RATES_URL = 'https://curs.bnr.ro/nbrfxrates.xml';

    // ========== Date Formats ==========

    public const string DATETIME_FORMAT = 'Y-m-d H:i:s';
    public const string DATE_FORMAT     = 'Y-m-d';

    // Prevent instantiation
    private function __construct() {}
}
