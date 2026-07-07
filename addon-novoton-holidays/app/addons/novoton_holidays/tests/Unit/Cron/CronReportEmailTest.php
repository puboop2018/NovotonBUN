<?php
declare(strict_types=1);

// Capturing stub for the procedural email builder (functions/email.php is not
// loaded in unit tests). Must live in the global namespace, declared before
// the SUT calls it.
namespace {
    if (!function_exists('fn_novoton_holidays_send_import_report_email')) {
        /**
         * @param array<int, array<string, mixed>> $results
         * @param array<string, mixed> $summary
         */
        function fn_novoton_holidays_send_import_report_email($results, $import_type, $summary, $country = '', $detail_log = ''): bool
        {
            $GLOBALS['__nvt_report_email_calls'][] = [
                'results' => $results,
                'import_type' => $import_type,
                'summary' => $summary,
                'country' => $country,
                'detail_log' => $detail_log,
            ];
            return true;
        }
    }
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron {
    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
    use Tygh\Addons\NovotonHolidays\Helpers\SyncLogger;

    /**
     * The cron report email must carry the SAME detail the cron prints
     * (regression: emails showed only aggregate counters and "Country: ALL",
     * and the advertised report file was never written), and exactly ONE
     * report email may be sent per run (regression: the controller's
     * completion email duplicated the command's own report).
     */
    final class CronReportEmailTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__nvt_report_email_calls'] = [];
        }

        /** @return array<int, array<string, mixed>> */
        private static function emailCalls(): array
        {
            return $GLOBALS['__nvt_report_email_calls'];
        }

        public function testSendEmailReportCarriesCollectedRunLogAndMarksSent(): void
        {
            $logger = new SyncLogger('resort_list');
            $logger->setOutputCallback(static function (string $msg): void {});
            $logger->output('Syncing resort list from Novoton API...');
            $logger->output('Fetching ALBANIA... ', false);
            $logger->output('4 resorts (1 added, 3 updated)');

            self::assertFalse($logger->isEmailSent());
            $logger->sendEmailReport([], 'ALBANIA');
            self::assertTrue($logger->isEmailSent());

            $calls = self::emailCalls();
            self::assertCount(1, $calls);
            self::assertSame('resort_list', $calls[0]['import_type']);
            self::assertSame('ALBANIA', $calls[0]['country']);
            self::assertIsString($calls[0]['detail_log']);
            self::assertStringContainsString('Fetching ALBANIA...', $calls[0]['detail_log']);
            self::assertStringContainsString('4 resorts (1 added, 3 updated)', $calls[0]['detail_log']);
        }

        public function testSendReportForwardsCountryAndRunLogAndSuppressesDuplicate(): void
        {
            $logger = new SyncLogger('resort_list');
            $logger->setOutputCallback(static function (string $msg): void {});
            $logger->output('Countries: ALBANIA');
            $logger->output('Total: 4 resorts synced (new: 1), errors: 0');

            $command = (new \ReflectionClass(ReportingProbeCommand::class))->newInstanceWithoutConstructor();
            $loggerProp = new \ReflectionProperty(AbstractCronCommand::class, 'logger');
            $loggerProp->setValue($command, $logger);

            $method = new \ReflectionMethod(AbstractCronCommand::class, 'sendReport');
            $method->invoke($command, 'resort_list', ['added' => 1, 'updated' => 3], 'ALBANIA');

            $calls = self::emailCalls();
            self::assertCount(1, $calls);
            self::assertSame('ALBANIA', $calls[0]['country'], 'the synced countries must reach the email, not "ALL"');
            self::assertStringContainsString('Countries: ALBANIA', $calls[0]['detail_log']);
            self::assertStringContainsString('Total: 4 resorts synced (new: 1), errors: 0', $calls[0]['detail_log']);

            // The command reported for the whole run — the controller's
            // completion email must be suppressed.
            self::assertTrue($logger->isEmailSent());
        }
    }

    /** Minimal concrete command for exercising the protected reporting seam. */
    final class ReportingProbeCommand extends AbstractCronCommand
    {
        public static function getModes(): array
        {
            return [];
        }

        public static function getDescription(): string
        {
            return 'test probe';
        }

        public function execute(): array
        {
            return ['success' => true];
        }
    }
}
