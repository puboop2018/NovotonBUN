<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Cron\AbstractCronCommand;
use Tygh\Addons\TravelCore\Cron\CommandDiscovery;

/**
 * The shared Commands/-directory auto-discovery both provider dispatchers
 * delegate to. Fixture command files are written to a temp dir at runtime.
 */
final class CommandDiscoveryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cd-test-' . getmypid();
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMapsEveryDeclaredModeToItsCommandClass(): void
    {
        file_put_contents($this->dir . '/AlphaCommand.php', <<<'PHP'
<?php
namespace TravelCoreDiscoveryFixture;
class AlphaCommand extends \Tygh\Addons\TravelCore\Cron\AbstractCronCommand
{
    public function execute(): array { return ['success' => true]; }
    public static function getDescription(): string { return 'alpha'; }
    public static function getModes(): array { return ['alpha', 'alpha_full']; }
}
PHP);
        // Not a subclass — must be skipped, not fatal.
        file_put_contents($this->dir . '/StrayCommand.php', <<<'PHP'
<?php
namespace TravelCoreDiscoveryFixture;
class StrayCommand
{
    public static function getModes(): array { return ['stray']; }
}
PHP);
        // Doesn't match *Command.php — never even loaded.
        file_put_contents($this->dir . '/Helper.php', '<?php intentionally invalid syntax here');

        $map = CommandDiscovery::map($this->dir, 'TravelCoreDiscoveryFixture\\', AbstractCronCommand::class);

        self::assertSame([
            'alpha' => 'TravelCoreDiscoveryFixture\\AlphaCommand',
            'alpha_full' => 'TravelCoreDiscoveryFixture\\AlphaCommand',
        ], $map);
    }

    public function testMissingDirectoryYieldsEmptyMap(): void
    {
        self::assertSame([], CommandDiscovery::map($this->dir . '/nope', 'X\\', AbstractCronCommand::class));
    }

    public function testBothProviderDispatchersDelegateToTheSharedDiscovery(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        foreach ([
            'addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/CronDispatcher.php',
            'addon-sphinx-holidays/app/addons/sphinx_holidays/src/Cron/CronDispatcher.php',
        ] as $rel) {
            $src = (string) file_get_contents($repoRoot . '/' . $rel);
            self::assertStringContainsString('CommandDiscovery::map(', $src, $rel);
            self::assertStringNotContainsString(
                "glob(",
                $src,
                "{$rel} must not carry its own discovery glob anymore",
            );
        }
    }
}
