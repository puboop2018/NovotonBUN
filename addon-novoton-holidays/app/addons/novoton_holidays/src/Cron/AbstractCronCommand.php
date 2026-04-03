<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron;

use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\SyncException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Services\Container;

abstract class AbstractCronCommand
{
    protected \Tygh\Addons\NovotonHolidays\NovotonApi $api;

    protected ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger;

    /** @var array */
    protected array $params = [];

    /** @var float */
    protected float $startTime;

    public function __construct(\Tygh\Addons\NovotonHolidays\NovotonApi $api, ?\Tygh\Addons\NovotonHolidays\Helpers\SyncLogger $logger, array $params = [])
    {
        $this->api = $api;
        $this->logger = $logger;
        $this->params = $params;
        $this->startTime = microtime(true);
    }

    abstract public function execute(): array;

    abstract public static function getModes(): array;

    abstract public static function getDescription(): string;

    protected function output(string $message, bool $newline = true): void
    {
        if ($this->logger) {
            $this->logger->output($message, $newline);
            return;
        }

        // Last-resort fallback: should not normally be reached since
        // all callers inject a SyncLogger. Log event so the message
        // is captured even when logger is missing.
        fn_log_event('general', 'runtime', [
            'message' => 'CronCommand output (no logger): ' . $message,
        ]);
    }

    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    protected function getDuration(): float
    {
        return round(microtime(true) - $this->startTime, 1);
    }

    protected function logComplete(string $mode, array $stats = []): void
    {
        fn_log_event('novoton_holidays', 'cron_complete', [
            'timestamp' => time(),
            'mode' => $mode,
            'stats' => $stats,
            'duration' => $this->getDuration()
        ]);
    }

    protected function logToSyncTable(string $type, int $updated, int $failed = 0): void
    {
        $syncRepo = Container::getInstance()->syncLogRepository();
        $syncRepo->create($type, [
            'updated'  => $updated,
            'failed'   => $failed,
            'duration' => (int)$this->getDuration(),
            'status'   => 'completed',
        ]);
    }

    protected function sendReport(string $type, array $stats, string $context = ''): void
    {
        fn_novoton_holidays_send_import_report_email([], $type, $stats, $context);
    }

    /**
     * Execute a sync operation with standardized error handling.
     *
     * Catches SyncException, ApiException, XmlParsingException, and general
     * Throwable, logging each consistently. Returns true on success, false on failure.
     *
     * @param callable $work The sync operation to execute
     * @param string $context Human-readable context for error messages (e.g. "hotel 123")
     * @param array &$errors Array to collect error messages
     * @return bool Whether the operation succeeded
     */
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
