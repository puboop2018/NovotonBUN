<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing;

final class Constants
{
    public const ADDON_ID = 'fgo_invoicing';

    public const API_BASE_PROD = 'https://api.fgo.ro/v1/';
    public const API_BASE_SANDBOX = 'https://api-testuat.fgo.ro/v1/';

    /** Hard-coded salt used by the official PrestaShop and WooCommerce plugins. */
    public const TOKEN_SALT = '4C490B5C';

    public const PATH_CHECK = 'factura/check';
    public const PATH_EMITERE = 'factura/emitere';
    public const PATH_ANULARE = 'factura/anulare';
    public const PATH_STORNARE = 'factura/stornare';
    public const PATH_STERGERE = 'factura/stergere';
    public const PATH_AWB = 'factura/awb';

    /** Romanian standard VAT rates accepted by FGO. */
    public const VAT_RATES = [0, 5, 9, 11, 21];

    public const UM_PIECE = 'H87';
    public const UM_SERVICE = 'SX';

    public const TIP_COMPANY = 'PJ';
    public const TIP_PERSON = 'PF';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_DELETED = 'deleted';

    public const TRIGGER_ON_ORDER = 'onOrder';
    public const TRIGGER_ON_PAYMENT = 'onPayment';
    public const TRIGGER_ON_COMPLETED = 'onCompleted';
    public const TRIGGER_MANUAL = 'manual';

    public const PLATFORM_NAME = 'CS-Cart';

    private function __construct()
    {
    }
}
