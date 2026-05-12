<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Read FGO billing extras (cui, reg, company, tip) for a user profile id.
 *
 * @return array{fgo_billing_cui: string, fgo_billing_reg: string, fgo_billing_company: string, fgo_billing_tip: ?int}
 */
function fn_fgo_invoicing_get_billing_extras(int $profile_id): array
{
    if ($profile_id <= 0) {
        return [
            'fgo_billing_cui' => '',
            'fgo_billing_reg' => '',
            'fgo_billing_company' => '',
            'fgo_billing_tip' => null,
        ];
    }

    $row = db_get_row(
        'SELECT fgo_billing_cui, fgo_billing_reg, fgo_billing_company, fgo_billing_tip
         FROM ?:user_profiles WHERE profile_id = ?i',
        $profile_id,
    );
    $rowArr = is_array($row) ? $row : [];

    $tipRaw = $rowArr['fgo_billing_tip'] ?? null;

    return [
        'fgo_billing_cui' => \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toString($rowArr['fgo_billing_cui'] ?? ''),
        'fgo_billing_reg' => \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toString($rowArr['fgo_billing_reg'] ?? ''),
        'fgo_billing_company' => \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toString($rowArr['fgo_billing_company'] ?? ''),
        'fgo_billing_tip' => $tipRaw !== null
            ? \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toInt($tipRaw)
            : null,
    ];
}

/**
 * Persist FGO billing extras submitted from checkout / profile editor.
 *
 * @param array<string, mixed> $userData
 */
function fn_fgo_invoicing_save_billing_extras(int $profile_id, array $userData): void
{
    if ($profile_id <= 0) {
        return;
    }

    $update = [];
    foreach (['fgo_billing_cui', 'fgo_billing_reg', 'fgo_billing_company'] as $key) {
        if (array_key_exists($key, $userData)) {
            $update[$key] = \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toString($userData[$key]);
        }
    }
    if (array_key_exists('fgo_billing_tip', $userData)) {
        $tip = \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toInt($userData['fgo_billing_tip']);
        $update['fgo_billing_tip'] = in_array($tip, [1, 2], true) ? $tip : null;
    }

    if ($update !== []) {
        db_query('UPDATE ?:user_profiles SET ?u WHERE profile_id = ?i', $update, $profile_id);
    }
}
