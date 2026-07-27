<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the removal of the get_products_post listing prefetch.
 *
 * The hook batch-loaded full ?:novoton_hotels rows — hotel_data is the
 * entire hotelinfo API response as JSON — into a request cache whose only
 * consumer (the per-product gather_additional_product_data_post
 * enrichment) became a complete no-op with Smarty 5. Every category and
 * search listing paid two queries plus megabytes of JSON decoding for
 * nothing. The batch prefetch function itself stays: the email builders
 * batch-read the enriched data for real.
 */
final class ListingPrefetchRemovedTest extends TestCase
{
    public function testTheListingHookStaysGone(): void
    {
        $addonDir = dirname(__DIR__, 3);

        $init = (string) file_get_contents($addonDir . '/init.php');
        self::assertStringNotContainsString(
            "'get_products_post'",
            $init,
            'get_products_post must not be re-registered: its cache had no reader on any web path',
        );

        $hooks = (string) file_get_contents($addonDir . '/hooks/product_hooks.php');
        self::assertStringNotContainsString('function fn_novoton_holidays_get_products_post', $hooks);
        self::assertStringContainsString('gather_additional_product_data_post', $hooks, 'the no-op siblings remain');
    }

    public function testTheBatchPrefetchSurvivesForTheEmailBuilders(): void
    {
        $addonDir = dirname(__DIR__, 3);

        $hotels = (string) file_get_contents($addonDir . '/functions/hotels.php');
        self::assertStringContainsString('function fn_novoton_holidays_prefetch_hotel_data(array $hotel_ids): void', $hotels);

        $email = (string) file_get_contents($addonDir . '/functions/email.php');
        self::assertSame(
            2,
            substr_count($email, 'fn_novoton_holidays_prefetch_hotel_data('),
            'both email builders keep their batch prefetch',
        );
    }
}
