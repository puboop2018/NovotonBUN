<?php

declare(strict_types=1);

/**
 * Realistic fixture for a `?:novoton_hotels` row as returned by the CS-Cart
 * DB layer (numeric columns come back as strings, JSON blobs are strings,
 * Y/N enums are 'Y'/'N' strings, dates are 'Y-m-d H:i:s'). Used by
 * Hotel DTO tests.
 *
 * @return array<string, mixed>
 */
return [
    'hotel_id' => 'NVT12345',
    'product_id' => '4201',
    'hotel_name' => 'Hotel Example Palace',
    'city' => 'Barcelona',
    'region' => 'Catalonia',
    'country' => 'Spain',
    'hotel_type' => '4*',
    'star_rating' => '4',
    'property_type' => 'hotel',
    'is_adults_only' => 'N',
    'latitude' => '41.3850000',
    'longitude' => '2.1734000',
    'hotel_data' => '{"IdHotel":"NVT12345","Facilities":[{"Code":"WIFI","Name":"Wi-Fi"}],"Images":[]}',
    'has_room_price' => 'Y',
    'packages_count' => '7',
    'hotelinfo_synced_at' => '2026-04-15 10:30:45',
    'hotel_list_synced_at' => '2026-04-10 06:00:00',
    'last_price_check' => '2026-04-17 22:15:00',
    'calendar_prices_raw' => '{"2026-05-01":145.50,"2026-05-02":152.00}',
    'created_at' => '2025-09-01 12:00:00',
    'updated_at' => '2026-04-17 22:15:05',
];
