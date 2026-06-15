<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tygh\Addons\NovotonHolidays\Cron\Commands\BatchedSyncCommand;

/**
 * Characterization coverage for BatchedSyncCommand's option-building and
 * status/result rendering, pinned with the boundary-typing paydown that routed
 * the sync's `mixed` status/result values through TypeCoerce. The command is
 * built without its constructor (which needs the API kit + logger); a capturing
 * output callback records the rendered lines, and the status getter is fed by a
 * tiny sync double. Inputs are deliberately string-typed to prove the coercion
 * (e.g. '10' renders as 10).
 */
#[CoversClass(BatchedSyncCommand::class)]
final class BatchedSyncCommandTest extends TestCase
{
    private BatchedSyncCommand $command;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->command = (new ReflectionClass(BatchedSyncCommand::class))
            ->newInstanceWithoutConstructor();

        $this->captured = [];
        $this->command->setOutputCallback(function (string $msg, bool $addNewline = true): void {
            $this->captured[] = $msg;
        });
    }

    /** @param array<string, mixed> $params */
    private function setParams(array $params): void
    {
        $prop = new ReflectionProperty($this->command, 'params');
        $prop->setAccessible(true);
        $prop->setValue($this->command, $params);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($this->command, $method);
        $m->setAccessible(true);

        return $m->invoke($this->command, ...$args);
    }

    private function capturedOutput(): string
    {
        return implode("\n", $this->captured);
    }

    public function testGetModesAndDescription(): void
    {
        $this->assertSame(['hotel_info_batched', 'sync_priceinfo_batched'], BatchedSyncCommand::getModes());
        $this->assertStringContainsString('Batched', BatchedSyncCommand::getDescription());
    }

    public function testGetBatchOptionsFromParams(): void
    {
        $this->setParams(['force_full' => '1', 'reset' => '1']);

        $this->assertSame(['force_full' => true, 'reset' => true], $this->invoke('getBatchOptions'));
        $this->assertStringContainsString('Mode: FORCED FULL SYNC', $this->capturedOutput());
    }

    public function testGetBatchOptionsEmptyWhenNoFlags(): void
    {
        $this->setParams([]);

        $this->assertSame([], $this->invoke('getBatchOptions'));
    }

    public function testPrintBatchResultInProgressCoercesStringNumbers(): void
    {
        $this->invoke('printBatchResult', [
            'status' => 'in_progress',
            'synced_this_run' => '5',
            'processed' => '10',
            'total' => '100',
            'remaining' => '90',
            'estimated_runs_remaining' => '9',
        ], 'hotel_info_batched');

        $out = $this->capturedOutput();
        $this->assertStringContainsString('Processed this run: 5', $out);
        $this->assertStringContainsString('Total progress: 10/100', $out);
        $this->assertStringContainsString('Remaining: 90', $out);
        $this->assertStringContainsString('Estimated runs remaining: 9', $out);
    }

    public function testPrintBatchStatusInProgressRendersCoercedFields(): void
    {
        $sync = new class {
            /** @return array<string, mixed> */
            public function getStatus(): array
            {
                return [
                    'status' => 'in_progress',
                    'sync_type' => 'hotel_info',
                    'started_at' => '2026-06-15 09:00:00',
                    'processed' => '10',
                    'total' => '100',
                    'percent' => '50',
                    'synced' => '8',
                    'errors' => '2',
                    'elapsed' => '5s',
                    'eta' => '10s',
                ];
            }
        };

        $result = $this->invoke('printBatchStatus', $sync);

        $out = $this->capturedOutput();
        $this->assertStringContainsString('Status: in_progress', $out);
        $this->assertStringContainsString('Sync Type: hotel_info', $out);
        $this->assertStringContainsString('Progress: 10/100 (50%)', $out);
        $this->assertStringContainsString('Synced: 8, Errors: 2', $out);
        $this->assertSame(true, $result['success']);
    }

    public function testPrintBatchStatusIdleUsesFallbacks(): void
    {
        $sync = new class {
            /** @return array<string, mixed> */
            public function getStatus(): array
            {
                return ['status' => 'idle']; // no last_sync / last_sync_type
            }
        };

        $this->invoke('printBatchStatus', $sync);

        $out = $this->capturedOutput();
        $this->assertStringContainsString('Last Sync: Never', $out);
        $this->assertStringContainsString('Last Type: N/A', $out);
    }
}
