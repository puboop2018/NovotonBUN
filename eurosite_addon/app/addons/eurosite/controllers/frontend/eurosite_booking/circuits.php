<?php

declare(strict_types=1);
/**
 * eurosite_booking.circuits — placeholder page for the Eurosite "circuits"
 * Touroperator module (the spec covers it; the storefront flow is a later
 * iteration). CS-Cart renders views/eurosite_booking/circuits.tpl, a thin
 * wrapper around the shared coming-soon component.
 */

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

Tygh::$app['view']->assign('eurosite_module', 'circuits');
