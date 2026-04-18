# DTO conventions

One-page spec for writing data-transfer objects in this monorepo. Applies to everything under `addon-travel-core/app/addons/travel_core/src/Dto/`, plus any addon-specific DTOs that may land in `addon-*/src/Dto/` in future.

Lives here rather than in a wiki so it's versioned with the code that enforces it. Reviewers should cite this file when a new DTO PR doesn't match the pattern.

---

## TL;DR — the rules

1. **`final readonly class` + constructor promotion.** No `extends`. Properties declared in the constructor.
2. **Private constructor OR a public constructor that validates every structural invariant.** Throw `\InvalidArgumentException` with a field name in the message on bad input.
3. **Named factories** (`fromDbRow`, `fromRequest`, `fromExtra`, `fromArray`). Public constructor stays minimal; factories do the type coercion + validation.
4. **One `toArray()` / `toCartExtra()` / equivalent escape hatch per DTO.** Return type is a precise `array{…}` shape in the docblock — never `array<string, mixed>` on an emitter.
5. **Per-DTO round-trip test.** Assert `fromX(toX($dto))` equals `$dto` (or covers every field). If the DTO is a nested leaf covered by a root's test, that's fine.
6. **Namespaced under `Tygh\Addons\TravelCore\Dto\{Domain}`** — `Domain` is one of `Booking`, `Hotel`, `Search` today. New domains are new subdirectories, not new addon-specific DTOs.
7. **Read-only from outside.** No setters. Mutation = emit a new instance via a `with*()` method.

---

## Structural invariants

Every DTO that's a "value" (not a builder) must be `final readonly class`.

```php
final readonly class BookingPricing
{
    public function __construct(
        public float $totalPrice,
        public string $currency,
        public string $remark,
        public string $important,
    ) {
    }
}
```

**Why `final`:** stops future subclasses sneaking in extra fields that break the `toArray` shape.

**Why `readonly`:** guarantees no in-place mutation. PHP 8.3's readonly-clone semantics let you produce a modified copy via `with*()` without giving up the no-mutation guarantee.

**Why promotion:** the field list and the constructor signature can't drift from each other. One place to read, one place to modify.

---

## Constructor validation

The constructor is the last line of defence. After it returns, every consumer can assume the invariants hold.

**Validate scalar invariants that must hold for the DTO to make sense:**
- Non-empty required strings (`hotelId`, primary keys, identifiers): throw on `''`.
- Non-negative prices / counts: throw on `< 0`.
- Cross-field invariants: `checkOut > checkIn`, `count($childrenAges) === $children`, enum-string membership, etc.
- Range invariants: `0 <= starRating <= 5`.

**Don't validate:**
- External business rules (e.g. "does this hotel accept adults only?"). Those belong in a service, not a DTO.
- Format fidelity beyond obvious malformedness (e.g. don't parse a phone-number format).
- Anything that would make a valid snapshot of historical data unreconstructable.

**Pattern — throw with the field name in the message:**

```php
public function __construct(
    public string $hotelId,
    public float $totalPrice,
) {
    if ($this->hotelId === '') {
        throw new \InvalidArgumentException('hotelId must not be empty');
    }
    if ($this->totalPrice < 0) {
        throw new \InvalidArgumentException('totalPrice must not be negative');
    }
}
```

Error messages lead with the field name so production logs pinpoint the bad input.

---

## Named factories

The public constructor handles the strict case (all fields present, types correct, invariants held). **Factories handle the tolerant case** — a DB row, a sanitised request, a legacy extras array.

```php
final readonly class Hotel
{
    public function __construct(
        public string $hotelId,
        // …
    ) {
        if ($hotelId === '') {
            throw new \InvalidArgumentException('Hotel requires non-empty hotel_id');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDbRow(array $row): self
    {
        return new self(
            hotelId: TypeCoerce::toString($row['hotel_id'] ?? ''),
            // …
        );
    }
}
```

Keeps the two concerns separate: the constructor is the contract; the factory is the coercion layer.

**Conventional factory names in this repo:**
- `fromDbRow(array)` — CS-Cart DB row
- `fromRequest(array)` — sanitised `$_REQUEST` data
- `fromExtra(array)` — cart-product `$product['extra']`
- `fromArray(array)` — generic array reconstruction (round-trip partner to `toArray()`)
- `fromCartExtra(array)` — the 30-key CS-Cart cart extra shape
- `fromX(…)` for a domain-specific reconstruction (e.g. `StayDates::fromDates(string, string)`)

Prefer one of the above over a bespoke name unless the context requires it.

---

## The `array{…}` escape hatch

DTOs cross the Smarty + `$_SESSION` + CS-Cart procedural boundary. Those layers can't consume objects — they consume arrays. Every DTO that crosses such a boundary declares an emitter:

```php
/**
 * @return array{
 *   hotel_id: string,
 *   product_id: int|null,
 *   hotel_name: string|null,
 *   star_rating: int|null,
 *   is_adults_only: 'Y'|'N',
 *   …
 * }
 */
public function toArray(): array
{
    return [
        'hotel_id' => $this->hotelId,
        'product_id' => $this->productId,
        // …
    ];
}
```

**Rules for the docblock:**
- The return type is a literal `array{…}` shape, not `array<string, mixed>`.
- Every emitted key is listed with its precise type.
- Use literal enum unions where applicable (`'Y'|'N'`, `'pending'|'confirmed'|'cancelled'`).
- Use `list<T>` for sequential arrays, `array<string, T>` only for genuine dynamic-key maps.
- If a key value is JSON-encoded, it's `string|null` — never `string|false`. Use `json_encode(...) ?: null` (or `?: '[]'` for arrays) in the method body.

Mirrored in existing code: `BookingCartItem::toCartExtra()`, `BookingCartItem::toCartProductRow()`, `Hotel::toArray()`, `SearchQuery::toArray()`, `SearchQuery::toNovotonParamsArray()`.

---

## Mutations → `with*()`

Readonly props can't be reassigned. To produce a modified copy, return a new instance:

```php
public function withHotelId(string $hotelId): self
{
    return new self(
        checkIn: $this->checkIn,
        checkOut: $this->checkOut,
        hotelId: $hotelId,
        // …
    );
}
```

Keep `with*()` methods narrow — one field per method. Don't add `withMany(array)` — if a caller needs to set many fields, they can chain.

---

## Tests

Every new DTO ships with a test that at minimum covers:
- The happy path through the primary factory (`fromX(array) → typed object`).
- A round-trip: `$dto = DtoX::fromY($fixture); self::assertEquals($dto, DtoX::fromY($dto->toArray()));` (where the round-trip is meaningful).
- Each constructor-level `throw` — one assertion per invariant.
- Edge cases that the factory tolerates (malformed JSON → null blob, '0000-00-00 00:00:00' → null date, null-island coords → null, etc).

**Placement:** `addon-novoton-holidays/app/addons/novoton_holidays/tests/Unit/Dto/{Domain}/{Name}Test.php`. The novoton addon is the only one with a configured PHPUnit test suite today; travel_core and sphinx inherit the classes via the cross-addon autoloader in `tests/bootstrap.php`.

**PHPUnit 11 attributes, not docblock metadata:**

```php
#[CoversClass(Hotel::class)]
#[CoversClass(GeoPoint::class)]
final class HotelTest extends TestCase
{
    // …
}
```

Nested DTOs that are covered by a root test (e.g. `HotelSummary` tested via `BookingCartItemTest`) do not need a separate test file. Add one only if the DTO has its own domain logic (a `from*()` factory with non-trivial coercion, or constructor validation that can't be exercised through the root).

---

## Builder exceptions

Mutational assembly (e.g. `CartAssemblyService` building a `BookingCartItem` from 6 sources across multiple methods) doesn't fit readonly. Use a **builder** (mutable, `final class`) that produces a `final readonly` DTO on `build()`:

```php
final class BookingCartItemBuilder
{
    private ?HotelSummary $hotel = null;
    // … one property per field …

    public function hotel(HotelSummary $h): self
    {
        $this->hotel = $h;
        return $this;
    }

    public function build(): BookingCartItem
    {
        if ($this->hotel === null) {
            throw new \LogicException('BookingCartItemBuilder requires hotel()');
        }
        // … other required-field checks …

        return new BookingCartItem(
            hotel: $this->hotel,
            // …
        );
    }
}
```

Builder invariants live in `build()`, not the individual setters — setters should be trivial.

---

## Locating the DTO

Namespace maps directly to path:

```
Tygh\Addons\TravelCore\Dto\{Domain}\{Name}
    → addon-travel-core/app/addons/travel_core/src/Dto/{Domain}/{Name}.php
```

Today's domains:

| Domain    | DTOs |
|-----------|---|
| `Hotel`   | `Hotel`, `GeoPoint` |
| `Search`  | `SearchQuery`, `Destination`, `RoomSpec` |
| `Booking` | `BookingCartItem` (+ `BookingCartItemBuilder`), `HotelSummary`, `RoomSelection`, `BoardSelection`, `StayDates`, `GuestList`, `ContactInfo`, `BookingTerms`, `BookingPricing` |

New domains get new subdirectories (not new addon-specific DTOs). Addon-specific logic goes in a service that composes the DTO, not a subclass of it.

---

## Code-review checklist

When reviewing a DTO PR, confirm — in order:

- [ ] `final readonly class` (or `final class` for a builder).
- [ ] Constructor uses property promotion; no setters.
- [ ] Constructor throws on every structural invariant (or the file explicitly notes why not).
- [ ] Factory methods named per the conventional list above.
- [ ] Every emitter method has an `array{…}` shape docblock, no `array<string, mixed>`.
- [ ] `json_encode` guards against `false` return (`?: null` or `?: '[]'`).
- [ ] Literal enum unions where applicable (`'Y'|'N'`, status strings).
- [ ] A test exists — either the DTO has its own, or it's covered by an existing root DTO test.
- [ ] Namespace matches path; placed in the correct `{Domain}/` subdirectory.
- [ ] CI green — PHPStan L10, Psalm, PHPCS, PHP-CS-Fixer dry-run, Rector dry-run, PHPMD, PHPUnit, `php -l`.

---

## Audit summary (snapshot at PR 10)

All 15 current DTOs were audited against this checklist as part of writing this document:

| DTO | `final readonly` | Ctor validation | Test |
|---|---|---|---|
| `Booking/BoardSelection` | ✓ | — | root (`BookingCartItemTest`) |
| `Booking/BookingCartItem` | ✓ | — (delegated to builder) | ✓ dedicated |
| `Booking/BookingCartItemBuilder` | `final class` (by design) | ✓ in `build()` | covered via `BookingCartItemTest` |
| `Booking/BookingPricing` | ✓ | — | root |
| `Booking/BookingTerms` | ✓ | — | root |
| `Booking/ContactInfo` | ✓ | — | root |
| `Booking/GuestList` | ✓ | — | root |
| `Booking/HotelSummary` | ✓ | — | root |
| `Booking/RoomSelection` | ✓ | — | root |
| `Booking/StayDates` | ✓ | — | root |
| `Hotel/Hotel` | ✓ | ✓ (hotelId non-empty) | ✓ dedicated |
| `Hotel/GeoPoint` | ✓ | ✓ (range check in `fromMixed`) | via `HotelTest` |
| `Search/Destination` | ✓ | — | via `SearchQueryTest` |
| `Search/RoomSpec` | ✓ | — | via `SearchQueryTest` |
| `Search/SearchQuery` | ✓ | — | ✓ dedicated |

**Gaps deliberately not closed in this PR** (avoid runtime-behaviour changes in a docs PR):
- `StayDates` — `checkOut > checkIn` invariant only enforced in `fromDates()`, not the constructor.
- `GuestList` — `count($childrenAges) === $children` not enforced.
- `BookingPricing` — `totalPrice >= 0` not enforced.

These can be tightened in a follow-up PR once each has direct test coverage for the new throw paths.
