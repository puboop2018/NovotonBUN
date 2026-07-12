<?php

declare(strict_types=1);
/**
 * Travel Core — Alternative Requests admin controller.
 *
 * Cross-provider grid of "no availability" contact requests stored in
 * travel_alternative_requests (novoton mirrors its provider records here;
 * sphinx records are internal-only). Read-only listing with provider/status
 * filters and pagination.
 *
 * @package TravelCore
 * @since 1.5.0
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

if ($mode === 'manage') {
    $repo = new AlternativeRequestRepository();
    [$requests, $search] = $repo->getListing(TypeCoerce::toStringMap($_REQUEST));

    $view->assign('alt_requests', $requests);
    $view->assign('search', $search);
    $view->assign('total_items', $search['total_items']);
}
