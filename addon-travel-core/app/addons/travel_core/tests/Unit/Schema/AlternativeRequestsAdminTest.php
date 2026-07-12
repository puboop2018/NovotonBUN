<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the cross-provider Availability Requests surface: the shared table is
 * created AND torn down (both teardown paths), the read-only admin grid
 * exists with pagination, and the page is reachable from both admin menus.
 */
final class AlternativeRequestsAdminTest extends TestCase
{
    public function testTableCreatedAndDroppedOnBothTeardownPaths(): void
    {
        $appRoot = dirname(__DIR__, 3);
        $addonXml = (string) file_get_contents($appRoot . '/addon.xml');
        $func = (string) file_get_contents($appRoot . '/func.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `?:travel_alternative_requests`', $addonXml);
        self::assertStringContainsString('DROP TABLE IF EXISTS `?:travel_alternative_requests`', $addonXml);
        self::assertStringContainsString('DROP TABLE IF EXISTS ?:travel_alternative_requests', $func);
    }

    public function testAdminGridExistsWithPagination(): void
    {
        $appRoot = dirname(__DIR__, 3);
        $controller = $appRoot . '/controllers/backend/travel_alternatives.php';
        self::assertFileExists($controller);
        self::assertStringContainsString('getListing', (string) file_get_contents($controller));

        $tpl = dirname(__DIR__, 6)
            . '/design/backend/templates/addons/travel_core/views/travel_alternatives/manage.tpl';
        self::assertFileExists($tpl);
        $tplSrc = (string) file_get_contents($tpl);
        self::assertStringContainsString('common/pagination.tpl', $tplSrc);
        self::assertStringContainsString('travel_core.provider', $tplSrc);
        self::assertStringContainsString('{foreach from=$alt_requests', $tplSrc);
    }

    public function testPageIsRegisteredInBothAdminMenus(): void
    {
        $appRoot = dirname(__DIR__, 3);
        $actions = (string) file_get_contents($appRoot . '/schemas/menu/actions.post.php');
        $menu = (string) file_get_contents($appRoot . '/schemas/menu/menu.post.php');

        self::assertStringContainsString("'travel_alternatives.manage'", $actions);
        self::assertStringContainsString('travel_alternatives.manage', $menu);
    }
}
