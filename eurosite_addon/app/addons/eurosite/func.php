<?php

declare(strict_types=1);

/**
 * Eurosite Holidays — addon functions.
 *
 * MVP keeps this thin: the API/booking logic lives in src/ (Api\* + Services\*).
 * This file holds only the CS-Cart procedural boundary the platform calls by
 * name. As the addon grows, hook bodies (place_order_post, etc.) belong in
 * src/Hooks and delegate from here — the pattern the sphinx addon uses.
 *
 * Location: app/addons/eurosite/func.php
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}
