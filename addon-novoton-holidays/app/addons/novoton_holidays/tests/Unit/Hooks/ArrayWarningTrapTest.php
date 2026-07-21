<?php

declare(strict_types=1);

namespace {
    // Load the procedural hook functions under test in the GLOBAL namespace,
    // as CS-Cart loads them (pattern: LinkOrderBookingsTest).
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/hooks/cart_hooks.php';
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\TestCase;
    use Tygh\Registry;

    /**
     * Pins the admin-side "Array to string conversion" trap: the literal
     * "Array" on dispatch=novoton_holidays.manage is emitted by the site's
     * CS-Cart kit (this repo's addons are proven clean — 33f15ad); the trap
     * logs the warning's file:line to var/novoton_tpl_trace.log while
     * chaining every error to the previously installed handler unchanged.
     */
    final class ArrayWarningTrapTest extends TestCase
    {
        private string $root;

        protected function setUp(): void
        {
            $this->root = sys_get_temp_dir() . '/nvt_trap_' . bin2hex(random_bytes(4));
            mkdir($this->root . '/var', 0777, true);
            Registry::set('config.dir.root', $this->root);
        }

        protected function tearDown(): void
        {
            Registry::del('config.dir.root');
            @unlink($this->root . '/var/novoton_tpl_trace.log');
            @rmdir($this->root . '/var');
            @rmdir($this->root);
        }

        private function logContents(): string
        {
            $log = $this->root . '/var/novoton_tpl_trace.log';

            return is_file($log) ? (string) file_get_contents($log) : '';
        }

        public function testLoggerWritesTimestampMessageFileAndLine(): void
        {
            \fn_novoton_holidays_log_array_conversion_warning(
                'Array to string conversion',
                '/var/www/html/var/cache/templates/backend/index.tpl.php',
                123,
            );

            self::assertMatchesRegularExpression(
                '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] Array to string conversion '
                    . 'in \/var\/www\/html\/var\/cache\/templates\/backend\/index\.tpl\.php:123$/m',
                $this->logContents(),
            );
        }

        public function testLoggerIsSilentWithoutAConfiguredRoot(): void
        {
            Registry::del('config.dir.root');

            \fn_novoton_holidays_log_array_conversion_warning('Array to string conversion', 'x.php', 1);

            self::assertSame('', $this->logContents());
        }

        public function testTrapLogsMatchingWarningsAndChainsEverythingToThePreviousHandler(): void
        {
            $seen = [];
            $recorder = static function (int $errno, string $errstr) use (&$seen): bool {
                $seen[] = $errstr;

                return true; // swallow so PHPUnit's converter never fires
            };

            set_error_handler($recorder);
            try {
                \fn_novoton_holidays_install_array_warning_trap();
                try {
                    trigger_error('Array to string conversion', E_USER_WARNING);
                    trigger_error('unrelated warning', E_USER_WARNING);
                } finally {
                    restore_error_handler(); // pop the trap
                }
            } finally {
                restore_error_handler(); // pop the recorder
            }

            // BOTH warnings reached the previous handler — chaining intact.
            self::assertSame(['Array to string conversion', 'unrelated warning'], $seen);

            // Only the matching one was logged, with " in <file>:<line>".
            $log = $this->logContents();
            self::assertStringContainsString('Array to string conversion in', $log);
            self::assertStringNotContainsString('unrelated warning', $log);
        }

        public function testDispatchBeforeDisplayInstallsTheTrapForNovotonAdminDispatches(): void
        {
            // Source pin for the WIRING (calling the hook fn directly would
            // need the full view/Registry runtime): the admin branch must
            // gate on AREA 'A' + a novoton_ dispatch and call the installer.
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/hooks/cart_hooks.php');

            self::assertMatchesRegularExpression(
                "/str_starts_with\\(\\\$dispatch, 'novoton_'\\) && defined\\('AREA'\\) && AREA === 'A'\\s*\\)\\s*\\{\\s*"
                    . 'fn_novoton_holidays_install_array_warning_trap\\(\\);/s',
                $source,
                'dispatch_before_display must install the array-warning trap for novoton admin dispatches',
            );
        }
    }
}
