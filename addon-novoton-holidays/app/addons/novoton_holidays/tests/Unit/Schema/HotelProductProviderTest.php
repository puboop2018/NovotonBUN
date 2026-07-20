<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the novoton HotelProductProvider's reverse lookup: the unified
 * Travel Bookings admin resolves hotel_id -> product_id through it to link
 * a booking's hotel to its storefront product page.
 */
final class HotelProductProviderTest extends TestCase
{
    public function testProductIdForHotelIdQueriesTheHotelsTable(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Providers/NovotonHotelProductProvider.php',
        );

        self::assertStringContainsString('public function productIdForHotelId(string $hotelId): ?int', $src);
        self::assertStringContainsString('SELECT product_id FROM ?:novoton_hotels WHERE hotel_id = ?i', $src);
        // Unknown hotel / unlinked product must yield null, never 0.
        self::assertStringContainsString('return $productId > 0 ? $productId : null;', $src);
    }
}
