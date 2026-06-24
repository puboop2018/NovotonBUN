# Architecture & Code-Quality Roadmap (Audit §3)

**Branch:** `claude/architecture-cleanup`
**Date:** 2026-06-05
**Status legend:** ✅ done in this branch · 🔜 scheduled · 🔬 needs investigation/decision

This document accompanies the §3 cleanup. It records what was landed now (the
safe, bounded wins) and lays out a **sequenced, low-risk plan** for the larger
items, which the audit itself rated P3 ("scheduled, not rushed"). The guiding
principle throughout is *incremental, behaviour-preserving change verified by
tests + a clean PHPStan run* — never a big-bang rewrite.

---

## Landed in this branch

### 3.1 — Shared hotel-repo contract in `travel_core` ✅ (partial, by design)
**What the audit asked:** move `HotelRepositoryInterface` into `travel_core/Contracts/`.

**What we found:** the two provider repositories are genuinely divergent. novoton's
interface is ~55 methods tied to its schema (`priceinfo_data`, `calendar_prices_raw`,
packages, resort stats, import modes…); sphinx's `HotelRepository` has a different
30-method surface, **no interface**, and even the overlapping names diverge
(`getById` vs `findById`; `linkToProduct` returns `void` vs `bool`). Moving novoton's
fat interface wholesale into core would pollute core and sphinx could never implement it.

**What we did instead (safe):**
- Added `Tygh\Addons\TravelCore\Contracts\HotelRepositoryInterface` — a *minimal,
  provider-neutral* contract: `findById`, `exists`, `delete`, `linkToProduct`,
  `unlinkProduct`, `getCountries`.
- novoton's `HotelRepositoryInterface` now **extends** the core contract and drops
  those six now-inherited declarations (its concrete repo already satisfies them).
- Verified: full monorepo PHPStan clean, novoton + travel_core suites green.

**🔜 Follow-ups (scheduled):**
1. **Sphinx adoption.** Converge sphinx's `HotelRepository` onto the core contract:
   rename `getById` → `findById`, change `linkToProduct` to return `bool`, add
   `exists`/`getCountries`/`delete`/`unlinkProduct` (or thin adapters), then
   `implements CoreHotelRepositoryInterface`. Each rename is mechanical + test-backed;
   do them one method per commit. *Risk: medium (sphinx call-site churn). Effort: ~half day.*
2. **Other core-consolidation targets** (higher value than the repo interface):
   | Class | Overlap | Plan |
   |---|---|---|
   | `SecurityService` | ~150 LOC validate/sanitize | **✅ Done (shared validators):** both providers implement `TravelCore\Contracts\SecurityServiceInterface` and already delegate their primitives to `TravelCore\Helpers\ValidationHelpers` (`isValidDate`, `isValidName`, `isValidEntityId`, `sanitizeName`, `toInt`/`toFloat` for ranges). Promoted the last duplicated primitive — `sanitizeString` (strip_tags + truncate) — into `ValidationHelpers`; novoton delegates to it. *(Follow-up: converge sphinx's one inline use + clear that file's boundary-typing baseline in a Phase-3 paydown pass.)* The remaining validate* bodies are genuinely provider-specific (different required fields/shapes) and stay per provider. |
   | `ConfigProvider` | ~100 LOC Registry+coerce boilerplate | Extract a `TravelCore` `AbstractConfigProvider` with the common `Registry::get` + `TypeCoerce` plumbing; providers supply their addon id + typed getters. |
   | `Container` / `SyncLogRepository` | DI + sync logging re-implemented per addon | Lift a shared `SyncLogRepositoryInterface` (+ base impl) into core; keep provider-specific table names behind a small config hook. |
   | `CacheService` (novoton 582 vs sphinx 90) | 6× size gap | **✅ Done:** added a minimal instance contract `TravelCore\Contracts\CacheServiceInterface` (`get`/`set`/`delete`/`cleanup`); novoton's interface now **extends** it and keeps `clear`/`remember`/`getStats` as extras. Sphinx's cache stays as-is **by design** — it is a *static*, search-result-only API and a static API cannot implement an instance contract; forcing the conversion would add risk for no real sharing. |

### 3.2 — PHPStan baseline paydown ✅ (baseline fully paid down — 0 entries)
**Baseline today:** **0** suppressed message-blocks (was ~1,759 at level 10). Level 10,
all three addons analysed in one run, `reportUnmatchedIgnoredErrors: false`. With an
empty baseline the invariant is now self-enforcing: any new type error fails the build
outright, no ratchet required.

**How it was driven to zero:** repeated the boundary-coercion pattern below across the
hotspots — coerce `db_*` / `json_decode` / `$_REQUEST` `mixed` at the boundary with
`TypeCoerce` / `RequestCoerce`, shape return types, then delete each cleaned file's
baseline blocks. The final 15 entries were a single shared seam: the
`CsCartFeatureAssignment` trait (used by novoton `FeatureMapper`, sphinx
`SphinxFeatureAssigner`, travel_core `VariantResolver`) leaked `mixed` from
`db_get_fields()`/`db_get_field()`/`db_query()` into typed ids/languages; coercing those
four call sites with `TypeCoerce::toIntList`/`toStringList`/`toInt` cleared all three
using-classes at once.

**🔜 The repeatable pattern (highest-leverage first):**
1. Pick one file with the most `argument.type` / `mixed` baseline entries
   (backend controllers and `Api/*Client` parsing are the hotspots).
2. Coerce inputs at the boundary with `RequestCoerce` (for `$_REQUEST`) / `TypeCoerce`
   (for decoded JSON / DB rows / API arrays). Do **not** sprinkle raw casts.
3. Re-run PHPStan on that file → 0 errors, then delete its baseline blocks.
4. Commit per file/controller so each diff is reviewable.

**Guardrails:** keep level at 10; **do not** raise further until the baseline is
materially down. Treat any *new* (non-baselined) error as a build failure — that
invariant is what keeps new code clean.

**Suggested ordering:** `controllers/backend/novoton_price_compare.php` →
`controllers/backend/novoton_hotels.php` → `Api/PricingApiClient.php` →
`Api/AvailabilityApiClient.php` → remaining controllers. *Effort: ongoing, ~1 controller/session.*

### 3.3 — God-class decomposition 🔜 (representative increment landed)
**Representative increment landed:** extracted the pure priceinfo-response shaping
concern (`extractPricesFromPriceInfo` / `formatPriceInfo` / `groupPricesByRoom`,
~90 LOC) out of `PriceInfoService` (808 LOC) into a new stateless, unit-tested
`PriceInfoShaper` collaborator (+10 tests). Behaviour-preserving; PriceInfoService
now delegates. This is the template: **find a pure, cohesive seam → move it →
delegate → add tests.**

**🔜 Remaining god classes (extract collaborators incrementally, not rewrites):**
| Class | LOC | Suggested first extractions |
|---|---|---|
| `BookingSubmissionService` | 876 | `BookingRoomsGuestsResolver` (resolveRoomsAndGuests / resolveGuestsData / groupRoomsByPackage / buildGroupGuestsAndRooms / extractGuestName, ~260 LOC); then `ApiBookingRequestBuilder` (buildApiBookingRequest); then `BookingRecordBuilder` (buildBookingRecord / persist). |
| `BookingRepository` | 862 | Split read-model queries (`findForAdminList` / `findWithOrderDetails` / `findAllForExport` / ownership) into a `BookingQueryRepository`; keep CRUD + travel_bookings sync here. |
| `PriceInfoService` | ~720 (post-3.3) | Next seam: calendar-price assembly (`getCalendarPrices`, the largest method) → `CalendarPriceBuilder`. |
| `novoton_price_compare.php` controller | 1,101 | Move the large inline HTML into a `.tpl`; extract data assembly into a `PriceComparePresenter` service. Highest visual payoff, but verify rendering manually. |

**Method:** one collaborator per commit, each with its own unit tests, each verified
by the full suite + clean PHPStan. Never change behaviour and structure in the same step.

### 3.4 — Misleading "deprecated/legacy" markers ✅
- `PriceInfoFormatter::toScalar/toInt/toFloat`: the `@deprecated 3.3.0` tags were
  removed (578 live callers, and they are legitimate thin wrappers over `TypeCoerce`).
  They are now documented as **supported convenience wrappers**, not slated for removal.
- `NovotonApi` facade: the docblock no longer calls the flat delegate methods
  "deprecated"; it's now described as a **supported convenience facade** with the
  domain accessors preferred for new code. (No `@deprecated` tags remain in either file.)

> Decision rationale: per maintainer direction, finishing a 578-site migration was
> not worth the churn/risk; the honest fix is to stop the tags from misleading.

---

## Sequencing summary (recommended order)

1. **§3.2 controller paydown** — repeat the landed pattern, 1 controller/session. *(safe, high signal)*
2. **§3.1 core consolidation** — `ConfigProvider` boilerplate, then `SyncLogRepository`,
   then `SecurityService` shared validators. *(medium)*
3. **§3.1 sphinx hotel-repo convergence** onto the core contract. *(medium)*
4. **§3.3 collaborator extractions** — `BookingSubmissionService` first, one seam/commit. *(medium)*
5. **§3.3 price_compare controller** template/presenter split. *(needs manual render verification)*

Each step: behaviour-preserving, test-backed, PHPStan-clean, reviewable in isolation.
Do **not** batch multiple god-class extractions or a baseline rewrite into one PR.
