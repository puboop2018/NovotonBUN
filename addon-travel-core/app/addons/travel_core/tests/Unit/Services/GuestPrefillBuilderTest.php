<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\GuestPrefillBuilder;

/**
 * Behavior tests for the shared edit-mode prefill builder both providers'
 * edit_booking controllers use: stored guests_data in any shape becomes the
 * guest_prefill map keyed like the shared guest-card input names.
 */
final class GuestPrefillBuilderTest extends TestCase
{
    public function testKeyedGuestsDataProjectsDirectly(): void
    {
        $prefill = GuestPrefillBuilder::build([
            'room1_adult_1' => ['last_name' => 'Popescu', 'first_name' => 'Ion', 'dob' => '27/05/1990'],
            'room1_child_1' => ['last_name' => 'Popescu', 'first_name' => 'Ana'],
        ]);

        self::assertSame(
            ['last_name' => 'Popescu', 'first_name' => 'Ion', 'dob' => '27/05/1990'],
            $prefill['room1_adult_1'],
        );
        self::assertSame('', $prefill['room1_child_1']['dob'], 'missing dob resolves to empty string');
    }

    public function testIndexedRowsGetRoomTypeCounterKeys(): void
    {
        // The sphinx shape: a row list with type/room fields (no keys).
        $prefill = GuestPrefillBuilder::build([
            ['last_name' => 'Ionescu', 'first_name' => 'Maria', 'type' => 'adult', 'room' => 1],
            ['last_name' => 'Ionescu', 'first_name' => 'Dan', 'type' => 'adult', 'room' => 1],
            ['last_name' => 'Ionescu', 'first_name' => 'Vlad', 'type' => 'child', 'room' => 2, 'age' => 8],
        ]);

        self::assertArrayHasKey('room1_adult_1', $prefill);
        self::assertArrayHasKey('room1_adult_2', $prefill);
        self::assertArrayHasKey('room2_child_1', $prefill);
        self::assertSame('Dan', $prefill['room1_adult_2']['first_name']);
    }

    public function testJsonStringInputDecodesLikeTheStoredColumn(): void
    {
        $json = (string) json_encode([
            'room1_adult_1' => ['last_name' => 'Georgescu', 'first_name' => 'Elena'],
        ]);

        $prefill = GuestPrefillBuilder::build($json);

        self::assertSame('Georgescu', $prefill['room1_adult_1']['last_name']);
    }

    public function testBirthdayConvertsToDobMaskFormatWhenDobIsMissing(): void
    {
        $prefill = GuestPrefillBuilder::build([
            'room1_child_1' => ['last_name' => 'Popa', 'first_name' => 'Ioana', 'birthday' => '2020-05-27'],
            'room1_child_2' => ['last_name' => 'Popa', 'first_name' => 'Radu', 'dob' => '01/02/2019', 'birthday' => '2019-02-01'],
        ]);

        self::assertSame('27/05/2020', $prefill['room1_child_1']['dob'], 'YYYY-MM-DD birthday becomes DD/MM/YYYY');
        self::assertSame('01/02/2019', $prefill['room1_child_2']['dob'], 'an explicit dob wins over birthday');
    }

    public function testGarbageInputYieldsAnEmptyMap(): void
    {
        self::assertSame([], GuestPrefillBuilder::build('not json'));
        self::assertSame([], GuestPrefillBuilder::build(''));
        self::assertSame([], GuestPrefillBuilder::build([]));
    }
}
