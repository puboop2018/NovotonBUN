<?php

declare(strict_types=1);

namespace {
    // Load the procedural function-under-test in the GLOBAL namespace, exactly as
    // CS-Cart loads it. A capturing fn_update_product() stub records the $_REQUEST
    // payload at call time (before the SUT cleans it up) so we can assert the
    // contract the real CS-Cart pipeline consumes.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CART_LANGUAGE')) {
        define('CART_LANGUAGE', 'en');
    }

    if (!function_exists('fn_update_product')) {
        function fn_update_product($product_data, $product_id = 0, $lang_code = '')
        {
            $GLOBALS['__fn_update_product_calls'][] = [
                'product_data' => $product_data,
                'product_id'   => $product_id,
                'lang_code'    => $lang_code,
                // snapshot of $_REQUEST as CS-Cart would see it
                'request'      => $_REQUEST,
            ];

            return $product_id;
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/hotels.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\TravelCore\Helpers\DebugLogger;

    final class AttachImagesFromUrlsTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__fn_update_product_calls'] = [];
            $_REQUEST = [];
            DebugLogger::$lastImageAttachPath = '';
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        private function calls(): array
        {
            /** @var array<int, array<string, mixed>> $calls */
            $calls = $GLOBALS['__fn_update_product_calls'];
            return $calls;
        }

        public function testReturnsZeroAndDoesNothingForInvalidProductId(): void
        {
            $n = fn_travel_core_attach_images_from_urls(0, ['https://cdn/x.jpg']);

            self::assertSame(0, $n);
            self::assertCount(0, $this->calls(), 'fn_update_product must not be called');
        }

        public function testReturnsZeroForEmptyUrlList(): void
        {
            $n = fn_travel_core_attach_images_from_urls(42, []);

            self::assertSame(0, $n);
            self::assertCount(0, $this->calls());
        }

        public function testCallsUpdateProductOnceWithEmptyDataAndCorrectArgs(): void
        {
            fn_travel_core_attach_images_from_urls(101, ['https://cdn/a.jpg']);

            $calls = $this->calls();
            self::assertCount(1, $calls, 'exactly one fn_update_product call');
            self::assertSame([], $calls[0]['product_data'], 'empty data array must not overwrite product fields');
            self::assertSame(101, $calls[0]['product_id']);
            self::assertSame('en', $calls[0]['lang_code']);
        }

        public function testSetsAttachPathBreadcrumbOnSuccess(): void
        {
            self::assertSame('', DebugLogger::$lastImageAttachPath, 'breadcrumb starts empty');

            fn_travel_core_attach_images_from_urls(101, ['https://cdn/a.jpg']);

            self::assertSame(
                'url/fn_update_product',
                DebugLogger::$lastImageAttachPath,
                'main path must record which pipeline ran',
            );
        }

        public function testDoesNotSetAttachPathForInvalidInput(): void
        {
            fn_travel_core_attach_images_from_urls(0, ['https://cdn/a.jpg']);

            self::assertSame('', DebugLogger::$lastImageAttachPath, 'no breadcrumb when nothing was attached');
        }

        public function testBuildsMainPlusAdditionalRequestPayload(): void
        {
            $pid  = 555;
            $urls = ['https://cdn/main.jpg', 'https://cdn/extra1.jpg', 'https://cdn/extra2.jpg'];

            $n = fn_travel_core_attach_images_from_urls($pid, $urls, true);

            self::assertSame(3, $n, 'returns number of urls handed off');

            $req = $this->calls()[0]['request'];

            // Main image (url index 0)
            self::assertSame('url', $req['type_product_main_image_detailed'][0]);
            self::assertSame('https://cdn/main.jpg', $req['file_product_main_image_detailed'][0]);
            self::assertSame(
                ['type' => 'M', 'object_id' => $pid, 'position' => 0],
                $req['product_main_image_data'][0],
            );

            // Additional images (url indexes 1,2 → additional indexes 0,1)
            self::assertSame('url', $req['type_product_add_additional_image_detailed'][0]);
            self::assertSame('https://cdn/extra1.jpg', $req['file_product_add_additional_image_detailed'][0]);
            self::assertSame(
                ['type' => 'A', 'object_id' => $pid, 'position' => 0],
                $req['product_add_additional_image_data'][0],
            );

            self::assertSame('url', $req['type_product_add_additional_image_detailed'][1]);
            self::assertSame('https://cdn/extra2.jpg', $req['file_product_add_additional_image_detailed'][1]);
            self::assertSame(
                ['type' => 'A', 'object_id' => $pid, 'position' => 1],
                $req['product_add_additional_image_data'][1],
            );
        }

        public function testFirstIsMainFalseMakesAllImagesAdditional(): void
        {
            $n = fn_travel_core_attach_images_from_urls(7, ['https://cdn/a.jpg', 'https://cdn/b.jpg'], false);

            self::assertSame(2, $n);
            $req = $this->calls()[0]['request'];

            self::assertArrayNotHasKey('product_main_image_data', $req, 'no main image when firstIsMain=false');
            self::assertArrayNotHasKey('type_product_main_image_detailed', $req);

            self::assertSame('https://cdn/a.jpg', $req['file_product_add_additional_image_detailed'][0]);
            self::assertSame('https://cdn/b.jpg', $req['file_product_add_additional_image_detailed'][1]);
            self::assertSame('A', $req['product_add_additional_image_data'][0]['type']);
            self::assertSame('A', $req['product_add_additional_image_data'][1]['type']);
        }

        public function testSkipsEmptyUrlStrings(): void
        {
            // index 0 empty → no main written; 'b' becomes the first non-empty (additional, since index!=0)
            fn_travel_core_attach_images_from_urls(9, ['', 'https://cdn/b.jpg']);

            $req = $this->calls()[0]['request'];
            self::assertArrayNotHasKey('product_main_image_data', $req);
            self::assertSame('https://cdn/b.jpg', $req['file_product_add_additional_image_detailed'][0]);
        }

        public function testCleansUpRequestKeysAfterCall(): void
        {
            fn_travel_core_attach_images_from_urls(3, ['https://cdn/a.jpg', 'https://cdn/b.jpg']);

            // The snapshot inside fn_update_product had the keys; the live $_REQUEST
            // must be clean afterwards so nothing bleeds into later product updates.
            foreach ([
                'product_main_image_data',
                'type_product_main_image_detailed',
                'file_product_main_image_detailed',
                'product_add_additional_image_data',
                'type_product_add_additional_image_detailed',
                'file_product_add_additional_image_detailed',
            ] as $key) {
                self::assertArrayNotHasKey($key, $_REQUEST, "{$key} must be unset after the call");
            }
        }
    }
}
