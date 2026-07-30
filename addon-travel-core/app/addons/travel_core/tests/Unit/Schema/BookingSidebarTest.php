<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the shared booking-form sidebar + conditions modal.
 *
 * Both providers' booking pages are now ONE 2-column layout: the summary
 * sidebar (components/booking_sidebar.tpl) on the left, the guest form on the
 * right. Before this, each provider carried its own summary/price/terms
 * markup, so every design change had to be made twice (x2 themes).
 *
 * The pins below are the ones a future edit could break silently: the brief's
 * ordering rules, the CS-Cart thumbnail integration, and — most importantly —
 * the DOM contract the booking JS depends on (see the constraint block).
 */
final class BookingSidebarTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    private static function tpl(string $theme = 'responsive'): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/design/themes/' . $theme
            . '/templates/addons/travel_core/components/booking_sidebar.tpl',
        );
    }

    private static function css(): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/design/themes/responsive/css/addons/travel_core/booking-pages.css',
        );
    }

    public function testBothThemesShipTheComponentByteIdentical(): void
    {
        self::assertSame(self::tpl('responsive'), self::tpl('nova_theme'));
        self::assertNotSame('', self::tpl());
    }

    /**
     * Brief: stars ABOVE the name, availability badge right-aligned on the
     * SAME row as the stars. (hotel_header.tpl puts stars after the name
     * inside the h1, which is why the booking form no longer includes it.)
     */
    public function testStarsSitAboveTheNameWithTheBadgeOnTheirRow(): void
    {
        $tpl = self::tpl();

        $rowPos = strpos($tpl, 'travel-bsidebar-toprow');
        $starsPos = strpos($tpl, 'travel-hotel-stars');
        $badgePos = strpos($tpl, 'id="availability-badge"');
        $namePos = strpos($tpl, 'travel-bsidebar-name');

        self::assertIsInt($rowPos);
        self::assertIsInt($starsPos);
        self::assertIsInt($badgePos);
        self::assertIsInt($namePos);
        self::assertLessThan($starsPos, $rowPos, 'stars render inside the top row');
        self::assertLessThan($badgePos, $starsPos, 'badge follows the stars on that row');
        self::assertLessThan($namePos, $badgePos, 'the whole row precedes the name');

        $css = self::css();
        $start = strpos($css, '.travel-booking-page .travel-bsidebar-toprow {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('justify-content: space-between', $rule);
    }

    /**
     * The hero still honours the store's Settings ▸ Thumbnails, per the brief —
     * but only its WIDTH. The stored thumbnail HEIGHT is what produced the
     * tall, squeezed crop the field reported: CS-Cart generated a portrait
     * thumbnail and `height: auto` let it through untouched. Width from the
     * setting, height derived from the card's landscape ratio, and the CSS
     * container enforces the shape.
     */
    public function testHeroUsesTheStoresThumbnailWidthAtALandscapeRatio(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('{include file="common/image.tpl"', $tpl);
        self::assertStringContainsString('$settings.Thumbnails.product_lists_thumbnail_width', $tpl);
        self::assertStringContainsString('image_height=($tbs_hero_w * 2 / 3)|round', $tpl);
        self::assertStringNotContainsString('product_lists_thumbnail_height', $tpl);

        // The ratio is enforced by the container, and the image is cropped to
        // it rather than squeezed.
        $css = self::css();
        $start = strpos($css, '.travel-booking-page .travel-bsidebar-hero {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('aspect-ratio: 3 / 2', $rule);
        self::assertStringContainsString('overflow: hidden', $rule);

        $imgStart = strpos($css, '.travel-booking-page .travel-bsidebar-hero img {');
        self::assertNotFalse($imgStart);
        $imgRule = substr($css, $imgStart, (int) strpos($css, '}', $imgStart) - $imgStart);
        self::assertStringContainsString('object-fit: cover', $imgRule);
        self::assertStringNotContainsString('height: auto', $imgRule);
    }

    /**
     * The booking page must fill the same theme container as the header,
     * breadcrumbs and footer. A page-level max-width here is what made the
     * hotel card float narrower than the logo with uneven margins.
     */
    public function testTheBookingPageDoesNotCapItsOwnWidth(): void
    {
        $css = self::css();

        $start = strpos($css, '.travel-booking-page.novoton-reservation-form,');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringNotContainsString('max-width', $rule);

        $novoton = (string) file_get_contents(
            self::repoRoot() . '/addon-novoton-holidays/design/themes/responsive/css/addons/novoton_holidays/styles.css',
        );
        $legacy = strpos($novoton, '.novoton-reservation-form {');
        self::assertNotFalse($legacy);
        $legacyRule = substr($novoton, $legacy, (int) strpos($novoton, '}', $legacy) - $legacy);
        self::assertStringNotContainsString('max-width', $legacyRule);
    }

    /**
     * Amenities are chips a guest can skim, not a run-on line of text.
     */
    public function testAmenitiesRenderAsCheckedChips(): void
    {
        $css = self::css();

        $start = strpos($css, '.travel-booking-page .travel-bsidebar-features {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringContainsString('flex-wrap: wrap', $rule);

        $markStart = strpos($css, '.travel-booking-page .travel-bsidebar-features li::before {');
        self::assertNotFalse($markStart, 'each amenity carries a check mark');
        $markRule = substr($css, $markStart, (int) strpos($css, '}', $markStart) - $markStart);
        self::assertStringContainsString('\2713', $markRule);
        self::assertStringContainsString('--nvt-success-strong', $markRule);
    }

    public function testSummaryCardFollowsTheBriefsOrderAndCopy(): void
    {
        $tpl = self::tpl();

        // Header reuses the existing key rather than inventing a new one.
        self::assertStringContainsString('{__("travel_core.your_booking_details")}', $tpl);

        // Two-column dates, then a rule, then the "you selected" line.
        $datesPos = strpos($tpl, 'travel-bsidebar-dates');
        $rulePos = strpos($tpl, 'travel-bsidebar-rule');
        $selectedPos = strpos($tpl, 'travel_core.you_selected');
        self::assertIsInt($datesPos);
        self::assertIsInt($rulePos);
        self::assertIsInt($selectedPos);
        self::assertLessThan($rulePos, $datesPos);
        self::assertLessThan($selectedPos, $rulePos);

        // Counts use CS-Cart plural forms, joined by a translatable sentence
        // so Romanian keeps its own word order and connector.
        foreach (['n_nights', 'n_rooms', 'n_adults', 'n_children'] as $key) {
            self::assertStringContainsString('travel_core.' . $key, $tpl);
        }
        self::assertStringContainsString('travel_core.selected_line_with_children', $tpl);
        self::assertStringContainsString('travel_core.selected_line', $tpl);

        // Room lines are "Nx <name>"; board and the change link follow.
        self::assertStringContainsString('{$tbs_room.qty}x', $tpl);
        self::assertStringContainsString('travel_core.change_selection', $tpl);
    }

    /** "Change your selection" goes back to the PDP carrying the search. */
    public function testChangeSelectionTargetsTheProductPageWithTheSearch(): void
    {
        $factory = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/src/ViewModels/BookingSidebarFactory.php',
        );
        self::assertStringContainsString("'products.view?' . http_build_query(\$query)", $factory);
        // No product => no link, rather than a dead one.
        self::assertStringContainsString('if ($productId <= 0) {', $factory);
    }

    /**
     * The booking JS addresses these nodes directly (booking-form.js writes
     * refreshed prices into .price-total / #novoton-total-price and toggles the
     * price-state nodes). Losing them silently breaks re-pricing.
     */
    public function testPriceBoxKeepsTheDomContractTheBookingJsDependsOn(): void
    {
        $tpl = self::tpl();

        foreach ([
            'booking-price-box',
            'price-total',
            'id="novoton-total-price"',
            'id="price-error-message"',
            'id="price-unverified-badge"',
            'id="refresh-price-link"',
            'id="availability-badge"',
        ] as $hook) {
            self::assertStringContainsString($hook, $tpl, "JS hook {$hook} must survive the redesign");
        }
    }

    public function testCancellationCardIsHiddenUntilItHasSomethingToSay(): void
    {
        $tpl = self::tpl();

        self::assertStringContainsString('id="travel-cancel-card"', $tpl);
        self::assertStringContainsString('id="travel-cancel-lines"', $tpl);
        self::assertStringContainsString('travel_core.cancel_cost_title', $tpl);
        self::assertStringContainsString('travel_core.cancel_you_will_pay', $tpl);
        // Empty policy => hidden card (novoton fills it from the price
        // re-verification that already runs on load). A free-until date on its
        // own is reason enough to show the card.
        self::assertStringContainsString(
            '{if !$tbs.cancel_lines && !$tbs.cancel_full_amount && !$tbs.cancel_free_until} travel-is-hidden{/if}',
            $tpl,
        );
    }

    /**
     * "Free cancellation until <date>" is the line guests look for first, and
     * the search-results card already shows it in green — the booking form
     * must make the same promise the same way.
     */
    public function testFreeCancellationIsGreenAndMatchesTheSearchCard(): void
    {
        $tpl = self::tpl();
        self::assertStringContainsString('id="travel-cancel-free"', $tpl);
        self::assertStringContainsString('id="travel-cancel-free-date"', $tpl);
        self::assertStringContainsString('travel_core.free_cancellation_until', $tpl);

        $css = self::css();
        $start = strpos($css, '.travel-booking-page .travel-bsidebar-freecancel {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        // Same token the search card uses (--nvt-success is too light for AA).
        self::assertStringContainsString('--nvt-success-strong', $rule);

        // Sphinx knows the date server-side; novoton only learns it from the
        // price re-verification, so its JS fills the same two nodes.
        $sphinx = (string) file_get_contents(
            self::repoRoot()
            . '/addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/booking_form.php',
        );
        self::assertStringContainsString('cancelFreeUntil:', $sphinx);
        self::assertStringContainsString('freeCancellationUntil(', $sphinx);

        $js = (string) file_get_contents(
            self::repoRoot() . '/addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js',
        );
        self::assertStringContainsString("getElementById('travel-cancel-free-date')", $js);
        self::assertStringContainsString('data.free_cancellation_until', $js);

        $recalc = (string) file_get_contents(
            self::repoRoot()
            . '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/ajax_recalculate_price.php',
        );
        self::assertStringContainsString("'free_cancellation_until' => \$free_cancellation_until", $recalc);
    }

    /**
     * Every customer-facing date follows Settings ▸ Appearance ▸ date format.
     * Field report: "Check-in 07.09.2026" beside "Anulare gratuită până la
     * 08/28/2026" — one card, two formats, because the sidebar hardcoded
     * dd.mm.yyyy while the terms line followed the store setting.
     */
    public function testDatesFollowTheStoreFormatEverywhere(): void
    {
        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/frontend/novoton_booking/booking_form.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/frontend/sphinx_booking/booking_form.php',
        ] as $controller) {
            $src = (string) file_get_contents(self::repoRoot() . $controller);
            self::assertStringContainsString('DateHelper::formatStoreDate(', $src, $controller);
            self::assertStringNotContainsString("'%d.%m.%Y'", $src, $controller . ': hardcoded date format');
        }

        // Both providers' terms formatters share the same one formatter.
        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Services/TermsFormatter.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/TermsFormatter.php',
        ] as $formatter) {
            $src = (string) file_get_contents(self::repoRoot() . $formatter);
            self::assertStringContainsString('DateHelper::formatStoreDate(', $src, $formatter);
        }

        // The cart/checkout card too — it used to print "%a %d %b %Y".
        foreach (['responsive', 'nova_theme'] as $theme) {
            $card = (string) file_get_contents(
                self::repoRoot() . '/addon-travel-core/design/themes/' . $theme
                . '/templates/addons/travel_core/components/cart_booking_details.tpl',
            );
            self::assertStringContainsString('date_format:$settings.Appearance.date_format', $card, $theme);
            self::assertStringNotContainsString('date_format:"%a %d %b %Y"', $card, $theme);
        }
    }

    /**
     * Romanian amenity names: sphinx already resolved them through the shared
     * feature map; novoton read its own facility_name_ro column, which its
     * sync fills with a verbatim copy of the ENGLISH name — so a Romanian
     * storefront listed "Air conditioning/Heating". Both go through the shared
     * resolver now.
     */
    public function testAmenityNamesComeFromTheSharedFeatureMap(): void
    {
        $repo = (string) file_get_contents(
            self::repoRoot()
            . '/addon-novoton-holidays/app/addons/novoton_holidays/src/Repository/FacilityRepository.php',
        );
        self::assertStringContainsString('FacilityLabelResolver::labels(', $repo);

        $resolver = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/src/Services/FacilityLabelResolver.php',
        );
        self::assertStringContainsString('FeatureMapper::resolveFacility(', $resolver);
        // Unmapped facilities keep the provider's own label rather than being
        // silently dropped, which is what an empty RO column used to cause.
        self::assertStringContainsString("\$facility['name'] ?? ''", $resolver);

        $sphinx = (string) file_get_contents(
            self::repoRoot()
            . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Services/SphinxFeatureAssigner.php',
        );
        self::assertStringContainsString('FacilityLabelResolver::column($lang)', $sphinx);
    }

    /** Only a 100% penalty gets the headline row. */
    public function testFullChargeHeadlineOnlyForATotalLoss(): void
    {
        $factory = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/src/ViewModels/BookingSidebarFactory.php',
        );
        self::assertStringContainsString("str_contains(\$line, '100%')", $factory);
    }

    /**
     * "What are my booking conditions?" — the link under Add-to-Cart and its
     * modal, accumulating ONE SECTION PER ROOM for multi-room bookings.
     */
    public function testBookingConditionsModalIsSharedAndPerRoom(): void
    {
        $modal = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/components/booking_conditions_modal.tpl',
        );
        $novaModal = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/nova_theme/templates/addons/travel_core/components/booking_conditions_modal.tpl',
        );
        self::assertSame($modal, $novaModal);

        self::assertStringContainsString('travel_core.booking_conditions_link', $modal);
        self::assertStringContainsString('data-travel-conditions-open', $modal);
        self::assertStringContainsString('id="travel-conditions-rooms"', $modal);
        self::assertStringContainsString('travel_core.payment_terms', $modal);
        self::assertStringContainsString('travel_core.cancellation_policy', $modal);

        // Behaviour lives in a real JS file (no inline script), and keys each
        // section by room so a multi-room booking accumulates rather than
        // overwrites.
        $js = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/js/addons/travel_core/booking-conditions.js',
        );
        self::assertStringContainsString('function setRoomSection(roomNum, data)', $js);
        self::assertStringContainsString("host.querySelector('[data-room=\"' + key + '\"]')", $js);
        self::assertStringContainsString('window.TravelConditions', $js);

        // Loaded site-wide with the other travel_core booking scripts.
        $scripts = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/themes/responsive/templates/addons/travel_core/hooks/index/scripts.post.tpl',
        );
        self::assertStringContainsString('js/addons/travel_core/booking-conditions.js', $scripts);

        // Both providers render the link+modal.
        foreach ([
            '/addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
            '/addon-sphinx-holidays/design/themes/responsive/templates/addons/sphinx_holidays/views/sphinx_booking/booking_form.tpl',
        ] as $path) {
            $form = (string) file_get_contents(self::repoRoot() . $path);
            self::assertStringContainsString(
                '{include file="addons/travel_core/components/booking_conditions_modal.tpl"}',
                $form,
                "missing conditions modal in {$path}",
            );
        }
    }

    /**
     * User ruling: "we get rid of left bordered/highlighted line on the form".
     * The child card keeps only its warm background.
     */
    public function testGuestCardsHaveNoLeftAccentBar(): void
    {
        $css = self::css();

        $start = strpos($css, '.travel-booking-page .guest-entry {');
        self::assertNotFalse($start);
        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);
        self::assertStringNotContainsString('border-left', $rule);

        $childStart = strpos($css, '.travel-booking-page .guest-entry-child {');
        self::assertNotFalse($childStart);
        $childRule = substr($css, $childStart, (int) strpos($css, '}', $childStart) - $childStart);
        self::assertStringNotContainsString('border-left', $childRule);
    }

    /** Novoton no longer prints a bed line ("Regular Bed") on adult cards. */
    public function testNovotonDropsTheBedSublabel(): void
    {
        $form = (string) file_get_contents(
            self::repoRoot()
            . '/addon-novoton-holidays/design/themes/responsive/templates/addons/novoton_holidays/views/novoton_booking/booking_form.tpl',
        );
        self::assertStringNotContainsString('gb_adult_sublabel', $form);
        self::assertStringNotContainsString('novoton_holidays.regular_bed', $form);
    }

    /** Every new frontend key must reach installed stores AND fresh installs. */
    public function testNewSidebarKeysAreSeededInAllThreePlaces(): void
    {
        $langKeys = (string) file_get_contents(
            self::repoRoot() . '/addon-travel-core/app/addons/travel_core/lang_keys.php',
        );
        $keys = [
            'travel_core.available',
            'travel_core.package',
            'travel_core.you_selected',
            'travel_core.n_nights',
            'travel_core.n_rooms',
            'travel_core.n_adults',
            'travel_core.n_children',
            'travel_core.selected_line',
            'travel_core.selected_line_with_children',
            'travel_core.change_selection',
            'travel_core.cancel_cost_title',
            'travel_core.cancel_you_will_pay',
            'travel_core.booking_conditions_link',
        ];
        foreach ($keys as $key) {
            self::assertStringContainsString("'{$key}'", $langKeys, "{$key} missing from lang_keys.php");
            foreach (['en', 'ro'] as $lang) {
                $po = (string) file_get_contents(
                    self::repoRoot() . '/addon-travel-core/var/langs/' . $lang . '/addons/travel_core.po',
                );
                self::assertStringContainsString(
                    'msgctxt "Languages::' . $key . '"',
                    $po,
                    "{$key} missing from {$lang}.po (fresh installs never get it)",
                );
            }
        }
    }
}
