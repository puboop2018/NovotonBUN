<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Guards the booking-id contract on order-item extras:
 *
 *   - extra['travel_surrogate_id']  = the unified travel_bookings surrogate id.
 *     The ONLY key allowed in travel_bookings.view links / unified lookups.
 *   - provider-PK keys are provider-scoped (novoton_booking_id; sphinx items
 *     persist theirs under the legacy name travel_booking_id).
 *
 * History: extra['travel_booking_id'] used to mean sphinx's own PK on sphinx
 * items but the unified SURROGATE on novoton items — the id-space mix-up
 * behind the "View in Novoton opened a different customer's booking" bug.
 * This test fails if any template links travel_bookings.view with
 * extra.travel_booking_id again.
 */
class BookingIdContractTest extends TestCase
{
    private const ADDON_DESIGN_ROOTS = [
        'addon-travel-core/design',
        'addon-novoton-holidays/design',
        'addon-sphinx-holidays/design',
        'addon-fgo-invoicing/design',
    ];

    public function testNoTemplateLinksUnifiedViewWithProviderScopedBookingId(): void
    {
        $offenders = [];

        foreach ($this->designTemplates() as $path) {
            $source = (string) file_get_contents($path);
            // A travel_bookings.view link whose booking_id is fed from
            // extra.travel_booking_id (any variable prefix, e.g. $oi/$product).
            if (preg_match('~travel_bookings\.view\?booking_id=[^"\']*extra\.travel_booking_id~', $source) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "travel_bookings.view links must use extra.travel_surrogate_id (the unified id), "
            . "never extra.travel_booking_id (provider-scoped id-space):\n" . implode("\n", $offenders),
        );
    }

    /** @return list<string> */
    private function designTemplates(): array
    {
        $repoRoot = dirname(__DIR__, 7);
        $templates = [];
        foreach (self::ADDON_DESIGN_ROOTS as $designRoot) {
            $dir = $repoRoot . '/' . $designRoot;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'tpl') {
                    $templates[] = $file->getPathname();
                }
            }
        }
        self::assertNotEmpty($templates, 'design template scan found nothing — repo layout changed?');
        return $templates;
    }
}
