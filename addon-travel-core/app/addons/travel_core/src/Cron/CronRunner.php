<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;

/**
 * Shared cron entry-point helper.
 *
 * Extracts the duplicated boilerplate from each addon's cron.php:
 * CLI arg parsing, access-key authentication, mode sanitization,
 * dispatch + output + error handling.
 *
 * Each addon's cron.php becomes ~15 lines: bootstrap, build dispatcher, run().
 *
 * Note: this class legitimately uses `exit()` as a cron entry point,
 * which is why ExitExpression is excluded for this file in phpmd.xml.
 */
class CronRunner
{
    public function __construct(
        private readonly string $addonLabel,
        private readonly CronDispatcherInterface $dispatcher,
        private readonly ?string $defaultMode = null,
        /** @var callable(\Throwable): void|null */
        private readonly mixed $onError = null,
    ) {
    }

    /**
     * Parse CLI/HTTP arguments and return [accessKey, mode, extraParams].
     *
     * @return array{string, string, array<string, string>}
     */
    public static function parseArgs(): array
    {
        global $argv;

        $accessKey = $_GET['access_key'] ?? '';
        $mode = $_GET['mode'] ?? '';
        $params = [];

        if (isset($argv) && is_array($argv)) {
            foreach ($argv as $i => $arg) {
                if ($i === 0) {
                    continue;
                }
                if (str_starts_with($arg, 'access_key=')) {
                    $accessKey = substr($arg, strlen('access_key='));
                } elseif (str_starts_with($arg, 'mode=')) {
                    $mode = substr($arg, strlen('mode='));
                } elseif (str_contains($arg, '=')) {
                    [$k, $v] = explode('=', $arg, 2);
                    $params[$k] = $v;
                }
            }
        }

        return [$accessKey, $mode, $params];
    }

    /**
     * Authenticate the access key using timing-safe comparison.
     *
     * Exits with an error message if authentication fails.
     */
    public static function authenticate(string $storedKey, string $providedKey, string $addonLabel = ''): void
    {
        if (empty($storedKey)) {
            exit("ERROR: Cron access key not set in {$addonLabel} addon settings.\n");
        }
        if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
            exit("ERROR: Invalid or missing access key.\n");
        }
    }

    /**
     * Sanitize a mode string to alphanumeric + underscores only.
     */
    public static function sanitizeMode(string $mode): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '', strtolower($mode));
    }

    /**
     * Run the full cron lifecycle: validate mode, dispatch, handle output/errors.
     *
     * @param string $mode Sanitized mode name
     * @param array<string,string> $params Extra CLI/HTTP parameters
     */
    public function run(string $mode, array $params = []): never
    {
        // Apply default mode if empty
        if ($mode === '' && $this->defaultMode !== null) {
            $mode = $this->defaultMode;
        }

        // Validate mode
        if (!$this->dispatcher->hasMode($mode)) {
            echo "Unknown mode: {$mode}\n\n";
            echo "Available modes:\n";
            foreach ($this->dispatcher::getAvailableModes() as $m => $desc) {
                echo "  {$m} - {$desc}\n";
            }
            exit(1);
        }

        echo '[' . date('Y-m-d H:i:s') . "] {$this->addonLabel} Cron Started - Mode: {$mode}\n";

        if (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', [
                'message' => "{$this->addonLabel} cron job started (mode: {$mode})",
            ]);
        }

        try {
            $this->dispatcher->dispatch($mode, $params);

            echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
            exit(0);
        } catch (\Throwable $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";

            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', [
                    'message' => "{$this->addonLabel} cron error: " . $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            exit(1);
        }
    }
}
