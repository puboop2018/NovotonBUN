<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the shared client-side i18n emitter (travel_i18n.tpl).
 *
 * The translation map consumed by the booking JS
 * (window.TravelTranslations || window.NovotonTranslations) used to be
 * hand-copied inline into novoton search.tpl / booking_form.tpl /
 * blocks/homepage_booking.tpl (×2 themes) and had drifted (55 vs 64 keys).
 * It now lives once in
 * design/themes/{responsive,nova_theme}/.../components/travel_i18n.tpl.
 *
 * This test freezes the key CONTRACT (the union of every key the three old
 * blocks emitted) so a future edit can't silently drop one, keeps the two
 * theme mirrors identical, and prevents any booking/search template from
 * regressing to a hand-built *Translations object.
 */
final class ClientI18nTest extends TestCase
{
    private const PARTIAL = 'addon-travel-core/design/themes/%s/templates/addons/travel_core/components/travel_i18n.tpl';

    /**
     * The frozen contract — the union of keys emitted by the three former
     * inline blocks. Every one must remain emitted by the shared partial.
     *
     * @var list<string>
     */
    private const REQUIRED_KEYS = [
        'addRoom', 'adult', 'adults', 'ageLabel', 'ageLabelSingular', 'ageOfChild',
        'applyChanges', 'april', 'august', 'availability', 'available', 'bookYourStay',
        'calendarPriceFooter', 'changeSearch', 'checkIn', 'checkInPast', 'checkOut',
        'child', 'childAge', 'childLabel', 'children', 'childrenAges', 'continueWithNewRoom',
        'currency', 'currencyCoeff', 'december', 'dobCannotBeFuture', 'done', 'february',
        'fri', 'futureDate', 'goBackToSearch', 'guests', 'includesOnRequest', 'invalidDay',
        'invalidDobFormat', 'invalidMonth', 'invalidYear', 'january', 'july', 'june',
        'loading', 'march', 'may', 'mon', 'newRoom', 'night', 'nights', 'nightsMany',
        'notChild', 'november', 'october', 'of', 'offer', 'offers', 'originalRoom',
        'pleaseEnterDates', 'pleaseSelectAllRooms', 'priceChange', 'priceDecreased',
        'priceIncreased', 'priceRecalculating', 'priceUpdated', 'remove', 'room',
        'roomChangedDueToAge', 'roomChangedTitle', 'roomUpdated', 'rooms', 'sat', 'search',
        'searching', 'selectAge', 'selectAgeForChildren', 'selectAgeForOneChild',
        'selectCheckIn', 'selectCheckOut', 'selectDates', 'selectDatesMessage',
        'selectMissingAges', 'selected', 'selectedSingular', 'september', 'sun', 'thu',
        'tue', 'wed', 'whereAreYouGoing', 'yearOld', 'yearsOld',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function read(string $relPath): string
    {
        $path = self::repoRoot() . '/' . $relPath;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /** @return list<string> keys emitted by the partial (the `key:` idents) */
    private static function emittedKeys(string $tpl): array
    {
        preg_match_all('~^\s+([a-zA-Z][a-zA-Z0-9]*)\s*:~m', $tpl, $m);
        return array_values(array_unique($m[1]));
    }

    public function testPartialEmitsEveryContractKey(): void
    {
        $emitted = self::emittedKeys(self::read(sprintf(self::PARTIAL, 'responsive')));
        $missing = array_values(array_diff(self::REQUIRED_KEYS, $emitted));
        self::assertSame(
            [],
            $missing,
            'travel_i18n.tpl dropped keys the booking JS relies on: ' . implode(', ', $missing),
        );
    }

    public function testThemeMirrorsAreIdentical(): void
    {
        self::assertSame(
            self::read(sprintf(self::PARTIAL, 'responsive')),
            self::read(sprintf(self::PARTIAL, 'nova_theme')),
            'travel_i18n.tpl theme mirror drifted',
        );
    }

    public function testPartialWritesBothGlobals(): void
    {
        $tpl = self::read(sprintf(self::PARTIAL, 'responsive'));
        self::assertStringContainsString('window.TravelTranslations', $tpl, 'must populate TravelTranslations');
        self::assertStringContainsString(
            'window.NovotonTranslations = window.TravelTranslations',
            $tpl,
            'must keep the NovotonTranslations back-compat alias',
        );
    }

    /**
     * No booking/search template may hand-build a client translations object
     * again — they must {include} the shared partial instead.
     */
    public function testNoTemplateHandBuildsTranslations(): void
    {
        $root = self::repoRoot();
        $templates = [
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/search.tpl',
            'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/views/novoton_booking/search.tpl',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
            'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/blocks/homepage_booking.tpl',
            'addon-novoton-holidays/design/themes/nova_theme/templates/addons/novoton_holidays/blocks/homepage_booking.tpl',
        ];
        $offenders = [];
        foreach ($templates as $rel) {
            $src = self::read($rel);
            // an inline object build: `window.<X>Translations = {` or an
            // incremental `window.<X>Translations.<key> = `
            if (preg_match('~window\.\w*Translations\s*=\s*\{~', $src)
                || preg_match('~window\.\w*Translations\.\w+\s*=\s*[\'"{]~', $src)) {
                $offenders[] = basename(dirname($rel)) . '/' . basename($rel);
            }
            self::assertStringContainsString(
                'components/travel_i18n.tpl',
                $src,
                "$rel must {include} the shared i18n partial",
            );
        }
        self::assertSame(
            [],
            $offenders,
            'templates still hand-build a *Translations object instead of including travel_i18n.tpl: '
                . implode(', ', $offenders),
        );
    }
}
