<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Contract for cron job dispatchers.
 *
 * Each provider addon implements its own dispatcher with provider-specific
 * command classes, but all follow this shared interface for authentication
 * helpers and the shared CronRunner.
 */
interface CronDispatcherInterface
{
    /**
     * Check if a cron mode is registered.
     */
    public function hasMode(string $mode): bool;

    /**
     * Dispatch a cron job by mode.
     *
     * @param string $mode   The cron mode to execute
     * @param array<string, mixed>  $params Additional parameters from CLI or HTTP
     * @return array{success: bool, error?: string, message?: string} Result from the command
     */
    public function dispatch(string $mode, array $params = []): array;

    /**
     * Get all available modes with their descriptions.
     *
     * @return array<string, string> mode => description
     */
    public static function getAvailableModes(): array;
}
