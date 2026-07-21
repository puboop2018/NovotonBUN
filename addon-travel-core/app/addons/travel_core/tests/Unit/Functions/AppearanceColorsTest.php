<?php

declare(strict_types=1);

namespace {
    // Load the procedural functions-under-test in the GLOBAL namespace, as
    // CS-Cart loads them (pattern: UpdateCscartCurrenciesTest).
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    // CS-Cart storage stubs — must be plain global functions so the helpers'
    // function_exists() checks bind to them.
    if (!function_exists('fn_get_storage_data')) {
        function fn_get_storage_data(string $name): ?string
        {
            return \Tygh\Addons\TravelCore\Tests\Unit\Functions\StorageStub::$data[$name] ?? null;
        }
    }
    if (!function_exists('fn_set_storage_data')) {
        function fn_set_storage_data(string $name, string $value): void
        {
            \Tygh\Addons\TravelCore\Tests\Unit\Functions\StorageStub::$data[$name] = $value;
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/hotels.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Registry;

    /** In-memory stand-in for CS-Cart's ?:storage_data KV table. */
    final class StorageStub
    {
        /** @var array<string, string> */
        public static array $data = [];
    }

    /**
     * Pins the storage-backed appearance-colors store: colors persisted via
     * fn_travel_core_save_appearance_colors survive independently of the
     * per-area registry caches (the Settings-API path lost them on cache
     * clear and never reached the storefront), and the reader prefers
     * storage over any legacy registry/settings values.
     */
    final class AppearanceColorsTest extends TestCase
    {
        protected function setUp(): void
        {
            StorageStub::$data = [];
            Registry::del('addons.travel_core');
        }

        protected function tearDown(): void
        {
            StorageStub::$data = [];
            Registry::del('addons.travel_core');
        }

        public function testSaveWritesJsonToStorageAndRefreshesTheRegistry(): void
        {
            \fn_travel_core_save_appearance_colors(['color_accent' => '#7c45ba', 'color_primary' => '']);

            self::assertSame(
                ['color_accent' => '#7c45ba', 'color_primary' => ''],
                json_decode(StorageStub::$data['travel_core_appearance'] ?? '', true),
                'colors must land in ?:storage_data as JSON',
            );

            $tc = Registry::get('addons.travel_core');
            self::assertIsArray($tc);
            self::assertSame('#7c45ba', $tc['color_accent'], 'same-request registry refresh');
        }

        public function testSavedColorsRoundTripThroughTheReader(): void
        {
            \fn_travel_core_save_appearance_colors(['color_accent' => '#7c45ba']);

            self::assertSame(['color_accent' => '#7c45ba'], \fn_travel_core_get_appearance_colors());
        }

        public function testStorageWinsOverLegacyRegistryValues(): void
        {
            // Legacy value from the settings/registry era…
            Registry::set('addons.travel_core', ['color_accent' => '#febb02', 'unrelated_setting' => 'x']);
            // …superseded by the storage-backed save.
            StorageStub::$data['travel_core_appearance'] = (string) json_encode(['color_accent' => '#7c45ba']);

            self::assertSame(
                ['color_accent' => '#7c45ba'],
                \fn_travel_core_get_appearance_colors(),
                'storage is the source of truth; non-color settings never leak into the map',
            );
        }

        public function testReaderFallsBackToRegistryWhenStorageIsEmpty(): void
        {
            Registry::set('addons.travel_core', ['color_primary' => '#003580', 'unrelated_setting' => 'x']);

            self::assertSame(['color_primary' => '#003580'], \fn_travel_core_get_appearance_colors());
        }

        public function testNonColorKeysInStorageAreIgnored(): void
        {
            StorageStub::$data['travel_core_appearance'] = (string) json_encode([
                'color_danger' => '#d32f2f',
                'injected' => 'nope',
            ]);

            self::assertSame(['color_danger' => '#d32f2f'], \fn_travel_core_get_appearance_colors());
        }
    }
}
