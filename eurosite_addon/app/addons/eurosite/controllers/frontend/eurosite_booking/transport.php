<?php

declare(strict_types=1);
/**
 * eurosite_booking.transport — placeholder page for the Eurosite "transport"
 * Touroperator module (the spec covers it; the storefront flow is a later
 * iteration). CS-Cart renders views/eurosite_booking/transport.tpl, a thin
 * wrapper around the shared coming-soon component.
 */

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

Tygh::$app['view']->assign('eurosite_module', 'transport');
