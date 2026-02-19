<?php
namespace Tygh\Addons\NovotonHolidays\Cron;

use Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository;

abstract class AbstractCronCommand
{
    protected $api;
    protected $logger;
    protected $params = [];
    protected $startTime;

    public function __construct($api, $logger, array $params = [])
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
        if ($this->logger && method_exists($this->logger, 'output')) {
            $this->logger->output($message, $newline);
        } else {
            echo $message . ($newline ? "\n" : "");
        }
    }

    protected function getParam(string $key, $default = null)
    {
        return $this->params[$key] ?? $_REQUEST[$key] ?? $default;
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
        $syncRepo = new SyncLogRepository();
        $syncRepo->create($type, [
            'updated'  => $updated,
            'failed'   => $failed,
            'duration' => (int)$this->getDuration(),
            'status'   => 'completed',
        ]);
    }

    protected function sendReport(string $type, array $stats, string $context = ''): void
    {
        if (function_exists('fn_novoton_send_import_report_email')) {
            fn_novoton_send_import_report_email([], $type, $stats, $context);
        }
    }
}
