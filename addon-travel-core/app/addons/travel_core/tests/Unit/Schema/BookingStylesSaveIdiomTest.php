<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the addon-settings write idiom in the booking-styles save controller.
 *
 * Settings::updateValue() MUST be called with auto_create=true (4th argument):
 * without it CS-Cart silently discards the write whenever the setting row is
 * absent from ?:settings_objects — exactly the state of installs where the
 * addon was installed before the appearance section existed (CS-Cart syncs
 * addon.xml settings only on install/upgrade, and this repo deploys by
 * symlink). Shipped once: colors "saved", the redirect re-read empty values
 * and the form fell back to the defaults (#febb02).
 *
 * PHPStan cannot catch this — Tygh\Settings is an unknown core class
 * (globally ignored in phpstan.neon), so the argument list is never checked.
 */
final class BookingStylesSaveIdiomTest extends TestCase
{
    public function testUpdateValueUsesAutoCreate(): void
    {
        $controller = dirname(__DIR__, 3) . '/controllers/backend/travel_booking_styles.php';
        self::assertFileExists($controller);

        $src = (string) file_get_contents($controller);

        self::assertStringContainsString(
            '->updateValue($settingName, $value, \'travel_core\', true)',
            $src,
            'The save handler must pass auto_create=true — the 3-arg form silently drops writes for setting rows that were never seeded.',
        );
        self::assertDoesNotMatchRegularExpression(
            "/->updateValue\\([^)]*'travel_core'\\s*\\)/",
            $src,
            'Found a 3-argument updateValue call — every settings write must use the auto_create idiom.',
        );
    }
}
