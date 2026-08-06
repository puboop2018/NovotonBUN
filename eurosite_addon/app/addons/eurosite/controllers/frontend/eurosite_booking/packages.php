<?php

declare(strict_types=1);
/**
 * eurosite_booking.packages — placeholder page for the Eurosite "packages"
 * Touroperator module (the spec covers it; the storefront flow is a later
 * iteration). CS-Cart renders views/eurosite_booking/packages.tpl, a thin
 * wrapper around the shared coming-soon component.
 */

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];
$view->assign('eurosite_module', 'packages');
