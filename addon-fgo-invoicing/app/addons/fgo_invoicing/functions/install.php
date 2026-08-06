<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Post-install schema migration: add billing-profile columns to user_profiles.
 *
 * `<queries for="install">` in addon.xml does not portably support
 * `ADD COLUMN IF NOT EXISTS`, so the column adds run here where we can guard
 * with information_schema lookups (idempotent re-install/upgrade safe).
 */
function fn_fgo_invoicing_post_install(): void
{
    $columns = [
        'fgo_billing_cui' => "VARCHAR(32) DEFAULT NULL COMMENT 'CIF/CUI for PJ; CNP for PF'",
        'fgo_billing_reg' => "VARCHAR(64) DEFAULT NULL COMMENT 'Registrul Comertului number'",
        'fgo_billing_company' => 'VARCHAR(255) DEFAULT NULL',
        'fgo_billing_tip' => "TINYINT NULL DEFAULT NULL COMMENT '1=PJ (company), 2=PF (person)'",
    ];

    foreach ($columns as $name => $definition) {
        $rawCount = db_get_field(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?p AND COLUMN_NAME = ?s',
            'cscart_user_profiles',
            $name,
        );
        $exists = \Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce::toInt($rawCount);
        if ($exists === 0) {
            db_query("ALTER TABLE ?:user_profiles ADD COLUMN `{$name}` {$definition}");
        }
    }

    // Mirror settings labels into ?:settings_descriptions right after CS-Cart
    // created the settings objects. CS-Cart only fills a description when the
    // object is created, and it fills it from the .po — which may be absent on
    // this deploy. Without this the whole settings form renders as blank labels
    // next to working widgets, and reinstalling is the only other cure.
    // Runs on travel_core-less stores too, where the init.php heal is skipped.
    \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::mirrorSettingsDescriptions();
}

/**
 * Uninstall: drop billing-profile columns + preserve fgo_invoices? No —
 * addon.xml already drops the table. We DO NOT drop user_profile columns
 * automatically, because they may be the only record of historical CIFs.
 * Admin can drop them manually if desired.
 */
function fn_fgo_invoicing_uninstall(): void
{
    // Intentionally a no-op for safety. See note above.
}
