<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Install\LanguageDelivery;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * Pins the language-delivery rules.
 *
 * Field failure this replaces: a Romanian storefront rendered
 * "_travel_core.your_booking_details", "_travel_core.for",
 * "_travel_core.total_stay" and seven more raw keys on the booking form, the
 * cart and the checkout — while every NEIGHBOURING label rendered fine. The
 * split was exact: labels present when the store was installed worked, labels
 * added afterwards did not. Their only delivery path is the runtime seeder,
 * and it had silently written nothing for months.
 *
 * Both halves are covered here: the language-resolution ladder as pure logic,
 * and the seed/probe behaviour against the db_* stubs. The two decisions that
 * caused the outage — which language codes to write, and when a language may be
 * marked as done — can never regress silently again.
 */
final class LanguageDeliveryTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private static function vars(): array
    {
        return [
            'travel_core.your_booking_details' => ['en' => 'Your booking details', 'ro' => 'Detaliile rezervării'],
            'travel_core.check_in' => ['en' => 'Check-in', 'ro' => 'Check-in'],
        ];
    }

    /**
     * Root cause #2: the old seeder wrote the literal codes it found in
     * lang_keys.php ('en', 'ro') and never consulted ?:languages. A store
     * whose Romanian row is 'ro-RO' therefore received ZERO usable rows — and
     * still got stamped as seeded.
     */
    public function testRegionalStoreCodesResolveToTheirBaseLanguage(): void
    {
        $map = self::invokeResolve(self::vars(), ['en-US', 'ro-RO']);

        self::assertSame(['en-US' => 'en', 'ro-RO' => 'ro'], $map);
    }

    public function testExactCodesWin(): void
    {
        $map = self::invokeResolve(self::vars(), ['ro', 'en']);

        self::assertSame(['ro' => 'ro', 'en' => 'en'], $map);
    }

    /**
     * A language nobody translated still gets English labels. Raw keys are the
     * one outcome that is never acceptable.
     */
    public function testUntranslatedStoreLanguagesFallBackToEnglish(): void
    {
        $map = self::invokeResolve(self::vars(), ['de', 'fr']);

        self::assertSame(['de' => 'en', 'fr' => 'en'], $map);
    }

    /** Underscored and upper-cased spellings reduce to the same base. */
    public function testCodeSpellingVariantsAreNormalised(): void
    {
        $map = self::invokeResolve(self::vars(), ['RO_ro']);

        self::assertSame(['RO_ro' => 'ro'], $map);
    }

    /**
     * The canary is what the seeder reads back before it dares stamp a
     * language as done, so it must be the same row on every run — not
     * "whichever key happens to be first in the source file".
     */
    public function testCanaryIsTheAlphabeticallyFirstKey(): void
    {
        self::assertSame('travel_core.check_in', LanguageDelivery::canaryKey(self::vars()));
        self::assertSame('travel_core.check_in', LanguageDelivery::canaryKey(array_reverse(self::vars(), true)));
    }

    public function testCanaryOfAnEmptySetIsEmpty(): void
    {
        self::assertSame('', LanguageDelivery::canaryKey([]));
    }

    /**
     * Root cause #1, guarded at the source level: the stamp write must sit
     * behind the read-back, never before it. A stamp written regardless of
     * whether a row landed is what disarmed the probe permanently.
     */
    public function testStampIsWrittenOnlyAfterTheCanaryReadsBack(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Install/LanguageDelivery.php',
        );

        $readBack = strpos($src, '$written = db_get_field(');
        $bail = strpos($src, "\$result['failed'][] = \$storeLang;");
        self::assertIsInt($readBack);
        self::assertIsInt($bail);

        // The stamp write inside seed() — the first one AFTER the bail branch
        // (isCurrent() mentions $stampName too, but only to read it).
        $stamp = strpos($src, '$stampName,', $bail);
        self::assertIsInt($stamp);
        self::assertLessThan($bail, $readBack, 'the canary is read before the failure branch');
        self::assertLessThan($stamp, $bail, 'a language that failed verification never reaches the stamp write');
    }

    /**
     * Root cause #3: the probe must ask the database whether the LABELS are
     * there, not merely whether a stamp row exists — otherwise a store that
     * lost its rows never heals without a file change.
     */
    public function testProbeCountsLanguagesMissingTheFingerprint(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Install/LanguageDelivery.php',
        );

        self::assertStringContainsString('FROM ?:languages AS l', $src);
        self::assertStringContainsString('lv.name IS NULL', $src);
        // Fails closed: an unreadable languages table must report "not current".
        self::assertStringContainsString("is_scalar(\$stale) && (string) \$stale !== '' && (int) \$stale === 0", $src);
    }

    /**
     * Every addon's init.php must run its heal through the guard: these fire
     * inside fn_init_addons(), where an uncaught throw is a 503 on every page
     * (the CART_LANGUAGE outage). And none of them may be AREA-gated again —
     * admin-only healing is what left CUSTOMERS reading raw keys.
     */
    public function testEveryAddonHealsGuardedAndInEveryArea(): void
    {
        $repoRoot = dirname(__DIR__, 7);
        $inits = [
            'addon-travel-core/app/addons/travel_core/init.php',
            'addon-novoton-holidays/app/addons/novoton_holidays/init.php',
            'addon-sphinx-holidays/app/addons/sphinx_holidays/init.php',
        ];

        foreach ($inits as $relative) {
            $src = (string) file_get_contents($repoRoot . '/' . $relative);

            $call = strpos($src, 'fn_travel_core_heal_language_keys(');
            self::assertIsInt($call, $relative . ': no language heal');

            // The `if (...) {` that opens the heal block, and the guard call
            // between it and the heal itself.
            $blockStart = strrpos(substr($src, 0, $call), "\nif (");
            self::assertIsInt($blockStart, $relative);
            $block = substr($src, $blockStart, $call - $blockStart);

            self::assertStringContainsString('fn_travel_core_self_heal_guard(', $block, $relative . ': unguarded heal');
            self::assertStringNotContainsString(
                'AREA',
                $block,
                $relative . ': the language heal must run in every area, not just admin',
            );
        }
    }

    /** Fresh installs seed directly instead of relying on the .po import. */
    public function testInstallSeedsLabels(): void
    {
        $repoRoot = dirname(__DIR__, 7);

        $core = (string) file_get_contents($repoRoot . '/addon-travel-core/app/addons/travel_core/func.php');
        self::assertMatchesRegularExpression(
            '/function fn_travel_core_post_install.*?fn_travel_core_seed_language_keys\(\)/s',
            $core,
        );

        $novoton = (string) file_get_contents(
            $repoRoot . '/addon-novoton-holidays/app/addons/novoton_holidays/functions/install.php',
        );
        self::assertMatchesRegularExpression(
            '/function fn_novoton_holidays_post_install.*?fn_novoton_holidays_seed_language_keys\(\)/s',
            $novoton,
        );

        $sphinx = (string) file_get_contents(
            $repoRoot . '/addon-sphinx-holidays/app/addons/sphinx_holidays/func.php',
        );
        self::assertMatchesRegularExpression(
            '/function fn_sphinx_holidays_post_install.*?fn_sphinx_holidays_seed_language_keys\(\)/s',
            $sphinx,
        );
    }

    /**
     * Sphinx's settings-label mirror must survive the move to the shared
     * delivery path — losing it would leave its settings page with blank
     * field labels, a different silent failure of the same family.
     */
    public function testSphinxStillMirrorsItsSettingsLabels(): void
    {
        $repoRoot = dirname(__DIR__, 7);

        $seeder = (string) file_get_contents(
            $repoRoot . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Install/LanguageSeeder.php',
        );
        self::assertStringContainsString('public static function mirrorSettingsDescriptions(array $vars): void', $seeder);
        self::assertStringContainsString('?:settings_descriptions', $seeder);
        self::assertStringContainsString('LanguageDelivery::seed(', $seeder);

        $init = (string) file_get_contents(
            $repoRoot . '/addon-sphinx-holidays/app/addons/sphinx_holidays/init.php',
        );
        self::assertStringContainsString('LanguageSeeder::mirrorSettingsDescriptions($vars)', $init);
    }

    // ── Behaviour, against the db_* stubs ───────────────────────────────────

    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * The whole point of the rewrite: a store whose Romanian language is
     * spelled 'ro-RO' must receive Romanian VALUES under the 'ro-RO' code.
     * The old seeder wrote 'ro', which matched nothing, and stamped anyway.
     */
    public function testSeedWritesEveryInstalledLanguageWithItsOwnValues(): void
    {
        DbStub::$getFields = static fn (): array => ['en-US', 'ro-RO'];

        $written = [];
        DbStub::$query = static function (string $sql, ...$p) use (&$written): int {
            $written[] = [$p[1] ?? '', $p[0] ?? '', $p[2] ?? ''];

            return 1;
        };
        // Canary read-back returns whatever the seeder just wrote.
        DbStub::$getField = static function (string $sql, ...$p) use (&$written) {
            foreach ($written as [$lang, $name, $value]) {
                if ($name === $p[0] && $lang === $p[1]) {
                    return $value;
                }
            }

            return null;
        };

        $result = LanguageDelivery::seed('travel_core._lang_seed_hash', self::vars(), 'fp-1');

        self::assertSame(['en-US', 'ro-RO'], $result['seeded']);
        self::assertSame([], $result['failed']);

        $roValues = [];
        foreach ($written as [$lang, $name, $value]) {
            if ($lang === 'ro-RO') {
                $roValues[$name] = $value;
            }
        }
        self::assertSame('Detaliile rezervării', $roValues['travel_core.your_booking_details'] ?? null);
        self::assertSame('fp-1', $roValues['travel_core._lang_seed_hash'] ?? null);
    }

    /**
     * Root cause #1, as behaviour: when the labels do not come back out of the
     * database, that language must NOT be stamped — the stamp is what disarms
     * the probe, and stamping a failed write is how the store stayed broken
     * for months.
     */
    public function testAFailedWriteIsNeverStamped(): void
    {
        DbStub::$getFields = static fn (): array => ['en'];
        $written = [];
        DbStub::$query = static function (string $sql, ...$p) use (&$written): int {
            $written[] = $p[0] ?? '';

            return 1;
        };
        DbStub::$getField = static fn () => null;   // nothing reads back

        $result = LanguageDelivery::seed('travel_core._lang_seed_hash', self::vars(), 'fp-1');

        self::assertSame([], $result['seeded']);
        self::assertSame(['en'], $result['failed']);
        self::assertNotContains('travel_core._lang_seed_hash', $written, 'the stamp must not be written');
    }

    /**
     * Root cause #3, as behaviour: the probe asks the DATABASE whether every
     * installed language carries this fingerprint. Any language missing it —
     * rows lost, a language added later — re-arms the heal with no file
     * change at all.
     */
    public function testProbeIsCurrentOnlyWhenNoLanguageIsStale(): void
    {
        DbStub::$getField = static fn (): string => '0';
        self::assertTrue(LanguageDelivery::isCurrent('travel_core._lang_seed_hash', 'fp-1'));

        DbStub::$getField = static fn (): string => '1';
        self::assertFalse(LanguageDelivery::isCurrent('travel_core._lang_seed_hash', 'fp-1'));
    }

    /** Fails closed: an unreadable ?:languages must report "not current". */
    public function testProbeFailsClosed(): void
    {
        DbStub::$getField = static fn () => null;
        self::assertFalse(LanguageDelivery::isCurrent('travel_core._lang_seed_hash', 'fp-1'));
    }

    public function testStoreLanguagesAreDedupedAndTrimmed(): void
    {
        DbStub::$getFields = static fn (): array => ['en', ' ro ', 'en', ''];

        self::assertSame(['en', 'ro'], LanguageDelivery::storeLanguages());
    }

    /** No variables, no writes — and certainly no stamp. */
    public function testSeedingAnEmptySetDoesNothing(): void
    {
        $calls = 0;
        DbStub::$query = static function () use (&$calls): int {
            $calls++;

            return 1;
        };

        self::assertSame(['seeded' => [], 'failed' => []], LanguageDelivery::seed('stamp', [], 'fp'));
        self::assertSame(0, $calls);
    }

    /**
     * @param array<string, array<string, string>> $vars
     * @param list<string> $installed
     *
     * @return array<string, string>
     */
    private static function invokeResolve(array $vars, array $installed): array
    {
        // storeLanguages() is the only part that needs a database, so the
        // installed set is injectable — this drives the real ladder.
        return LanguageDelivery::resolveLanguages($vars, $installed);
    }
}
