<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the PDP hotel resolution to prefer the hotel's own API address fields
 * (address_city / address_country) over the irregular destination-tree names,
 * so the product-page location line reads "street, city, country" from clean
 * per-hotel data.
 */
final class PdpProviderAddressTest extends TestCase
{
    public function testResolveProductPrefersApiAddressFields(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Providers/SphinxHotelProductProvider.php',
        );

        self::assertStringContainsString('NULLIF(address_city', $src);
        self::assertStringContainsString('NULLIF(address_country', $src);
        self::assertStringContainsString('address, phone, email, website', $src);
    }

    public function testProductIdForHotelIdQueriesTheHotelsTable(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Providers/SphinxHotelProductProvider.php',
        );

        // Reverse lookup used by the unified Travel Bookings admin to link a
        // booking's hotel to its storefront product page.
        self::assertStringContainsString('public function productIdForHotelId(string $hotelId): ?int', $src);
        self::assertStringContainsString('SELECT product_id FROM ?:sphinx_hotels WHERE hotel_id = ?s', $src);
        self::assertStringContainsString('return $productId > 0 ? $productId : null;', $src);
    }
}
