<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * "Edit Guest Details" must render the SAME page as the booking form.
 *
 * Field report: clicking the edit link on a cart line landed on an isolated
 * bare form — no hotel, no dates, no price, and a "What are my booking
 * conditions?" link that opened nothing.
 *
 * Nothing was broken in the templates. edit_booking.tpl is a one-line
 * `{include}` of booking_form.tpl in BOTH providers, so the two modes share
 * every line of markup — but the markup is driven by view variables, and the
 * edit controllers assigned fewer of them. `components/booking_sidebar.tpl` is
 * wrapped in `{if $tbs}`, so a missing `travel_booking_sidebar` renders the
 * whole left column as nothing, silently. Same for the conditions modal, which
 * reads the same variable.
 *
 * The fix is structural: one builder per provider, called by both modes. These
 * pins exist so a future edit cannot quietly assign the sidebar in one mode
 * only — the failure mode is invisible in review and invisible in a smoke test
 * that only opens the create page.
 */
final class EditBookingPageParityTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function read(string $relative): string
    {
        $path = self::repoRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, array{string, string, string}> */
    public static function providers(): array
    {
        return [
            'novoton' => [
                'addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking',
                'addon-novoton-holidays/app/addons/novoton_holidays/src/ViewModels/NovotonBookingSidebarBuilder.php',
                'NovotonBookingSidebarBuilder::sidebar(',
            ],
            'sphinx' => [
                'addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking',
                'addon-sphinx-holidays/app/addons/sphinx_holidays/src/ViewModels/SphinxBookingSidebarBuilder.php',
                'SphinxBookingSidebarBuilder::build(',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testBothModesBuildTheSidebarThroughOneBuilder(
        string $controllerDir,
        string $builderPath,
        string $call,
    ): void {
        foreach (['booking_form.php', 'edit_booking.php'] as $mode) {
            $src = self::read($controllerDir . '/' . $mode);

            self::assertStringContainsString(
                $call,
                $src,
                "{$mode} must build its sidebar through the shared builder",
            );
            self::assertStringContainsString(
                "assign('travel_booking_sidebar'",
                $src,
                "{$mode} without this assign renders an empty left column — silently, because"
                . ' booking_sidebar.tpl is wrapped in {if $tbs}',
            );
        }

        self::assertNotSame('', self::read($builderPath));
    }

    /**
     * The edit page shows the BOOKED figures. Re-quoting while a guest fixes a
     * misspelled name would move the price under them — and for sphinx it is
     * also pinned by SphinxEditBookingTest.
     */
    public function testEditModeNeverReQuotes(): void
    {
        $sphinx = self::read(
            'addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/edit_booking.php',
        );
        self::assertStringNotContainsString('verifyHotelOffer', $sphinx);

        $novoton = self::read(
            'addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/edit_booking.php',
        );
        self::assertStringNotContainsString('ajax_recalculate', $novoton);
    }

    /**
     * Novoton's edit mode was also missing the hotel header and the three
     * booking_data keys the form's inline script reads. All of them are
     * `|default:`-guarded in the template, so their absence never errored — it
     * just quietly gave every hotel 4 adults / 2 children / capacity 2 and an
     * empty room list.
     */
    public function testNovotonEditAssignsEverythingTheTemplateReads(): void
    {
        $edit = self::read(
            'addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/edit_booking.php',
        );

        foreach ([
            "assign('travel_hotel_header'",
            "assign('hotel_location_line'",
            "assign('hotel_map_url'",
            "'age_categories' =>",
            "'current_room_limits' =>",
            "'rooms_data_json' =>",
        ] as $needle) {
            self::assertStringContainsString($needle, $edit, "edit_booking must assign {$needle}");
        }

        // Room limits come from the same resolver the create mode uses.
        self::assertStringContainsString('RoomLimitsResolver::resolve(', $edit);
        $create = self::read(
            'addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/booking_form.php',
        );
        self::assertStringContainsString('RoomLimitsResolver::resolve(', $create);
    }

    /** Both providers' edit templates are still a bare include of the form. */
    public function testEditTemplatesRemainOneLineIncludes(): void
    {
        foreach ([
            'addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking'
                => 'addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
            'addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking'
                => 'addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl',
        ] as $dir => $include) {
            $tpl = self::read($dir . '/edit_booking.tpl');
            self::assertStringContainsString($include, $tpl);
        }
    }
}
