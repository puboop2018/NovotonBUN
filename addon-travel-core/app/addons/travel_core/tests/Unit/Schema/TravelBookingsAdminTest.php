<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the unified Travel Bookings admin pages (travel_bookings.manage/view):
 *
 * 1. Labels come from travel_core.* language keys. The templates once called
 *    bare __("hotel")/__("hotel_name") — neither exists in CS-Cart core, so
 *    the admin rendered the missing-variable placeholders "_hotel" and
 *    "_hotel_name". The keys are declared in addon.xml, which the lang
 *    self-seed merges (fn_travel_core_language_variables), reaching
 *    already-installed stores.
 *
 * 2. The hotel name links to its STOREFRONT product page in a new window.
 *    product_id is resolved through the provider registry
 *    (HotelProductProviderInterface::productIdForHotelId) — no provider SQL
 *    in the shared controller.
 */
final class TravelBookingsAdminTest extends TestCase
{
    private static function backendTpl(string $name): string
    {
        $path = dirname(__DIR__, 6)
            . '/design/backend/templates/addons/travel_core/views/travel_bookings/' . $name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLabelsUseTravelCoreKeysNotMissingCoreVars(): void
    {
        foreach (['manage.tpl', 'view.tpl'] as $name) {
            $tpl = self::backendTpl($name);

            self::assertStringNotContainsString(
                '{__("hotel_name")}',
                $tpl,
                "{$name}: bare hotel_name var does not exist in CS-Cart core — renders \"_hotel_name\"",
            );
            self::assertStringNotContainsString(
                '{__("hotel")}',
                $tpl,
                "{$name}: bare hotel var does not exist in CS-Cart core — renders \"_hotel\"",
            );
        }

        self::assertStringContainsString('{__("travel_core.hotel_name")}', self::backendTpl('manage.tpl'));
        self::assertStringContainsString('{__("travel_core.hotel")}', self::backendTpl('manage.tpl'));
        self::assertStringContainsString('{__("travel_core.hotel_name")}', self::backendTpl('view.tpl'));
    }

    public function testHotelNameLangKeyIsDeclaredForSeeding(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 3) . '/addon.xml');

        foreach (['en', 'ro'] as $lang) {
            self::assertStringContainsString(
                'lang="' . $lang . '" id="travel_core.hotel_name"',
                $xml,
                'travel_core.hotel_name must be declared so the lang self-seed reaches installed stores',
            );
        }
    }

    public function testHotelNameLinksToTheStorefrontProductPageInANewWindow(): void
    {
        foreach (['manage.tpl', 'view.tpl'] as $name) {
            $tpl = self::backendTpl($name);

            self::assertStringContainsString(
                '<a href="{$booking.hotel_product_url|escape:html}" target="_blank" rel="noopener">',
                $tpl,
                "{$name}: the hotel name must link to the storefront product page in a new window",
            );
        }
    }

    public function testControllerResolvesTheProductThroughTheProviderRegistry(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/controllers/backend/travel_bookings.php',
        );

        self::assertStringContainsString('getHotelProductProvider', $controller);
        self::assertStringContainsString('productIdForHotelId', $controller);
        // Area 'C' — the admin grid links OUT to the customer storefront.
        self::assertStringContainsString("fn_url('products.view?product_id=' . \$productId, 'C')", $controller);
    }

    public function testInterfaceDeclaresTheReverseLookup(): void
    {
        $interface = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Contracts/HotelProductProviderInterface.php',
        );

        self::assertStringContainsString(
            'public function productIdForHotelId(string $hotelId): ?int;',
            $interface,
            'both provider addons implement this — removing it breaks their class load',
        );
    }
}
