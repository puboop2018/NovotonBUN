<?php

declare(strict_types=1);

use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\Container;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Backend controller for the FGO Invoicing addon admin pages.
 *
 * Routes (CS-Cart dispatcher style):
 *   GET  /fgo_invoicing.manage         List recent invoice rows.
 *   GET  /fgo_invoicing.view?order_id=N Show one row + raw payload.
 *   POST /fgo_invoicing.issue          Manual issue / re-issue button.
 *   POST /fgo_invoicing.cancel
 *   POST /fgo_invoicing.storno
 *   POST /fgo_invoicing.delete
 *   POST /fgo_invoicing.attach_awb
 *   POST /fgo_invoicing.test_connection /factura/check preflight from settings.
 */

if (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN) {
    return [CONTROLLER_STATUS_DENIED];
}

$container = Container::getInstance();
$repo = $container->repository();

$view = is_object(Tygh::$app) && method_exists(Tygh::$app, 'offsetGet') ? Tygh::$app->offsetGet('view') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = TypeCoerce::toInt($_REQUEST['order_id'] ?? 0);

    if ($mode === 'issue') {
        if ($orderId <= 0) {
            fn_set_notification('E', __('error'), __('fgo_invoicing.missing_order_id'));
            return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.manage'];
        }
        $result = $container->issuer()->issueForOrder($orderId);
        if ($result['status'] === 'issued') {
            fn_set_notification('N', __('notice'), __('fgo_invoicing.invoice_issued'));
        } else {
            fn_set_notification(
                'E',
                __('error'),
                TypeCoerce::toString(__('fgo_invoicing.invoice_failed')) . ': ' . ($result['error'] ?? 'unknown'),
            );
        }
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.view?order_id=' . $orderId];
    }

    if ($mode === 'cancel' || $mode === 'storno' || $mode === 'delete') {
        $result = match ($mode) {
            'cancel' => $container->canceler()->cancel($orderId),
            'storno' => $container->canceler()->storno($orderId),
            'delete' => $container->canceler()->delete($orderId),
        };
        if ($result['status'] === 'ok') {
            fn_set_notification('N', __('notice'), __('fgo_invoicing.action_succeeded'));
        } else {
            fn_set_notification('E', __('error'), $result['error'] ?? 'failed');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.view?order_id=' . $orderId];
    }

    if ($mode === 'attach_awb') {
        $awb = trim(TypeCoerce::toString($_REQUEST['awb'] ?? ''));
        $result = $container->canceler()->attachAwb($orderId, $awb);
        if ($result['status'] === 'ok') {
            fn_set_notification('N', __('notice'), __('fgo_invoicing.awb_attached'));
        } else {
            fn_set_notification('E', __('error'), $result['error'] ?? 'failed');
        }
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.view?order_id=' . $orderId];
    }

    if ($mode === 'test_connection') {
        try {
            $resp = $container->api()->check();
            $msg = TypeCoerce::toString($resp['Message'] ?? __('fgo_invoicing.connection_ok'));
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('fgo_invoicing.connection_ok')) . ': ' . $msg);
        } catch (FgoApiException $e) {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('fgo_invoicing.connection_failed')) . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('fgo_invoicing.connection_failed')) . ': ' . $e->getMessage());
        }
        return [CONTROLLER_STATUS_REDIRECT, 'addons.update?addon=fgo_invoicing'];
    }
}

if ($mode === 'manage') {
    $rows = $repo->listRecent(200);
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('fgo_invoices', $rows);
        $view->assign('fgo_sandbox', ConfigProvider::isSandbox());
    }
}

if ($mode === 'view') {
    $orderId = TypeCoerce::toInt($_REQUEST['order_id'] ?? 0);
    $row = $repo->findByOrderId($orderId);
    if ($row === null) {
        fn_set_notification('W', __('warning'), __('fgo_invoicing.no_invoice_for_order'));
        return [CONTROLLER_STATUS_REDIRECT, 'fgo_invoicing.manage'];
    }
    $requestRaw = TypeCoerce::toString($row['request_payload'] ?? '[]');
    $responseRaw = TypeCoerce::toString($row['payload'] ?? '[]');
    $requestArr = json_decode($requestRaw !== '' ? $requestRaw : '[]', true);
    $responseArr = json_decode($responseRaw !== '' ? $responseRaw : '[]', true);
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('fgo_invoice', $row);
        $view->assign(
            'fgo_request_pretty',
            json_encode(
                is_array($requestArr) ? $requestArr : [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
        $view->assign(
            'fgo_response_pretty',
            json_encode(
                is_array($responseArr) ? $responseArr : [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
