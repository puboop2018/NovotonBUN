<?php
declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

#[CoversClass(GuestDataNormalizer::class)]
class GuestDataNormalizerTest extends TestCase
{
    private GuestDataNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GuestDataNormalizer();
    }

    // ── normalize() ─────────────────────────────────────────────────────────

    public function testNormalizeEmptyArrayReturnsEmpty(): void
    {
        $this->assertSame([], $this->normalizer->normalize([]));
    }

    public function testNormalizeEmptyStringReturnsEmpty(): void
    {
        $this->assertSame([], $this->normalizer->normalize(''));
    }

    public function testNormalizeInvalidJsonReturnsEmpty(): void
    {
        $this->assertSame([], $this->normalizer->normalize('{not valid'));
    }

    public function testNormalizeKeyedPassthroughEnsuresFields(): void
    {
        $raw = [
            'room1_adult_1' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ];

        $result = $this->normalizer->normalize($raw);

        $this->assertArrayHasKey('room1_adult_1', $result);
        $entry = $result['room1_adult_1'];
        $this->assertSame('Ada', $entry['first_name']);
        $this->assertSame('Lovelace', $entry['last_name']);
        // Defaults filled in.
        $this->assertSame('adult', $entry['type']);
        $this->assertSame(0, $entry['age']);
        $this->assertSame(1, $entry['room']);
        $this->assertSame(0, $entry['is_holder']);
        // Name fields derived.
        $this->assertSame('Ada Lovelace', $entry['api_name']);
        $this->assertSame('Lovelace, Ada', $entry['name']);
    }

    public function testNormalizeIndexedArrayConvertsToKeyed(): void
    {
        $raw = [
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'type' => 'adult', 'room' => 1],
            ['first_name' => 'Alan', 'last_name' => 'Turing', 'type' => 'adult', 'room' => 1],
        ];

        $result = $this->normalizer->normalize($raw);

        $this->assertArrayHasKey('room1_adult_1', $result);
        $this->assertArrayHasKey('room1_adult_2', $result);
        $this->assertSame('Ada', $result['room1_adult_1']['first_name']);
        $this->assertSame('Alan', $result['room1_adult_2']['first_name']);
    }

    public function testNormalizeUnknownStructureAppliesFieldDefaults(): void
    {
        // Arbitrary string keys that don't match the room pattern and
        // aren't sequential int keys either.
        $raw = [
            'guest_a' => ['first_name' => 'Grace'],
        ];

        $result = $this->normalizer->normalize($raw);

        $this->assertArrayHasKey('guest_a', $result);
        $this->assertSame('Grace', $result['guest_a']['first_name']);
        $this->assertSame('adult', $result['guest_a']['type']);
        $this->assertSame(0, $result['guest_a']['age']);
    }

    // ── decode() ────────────────────────────────────────────────────────────

    public function testDecodeValidJsonString(): void
    {
        $this->assertSame(
            ['room1_adult_1' => ['first_name' => 'Ada']],
            $this->normalizer->decode('{"room1_adult_1":{"first_name":"Ada"}}'),
        );
    }

    public function testDecodeInvalidJsonReturnsEmpty(): void
    {
        $this->assertSame([], $this->normalizer->decode('{not valid'));
    }

    public function testDecodeArrayPassthrough(): void
    {
        $raw = ['x' => 1];
        $this->assertSame($raw, $this->normalizer->decode($raw));
    }

    public function testDecodeNonArrayJsonReturnsEmpty(): void
    {
        // JSON scalar decodes to a string, not an array — must return [].
        $this->assertSame([], $this->normalizer->decode('"just a string"'));
    }

    // ── toJson() ────────────────────────────────────────────────────────────

    public function testToJsonEmptyReturnsEmptyObjectLiteral(): void
    {
        $this->assertSame('{}', $this->normalizer->toJson([]));
        $this->assertSame('{}', $this->normalizer->toJson(''));
    }

    public function testToJsonKeyedRoundTrip(): void
    {
        $raw = [
            'room1_adult_1' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ];

        $json = $this->normalizer->toJson($raw);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('room1_adult_1', $decoded);
        $this->assertSame('Ada', $decoded['room1_adult_1']['first_name']);
        // Round-trip: normalize(toJson($x)) equals normalize($x).
        $this->assertSame(
            $this->normalizer->normalize($raw),
            $this->normalizer->normalize($json),
        );
    }

    public function testToJsonNormalizesArrayBeforeEncoding(): void
    {
        // Indexed input gets converted to keyed format before encoding.
        $raw = [
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'type' => 'adult', 'room' => 1],
        ];

        $json = $this->normalizer->toJson($raw);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('room1_adult_1', $decoded);
        $this->assertArrayNotHasKey(0, $decoded);
    }

    // ── isKeyedFormat() ─────────────────────────────────────────────────────

    public function testIsKeyedFormatMatchesRoomPattern(): void
    {
        $this->assertTrue($this->normalizer->isKeyedFormat(['room1_adult_1' => []]));
        $this->assertTrue($this->normalizer->isKeyedFormat(['room2_child_3' => []]));
        $this->assertTrue($this->normalizer->isKeyedFormat([
            'noise' => [],
            'room1_adult_1' => [],
        ]));
    }

    public function testIsKeyedFormatRejectsIntKeysAndEmpty(): void
    {
        $this->assertFalse($this->normalizer->isKeyedFormat([]));
        $this->assertFalse($this->normalizer->isKeyedFormat([0 => ['name' => 'x']]));
    }

    public function testIsKeyedFormatRejectsStringKeysWithoutRoomPattern(): void
    {
        $this->assertFalse($this->normalizer->isKeyedFormat(['foo' => [], 'bar' => []]));
    }

    // ── isArrayFormat() ─────────────────────────────────────────────────────

    public function testIsArrayFormatAcceptsSequentialIntKeysWithGuestFields(): void
    {
        $this->assertTrue($this->normalizer->isArrayFormat([
            ['name' => 'Ada'],
            ['name' => 'Alan'],
        ]));
        $this->assertTrue($this->normalizer->isArrayFormat([
            ['first_name' => 'Ada'],
        ]));
        $this->assertTrue($this->normalizer->isArrayFormat([
            ['type' => 'adult'],
        ]));
    }

    public function testIsArrayFormatRejectsStringKeys(): void
    {
        $this->assertFalse($this->normalizer->isArrayFormat([
            'room1_adult_1' => ['name' => 'Ada'],
        ]));
    }

    public function testIsArrayFormatRejectsFirstElementWithoutGuestFields(): void
    {
        $this->assertFalse($this->normalizer->isArrayFormat([
            ['unrelated' => 1],
        ]));
        $this->assertFalse($this->normalizer->isArrayFormat([]));
    }

    // ── array-to-keyed counter logic ────────────────────────────────────────

    public function testArrayToKeyedMultiRoomCounters(): void
    {
        $raw = [
            ['first_name' => 'A1', 'type' => 'adult', 'room' => 1],
            ['first_name' => 'A2', 'type' => 'adult', 'room' => 1],
            ['first_name' => 'C1', 'type' => 'child', 'room' => 1, 'age' => 7],
            ['first_name' => 'A3', 'type' => 'adult', 'room' => 2],
        ];

        $result = $this->normalizer->normalize($raw);

        $this->assertSame(['room1_adult_1', 'room1_adult_2', 'room1_child_1', 'room2_adult_1'], array_keys($result));
        $this->assertSame('A1', $result['room1_adult_1']['first_name']);
        $this->assertSame('C1', $result['room1_child_1']['first_name']);
        $this->assertSame(7, $result['room1_child_1']['age']);
        $this->assertSame('A3', $result['room2_adult_1']['first_name']);
    }

    public function testArrayToKeyedUnknownTypeBucketsAsAdult(): void
    {
        $raw = [
            ['first_name' => 'Unknown', 'type' => 'ghost', 'room' => 1],
        ];

        $result = $this->normalizer->normalize($raw);

        // The bucket key coerces unknown types to 'adult'...
        $this->assertArrayHasKey('room1_adult_1', $result);
        // ...but the field-copy loop after the coercion overwrites the
        // coerced value with the raw input. Pinning current behaviour —
        // change this test intentionally if the SUT is ever fixed.
        $this->assertSame('ghost', $result['room1_adult_1']['type']);
    }

    public function testArrayToKeyedMissingRoomDefaultsToOne(): void
    {
        $raw = [
            ['first_name' => 'NoRoom', 'type' => 'adult'],
            ['first_name' => 'ZeroRoom', 'type' => 'adult', 'room' => 0],
        ];

        $result = $this->normalizer->normalize($raw);

        $this->assertArrayHasKey('room1_adult_1', $result);
        $this->assertArrayHasKey('room1_adult_2', $result);
    }

    public function testArrayToKeyedSkipsNonArrayEntries(): void
    {
        $raw = [
            ['first_name' => 'Ada', 'type' => 'adult', 'room' => 1],
            'not-an-array',
            ['first_name' => 'Alan', 'type' => 'adult', 'room' => 1],
        ];

        // isArrayFormat requires first element to look like a guest, which
        // this satisfies; the string entry is skipped by convertArrayToKeyed.
        $result = $this->normalizer->normalize($raw);

        $this->assertCount(2, $result);
        $this->assertSame('Ada', $result['room1_adult_1']['first_name']);
        $this->assertSame('Alan', $result['room1_adult_2']['first_name']);
    }

    // ── name derivation (exercised via normalize on keyed input) ────────────

    public function testDeriveApiNameFromFirstAndLast(): void
    {
        $result = $this->normalizer->normalize([
            'room1_adult_1' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ]);
        $this->assertSame('Ada Lovelace', $result['room1_adult_1']['api_name']);
    }

    public function testDeriveDisplayNameAsLastCommaFirst(): void
    {
        $result = $this->normalizer->normalize([
            'room1_adult_1' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ]);
        $this->assertSame('Lovelace, Ada', $result['room1_adult_1']['name']);
    }

    public function testDeriveDisplayNameFromFirstOnly(): void
    {
        $result = $this->normalizer->normalize([
            'room1_adult_1' => ['first_name' => 'Ada'],
        ]);
        $this->assertSame('Ada', $result['room1_adult_1']['name']);
        $this->assertSame('Ada', $result['room1_adult_1']['api_name']);
    }

    public function testDeriveApiNameFallsBackToName(): void
    {
        $result = $this->normalizer->normalize([
            'room1_adult_1' => ['name' => 'Ada Lovelace'],
        ]);
        $this->assertSame('Ada Lovelace', $result['room1_adult_1']['api_name']);
        $this->assertSame('Ada Lovelace', $result['room1_adult_1']['name']);
    }

    public function testDeriveNameFallsBackToApiName(): void
    {
        $result = $this->normalizer->normalize([
            'room1_adult_1' => ['api_name' => 'Ada Lovelace'],
        ]);
        $this->assertSame('Ada Lovelace', $result['room1_adult_1']['name']);
        $this->assertSame('Ada Lovelace', $result['room1_adult_1']['api_name']);
    }
}
