<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Install;

/**
 * Idempotent schema self-heal for EXISTING installs (sphinx pattern).
 *
 * Dev stores symlink the addon and never rerun addon.xml, so schema shipped
 * after install must converge at runtime. Two steps, both idempotent:
 *
 *  1. Missing TABLES — re-execute addon.xml's own `CREATE TABLE IF NOT
 *     EXISTS` install items verbatim (single source of truth, no DDL drift;
 *     only items starting with that exact phrase are run, so future seed
 *     INSERTs can never replay here).
 *  2. Missing COLUMNS on ?:eurosite_bookings — the original MVP table
 *     predates the booking-pipeline columns; checked via
 *     INFORMATION_SCHEMA and ADDed one by one.
 *
 * Called once per request from func.php (AREA 'A' and the cron controller);
 * "reinstall the addon" remains the manual fallback.
 */
final class SchemaMigrator
{
    private static bool $done = false;

    /**
     * @param string $prefix The ?: table prefix, read at the func.php
     *                       boundary (Registry access is banned in src/).
     */
    public static function ensure(string $prefix): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;

        self::createMissingTables();
        self::addMissingBookingColumns($prefix);
    }

    private static function createMissingTables(): void
    {
        $xml = @simplexml_load_file(__DIR__ . '/../../addon.xml');
        if ($xml === false || $xml === null) {
            return;
        }
        foreach ($xml->queries->item ?? [] as $item) {
            if ((string) $item['for'] !== 'install') {
                continue;
            }
            $sql = trim((string) $item);
            if (stripos($sql, 'CREATE TABLE IF NOT EXISTS') !== 0) {
                continue;
            }
            db_query($sql);
        }
    }

    private static function addMissingBookingColumns(string $prefix): void
    {
        // column => ADD COLUMN definition (keep in sync with the
        // eurosite_bookings CREATE in addon.xml)
        $columns = [
            'nights'        => "ADD COLUMN `nights` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `check_out`",
            'adults'        => "ADD COLUMN `adults` TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER `nights`",
            'children'      => "ADD COLUMN `children` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `adults`",
            'children_ages' => "ADD COLUMN `children_ages` VARCHAR(100) NOT NULL DEFAULT '' AFTER `children`",
            'num_rooms'     => "ADD COLUMN `num_rooms` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `children_ages`",
            'rooms_data'    => 'ADD COLUMN `rooms_data` JSON DEFAULT NULL AFTER `num_rooms`',
            'room_type'     => "ADD COLUMN `room_type` VARCHAR(255) NOT NULL DEFAULT '' AFTER `rooms_data`",
            'board_id'      => "ADD COLUMN `board_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'meal/board service code' AFTER `room_type`",
            'meal_name'     => "ADD COLUMN `meal_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `board_id`",
            'series_id'     => "ADD COLUMN `series_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'SeriesId from the offer' AFTER `meal_name`",
            'guest_name'    => "ADD COLUMN `guest_name` VARCHAR(500) NOT NULL DEFAULT '' AFTER `series_id`",
            'guest_email'   => "ADD COLUMN `guest_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `guest_name`",
            'guest_phone'   => "ADD COLUMN `guest_phone` VARCHAR(100) NOT NULL DEFAULT '' AFTER `guest_email`",
            'cancellation_fees_json' => "ADD COLUMN `cancellation_fees_json` JSON DEFAULT NULL COMMENT 'getBookingFees snapshot' AFTER `status`",
            'updated_at'    => 'ADD COLUMN `updated_at` DATETIME DEFAULT NULL AFTER `created_at`',
        ];

        $table = $prefix . 'eurosite_bookings';
        $rows = db_get_array(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $table,
        );
        if (!is_array($rows) || $rows === []) {
            return; // table absent — addon not installed; base CREATE owns it
        }
        $have = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['COLUMN_NAME']) && is_scalar($row['COLUMN_NAME'])) {
                $have[(string) $row['COLUMN_NAME']] = true;
            }
        }
        foreach ($columns as $column => $ddl) {
            if (!isset($have[$column])) {
                db_query('ALTER TABLE ?:eurosite_bookings ' . $ddl);
            }
        }
    }
}
