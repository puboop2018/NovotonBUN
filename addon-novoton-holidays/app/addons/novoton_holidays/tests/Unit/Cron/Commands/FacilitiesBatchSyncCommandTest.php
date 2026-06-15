<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Cron\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tygh\Addons\NovotonHolidays\Cron\Commands\FacilitiesBatchSyncCommand;
use Tygh\Addons\NovotonHolidays\Helpers\SyncInterface;

/**
 * Minimal SyncInterface double serving a fixed getStatus() payload — printStatus()
 * is typed against SyncInterface, so a structural anonymous class won't satisfy
 * the parameter type at runtime.
 */
final class FacilitiesSyncDouble implements SyncInterface
{
    /** @param array<string, mixed> $status */
    public function __construct(private array $status)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        return $this->status;
    }

    public function setBatchSize(int $size): void
    {
    }

    public function setMaxExecutionTime(int $seconds): void
    {
    }

    public function setUnlimited(bool $unlimited): void
    {
    }

    public function setOutputCallback(callable $callback): void
    {
    }
}

/**
 * Characterization coverage for FacilitiesBatchSyncCommand's status/result
 * rendering, pinned with the boundary-typing paydown that routed the sync's
 * `mixed` status/result values through TypeCoerce. Built without its constructor
 * (which needs the API kit + logger); a capturing output callback records the
 * rendered lines. Inputs are deliberately string-typed to prove the coercion.
 */
#[CoversClass(FacilitiesBatchSyncCommand::class)]
final class FacilitiesBatchSyncCommandTest extends TestCase
{
    private FacilitiesBatchSyncCommand $command;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->command = (new ReflectionClass(FacilitiesBatchSyncCommand::class))
            ->newInstanceWithoutConstructor();

        $this->captured = [];
        $this->command->setOutputCallback(function (string $msg, bool $addNewline = true): void {
            $this->captured[] = $msg;
        });
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($this->command, $method);
        $m->setAccessible(true);

        return $m->invoke($this->command, ...$args);
    }

    private function renderedOutput(): string
    {
        return implode("\n", $this->captured);
    }

    public function testGetModesAndDescription(): void
    {
        $this->assertSame(['hotel_facilities_batched'], FacilitiesBatchSyncCommand::getModes());
        $this->assertStringContainsString('facilities', strtolower(FacilitiesBatchSyncCommand::getDescription()));
    }

    public function testPrintResultInProgressCoercesStringNumbers(): void
    {
        $this->invoke('printResult', [
            'status' => 'in_progress',
            'synced_this_run' => '7',
            'processed' => '30',
            'total' => '200',
            'remaining' => '170',
            'estimated_runs_remaining' => '6',
        ]);

        $out = $this->renderedOutput();
        $this->assertStringContainsString('Processed this run: 7', $out);
        $this->assertStringContainsString('Total progress: 30/200', $out);
        $this->assertStringContainsString('Remaining: 170', $out);
        $this->assertStringContainsString('Estimated runs remaining: 6', $out);
    }

    public function testPrintStatusInProgressRendersCoercedFields(): void
    {
        $sync = new FacilitiesSyncDouble([
            'status' => 'in_progress',
            'started_at' => '2026-06-15 09:00:00',
            'processed' => '30',
            'total' => '200',
            'percent' => '15',
            'synced' => '28',
            'errors' => '2',
            'elapsed' => '12s',
            'eta' => '60s',
        ]);

        $result = $this->invoke('printStatus', $sync);

        $out = $this->renderedOutput();
        $this->assertStringContainsString('Status: in_progress', $out);
        $this->assertStringContainsString('Progress: 30/200 (15%)', $out);
        $this->assertStringContainsString('Synced: 28, Errors: 2', $out);
        $this->assertSame(true, $result['success']);
    }

    public function testPrintStatusIdleUsesFallback(): void
    {
        $sync = new FacilitiesSyncDouble(['status' => 'idle']);

        $this->invoke('printStatus', $sync);

        $this->assertStringContainsString('Last Sync: Never', $this->renderedOutput());
    }
}
