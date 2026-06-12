<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\AbstractConfigProvider;
use Tygh\Registry;

/**
 * Coverage for the shared ConfigProvider base: getSetting defaults, typed
 * coercion helpers, per-request settings caching, and — critically — the
 * per-subclass cache isolation that lets two provider addons extend the
 * same base without clobbering each other's settings.
 */
#[CoversClass(AbstractConfigProvider::class)]
class AbstractConfigProviderTest extends TestCase
{
    protected function setUp(): void
    {
        FakeAlphaConfigProvider::resetSettingsCache();
        FakeBetaConfigProvider::resetSettingsCache();
        Registry::set('addons.fake_alpha', null);
        Registry::set('addons.fake_beta', null);
    }

    public function testGetSettingReturnsValueAndDefault(): void
    {
        Registry::set('addons.fake_alpha', ['api_key' => 'secret', 'empty' => null]);

        $this->assertSame('secret', FakeAlphaConfigProvider::setting('api_key'));
        $this->assertSame('fallback', FakeAlphaConfigProvider::setting('missing', 'fallback'));
        $this->assertSame('fallback', FakeAlphaConfigProvider::setting('empty', 'fallback'));
        $this->assertNull(FakeAlphaConfigProvider::setting('missing'));
    }

    public function testTypedHelpersCoerceValues(): void
    {
        Registry::set('addons.fake_alpha', [
            'name' => 'Sphinx',
            'limit' => '42',
            'ratio' => '1.5',
            'flag_on' => 'Y',
            'flag_off' => 'N',
        ]);

        $this->assertSame('Sphinx', FakeAlphaConfigProvider::str('name'));
        $this->assertSame('dflt', FakeAlphaConfigProvider::str('missing', 'dflt'));
        $this->assertSame(42, FakeAlphaConfigProvider::int('limit'));
        $this->assertSame(7, FakeAlphaConfigProvider::int('missing', 7));
        $this->assertSame(1.5, FakeAlphaConfigProvider::float('ratio'));
        $this->assertTrue(FakeAlphaConfigProvider::bool('flag_on'));
        $this->assertFalse(FakeAlphaConfigProvider::bool('flag_off'));
        $this->assertTrue(FakeAlphaConfigProvider::bool('missing', true));
    }

    public function testSettingsAreCachedUntilReset(): void
    {
        Registry::set('addons.fake_alpha', ['mode' => 'first']);
        $this->assertSame('first', FakeAlphaConfigProvider::setting('mode'));

        // Registry changes are not visible through the per-request cache...
        Registry::set('addons.fake_alpha', ['mode' => 'second']);
        $this->assertSame('first', FakeAlphaConfigProvider::setting('mode'));

        // ...until the cache is explicitly reset.
        FakeAlphaConfigProvider::resetSettingsCache();
        $this->assertSame('second', FakeAlphaConfigProvider::setting('mode'));
    }

    public function testSubclassesDoNotShareCachedSettings(): void
    {
        Registry::set('addons.fake_alpha', ['shared_key' => 'alpha-value']);
        Registry::set('addons.fake_beta', ['shared_key' => 'beta-value']);

        $this->assertSame('alpha-value', FakeAlphaConfigProvider::setting('shared_key'));
        $this->assertSame('beta-value', FakeBetaConfigProvider::setting('shared_key'));

        // Resetting one provider's cache must not evict the other's.
        Registry::set('addons.fake_alpha', ['shared_key' => 'alpha-2']);
        Registry::set('addons.fake_beta', ['shared_key' => 'beta-2']);
        FakeAlphaConfigProvider::resetSettingsCache();

        $this->assertSame('alpha-2', FakeAlphaConfigProvider::setting('shared_key'));
        $this->assertSame('beta-value', FakeBetaConfigProvider::setting('shared_key'));
    }

    public function testNonArrayRegistryValueYieldsEmptySettings(): void
    {
        Registry::set('addons.fake_alpha', 'not-an-array');

        $this->assertSame('dflt', FakeAlphaConfigProvider::setting('anything', 'dflt'));
    }
}

/**
 * Concrete fixture exposing the protected base helpers for assertions.
 */
final class FakeAlphaConfigProvider extends AbstractConfigProvider
{
    #[\Override]
    protected static function addonId(): string
    {
        return 'fake_alpha';
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return self::getSetting($key, $default);
    }

    public static function str(string $key, string $default = ''): string
    {
        return self::getString($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return self::getInt($key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return self::getFloat($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return self::getBool($key, $default);
    }
}

/**
 * Second fixture proving per-subclass cache isolation.
 */
final class FakeBetaConfigProvider extends AbstractConfigProvider
{
    #[\Override]
    protected static function addonId(): string
    {
        return 'fake_beta';
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return self::getSetting($key, $default);
    }
}
