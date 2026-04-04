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

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_ASK       = 'ask';
    public const STATUS_WAITING   = 'waiting';

    // ========== Limits ==========

    public const MAX_ADULTS    = 10;
    public const MAX_CHILDREN  = 6;
    public const MAX_ROOMS     = 5;
    public const MAX_NIGHTS    = 30;
    public const MAX_CHILD_AGE = 17;
    public const MIN_CHILD_AGE = 0;

    // ========== Defaults ==========

    public const DEFAULT_ADULTS   = 2;
    public const DEFAULT_CHILDREN = 0;
    public const DEFAULT_NIGHTS   = 7;
    public const DEFAULT_ROOMS    = 1;

    // ========== Currency ==========

    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_GBP = 'GBP';
    public const CURRENCY_BGN = 'BGN';
    public const CURRENCY_RON = 'RON';

    // ========== Batch Processing ==========

    public const BATCH_SIZE_MIN     = 50;
    public const BATCH_SIZE_DEFAULT = 500;
    public const BATCH_SIZE_MAX     = 2000;

    // ========== External URLs ==========

    public const BNR_RATES_URL = 'https://curs.bnr.ro/nbrfxrates.xml';

    // ========== Date Formats ==========

    public const DATETIME_FORMAT = 'Y-m-d H:i:s';
    public const DATE_FORMAT     = 'Y-m-d';

    // Prevent instantiation
    private function __construct() {}
}
