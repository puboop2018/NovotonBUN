<?php
declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron;

use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\SyncException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Cron\AbstractCronCommand as BaseCommand;

/**
 * Novoton-specific cron command base.
 *
 * Extends travel_core's shared base with Novoton API injection,
 * SyncLogger integration, granular exception handling, and
 * sync-table / email reporting.
 */
abstract class AbstractCronCommand extends BaseCommand
{
    protected \Tygh\Addons\NovotonHolidays\NovotonApi $api;
    protected ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger;
    protected array $params = [];

    public function __construct(
        \Tygh\Addons\NovotonHolidays\NovotonApi $api,
        ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger,
        array $params = [],
    ) {
        parent::__construct();
        $this->api = $api;
        $this->logger = $logger;
        $this->params = $params;

        // Wire SyncLogger as the output callback for the base class
        if ($this->logger !== null) {
            $this->setOutputCallback(fn(string $msg) => $this->logger->output($msg));
        }
    }

    abstract public static function getModes(): array;

    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    protected function logComplete(string $mode, array $stats = []): void
    {
        fn_log_event('novoton_holidays', 'cron_complete', [
            'timestamp' => time(),
            'mode' => $mode,
            'stats' => $stats,
            'duration' => $this->getDuration(),
        ]);
    }

    protected function logToSyncTable(string $type, int $updated, int $failed = 0): void
    {
        $syncRepo = Container::getInstance()->syncLogRepository();
        $syncRepo->create($type, [
            'updated'  => $updated,
            'failed'   => $failed,
            'duration' => (int) $this->getDuration(),
            'status'   => 'completed',
        ]);
    }

    protected function sendReport(string $type, array $stats, string $context = ''): void
    {
        fn_novoton_holidays_send_import_report_email([], $type, $stats, $context);
    }

    /**
     * Novoton-specific error handling with granular exception types.
     *
     * Overrides the base class to distinguish SyncException, ApiException,
     * and XmlParsingException for richer error messages.
     */
    #[\Override]
    protected function trySyncItem(callable $work, string $context, array &$errors): bool
    {
        try {
            $work();
            return true;
        } catch (SyncException $e) {
            $errors[] = $e->getMessage();
        } catch (ApiException $e) {
            $errors[] = "API error for {$context} (HTTP {$e->getHttpCode()}): " . $e->getMessage();
        } catch (XmlParsingException $e) {
            $errors[] = "XML parsing error for {$context}: " . $e->getMessage();
        } catch (\Throwable $e) {
            $errors[] = "Unexpected error for {$context}: " . $e->getMessage();
        }
        return false;
    }
}
