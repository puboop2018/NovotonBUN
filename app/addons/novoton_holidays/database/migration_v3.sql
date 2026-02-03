-- =====================================================
-- Novoton Holidays Database Migration v3.0
-- Package-centric architecture
-- =====================================================
--
-- This migration:
-- 1. Creates new novoton_hotel_packages table (package-centric)
-- 2. Simplifies novoton_hotels table (removes redundant JSON columns)
-- 3. Deprecates old normalized tables (novoton_hotel_prices, novoton_seasons, novoton_early_booking)
--
-- Run this AFTER backing up your database!
-- =====================================================

-- -----------------------------------------------------
-- Step 1: Create new novoton_hotel_packages table
-- This stores priceinfo API response per package
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `cscart_novoton_hotel_packages` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `hotel_id` varchar(50) NOT NULL COMMENT 'FK to novoton_hotels.hotel_id',
    `package_id` varchar(50) NOT NULL COMMENT 'IdCont from hotelinfo API',
    `package_name` varchar(255) NOT NULL COMMENT 'PackageName from API',
    `priceinfo_data` longtext COMMENT 'JSON: full priceinfo API response',
    `seasons_count` int(3) DEFAULT 0 COMMENT 'Number of seasons in priceinfo',
    `has_early_booking` enum('Y','N') DEFAULT 'N' COMMENT 'Has EB discounts',
    `min_price` decimal(10,2) DEFAULT NULL COMMENT 'Lowest adult price (for quick filtering)',
    `currency` varchar(3) NOT NULL DEFAULT 'EUR',
    `synced_at` datetime DEFAULT NULL COMMENT 'Last priceinfo sync date',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_hotel_package` (`hotel_id`, `package_id`),
    KEY `idx_hotel_id` (`hotel_id`),
    KEY `idx_package_name` (`package_name`(100)),
    KEY `idx_min_price` (`min_price`),
    KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Package-specific data including priceinfo JSON';

-- -----------------------------------------------------
-- Step 2: Add star_rating column to novoton_hotels
-- (keeps hotel_type for backwards compatibility)
-- -----------------------------------------------------
ALTER TABLE `cscart_novoton_hotels`
ADD COLUMN IF NOT EXISTS `star_rating` tinyint(1) DEFAULT NULL COMMENT 'Numeric star rating 1-5' AFTER `hotel_type`;

-- Add hotelinfo_data column (renamed from hotel_data for clarity)
-- Keep hotel_data for backwards compatibility during migration
ALTER TABLE `cscart_novoton_hotels`
ADD COLUMN IF NOT EXISTS `hotelinfo_data` longtext COMMENT 'JSON: full hotelinfo API response' AFTER `longitude`;

-- -----------------------------------------------------
-- Step 3: Parse hotel_type into star_rating
-- Common formats: "4*", "3* Sup", "5*", "Apart"
-- -----------------------------------------------------
UPDATE `cscart_novoton_hotels`
SET `star_rating` = CASE
    WHEN `hotel_type` LIKE '5%' THEN 5
    WHEN `hotel_type` LIKE '4%' THEN 4
    WHEN `hotel_type` LIKE '3%' THEN 3
    WHEN `hotel_type` LIKE '2%' THEN 2
    WHEN `hotel_type` LIKE '1%' THEN 1
    ELSE NULL
END
WHERE `star_rating` IS NULL AND `hotel_type` IS NOT NULL AND `hotel_type` != '';

-- -----------------------------------------------------
-- Step 4: Copy hotel_data to hotelinfo_data
-- (only if hotelinfo_data is empty)
-- -----------------------------------------------------
UPDATE `cscart_novoton_hotels`
SET `hotelinfo_data` = `hotel_data`
WHERE `hotelinfo_data` IS NULL AND `hotel_data` IS NOT NULL;

-- -----------------------------------------------------
-- Step 5: Add indexes for better query performance
-- -----------------------------------------------------
-- Index for star rating filters
ALTER TABLE `cscart_novoton_hotels`
ADD INDEX IF NOT EXISTS `idx_star_rating` (`star_rating`);

-- Index for city/country lookups
ALTER TABLE `cscart_novoton_hotels`
ADD INDEX IF NOT EXISTS `idx_city_country` (`city`, `country`);

-- -----------------------------------------------------
-- NOTES:
-- -----------------------------------------------------
-- The following tables are DEPRECATED but NOT DROPPED:
-- - novoton_hotel_prices (normalized price storage)
-- - novoton_seasons (was never populated)
-- - novoton_early_booking (was never populated)
--
-- The following columns in novoton_hotels are DEPRECATED:
-- - rooms_data (now in hotelinfo_data)
-- - board_data (now in hotelinfo_data)
-- - packages_data (now in novoton_hotel_packages)
-- - ages_data (now in hotelinfo_data)
--
-- These will be removed in a future version after confirming
-- the new architecture works correctly.
-- -----------------------------------------------------

-- Verify migration
SELECT
    'novoton_hotel_packages' as table_name,
    COUNT(*) as row_count
FROM `cscart_novoton_hotel_packages`
UNION ALL
SELECT
    'novoton_hotels' as table_name,
    COUNT(*) as row_count
FROM `cscart_novoton_hotels`;
