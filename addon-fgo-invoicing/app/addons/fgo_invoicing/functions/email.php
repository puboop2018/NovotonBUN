<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Send the customer-facing "Invoice issued" notification email containing the
 * signed FGO PDF link. Wraps CS-Cart's fn_send_mail with the addon's template.
 *
 * @param array{
 *   to: string,
 *   order_id: int,
 *   invoice_number: string,
 *   invoice_series: string,
 *   pdf_link: string,
 *   payment_link?: string,
 *   company_name?: string
 * } $payload
 */
function fn_fgo_invoicing_send_invoice_email(array $payload): bool
{
    if (!function_exists('fn_send_mail')) {
        return false;
    }

    $companyId = \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toInt(
        \Tygh\Registry::get('runtime.company_id'),
    );
    if ($companyId <= 0) {
        $companyId = 1;
    }

    $tpl = [
        'addons/fgo_invoicing/invoice_issued_subj.tpl',
        'addons/fgo_invoicing/invoice_issued.tpl',
    ];

    $orderDept = \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toString(
        \Tygh\Registry::get('settings.Company.company_orders_department'),
    );

    return (bool) fn_send_mail(
        $payload['to'],
        $orderDept,
        $tpl[0],
        $tpl[1],
        $payload,
        '',
        '',
        '',
        '',
        '',
        $companyId,
    );
}
