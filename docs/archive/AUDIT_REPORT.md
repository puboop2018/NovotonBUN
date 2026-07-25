# NovotonBUN — Codebase Audit Report

**Date:** 2026-06-04
**Scope:** `addon-novoton-holidays`, `addon-sphinx-holidays`, `addon-travel-core` (≈52K LOC source, vendor excluded)
**Method:** Four parallel read-only audits (security, pricing/correctness, architecture/duplication, data/DB/cron) followed by manual verification of the critical findings against source. Severity ratings below are **post-verification** and differ from the raw agent output where the original claims were overstated or speculative.

> **Verification legend:** ✅ confirmed against code · ⚠️ plausible, needs business/runtime confirmation · ❌ original claim overstated (downgraded)

---

## Executive summary

The codebase is a functional but mid-refactor CS-Cart travel-booking suite. The most actionable issues, in priority order:

1. **CRITICAL — API credentials written to logs unmasked** (`ReservationApiClient.php`). One-line fix, ship immediately.
2. **HIGH — Type-safety debt**: ~1,800–2,600 suppressed PHPStan errors (≈1 per 20 LOC). Concentrated in controllers and API response parsing.
3. **HIGH — Incomplete `travel_core` extraction**: ~2–3K LOC duplicated between the two consumer addons.
4. **MEDIUM — Pricing edge cases**: silent fee-tier defaults and an unguarded division; both real, both conditional.
5. **MEDIUM — Sync concurrency**: the batched-sync state machine has a genuine check-then-act gap (not the "corruption" the raw audit claimed, but real).

Security fundamentals are otherwise **solid**: SQL injection (parameterized throughout), XSS (escaped output), cron access-key validation (`hash_equals`), and encryption-key file handling all verified clean.

---

## 1. Security

### 1.1 CRITICAL — Unmasked API credentials in event log ✅
**`addon-novoton-holidays/.../src/Api/ReservationApiClient.php:133-136` and `:180-183`**

Two methods log the raw request XML — which contains `<usr>`/`<psw>` credentials — via `fn_log_event()` without masking:

```php
// createHotelRequest() — line 133
fn_log_event('general', 'runtime', ['message' => 'Novoton hotel_request Request', 'xml' => $xml]);
// getAlternatives() — line 180
fn_log_event('general', 'runtime', ['message' => 'Novoton alternative_RS Request', 'xml' => $xml]);
```

The class already has `maskCredentials()` and uses it correctly on the sibling return paths (lines 143, 165) — proving the logged `$xml` carries live credentials. Result: credentials land in `cscart_event_log` and any log aggregation, readable by anyone with backend log access.

**Fix:** wrap both with `$this->maskCredentials($xml)`. Then grep the whole `Api/` tree for other `fn_log_event(... 'xml' => $xml ...)` sites built from `xmlCredentials()`.

### 1.2 HIGH — HTTP (non-TLS) API URL only warns, does not block ✅
**`.../src/NovotonHttpClient.php:61-70`** — when the configured API URL is `http://`, the client logs a warning then sends credentials anyway. Recommend: refuse `http://` unless an explicit `allow_insecure_api` opt-in setting is set.

### 1.3 MEDIUM — Contact email never format-validated ✅
**`SecurityService::validateBookingData()`** validates most booking fields but not `contact.email`; `add_to_cart.php` stores it via `TypeCoerce::toString()` (type-safe, not format-safe). Add a `FILTER_VALIDATE_EMAIL` check.

### 1.4 LOW — Backend authorization is schema-only
**`controllers/backend/novoton_hotels.php:46`** delegates auth entirely to CS-Cart's schema ACL (standard practice). Consider an explicit `_check_acl()` fallback as defense-in-depth on destructive modes.

### 1.5 Verified clean
- **SQL injection** — all `db_*` calls use placeholders (`?i ?s ?a ?p ?n`); `buildWhereClause()` escapes correctly.
- **XSS** — template output escaped; no raw request echoing found.
- **Cron access key** — validated with timing-safe `hash_equals()` in all three addons before any work runs.
- **Encryption key storage** — `/var/novoton/.encryption_key` at `0600` in a `0700` dir, `random_bytes(32)`, no user-controlled paths.

---

## 2. Pricing / Correctness

> This is the highest-business-risk area (mischarging customers). Findings verified individually.

### 2.1 MEDIUM — Silent fee-tier defaults hide missing API data ✅
**`FeeCalculator.php:259-262`**
```php
$toDays   = ($rawToDays   !== '') ? (int) $rawToDays   : 3;
$fromDays = ($rawFromDays !== '') ? (int) $rawFromDays : 4;
```
When the API omits `ToDays`/`FromDays`, fabricated 3/4 thresholds drive tier selection (`Price1` vs `Price2`, lines 275-284). A stay whose `nights` straddles that fabricated boundary gets the wrong handling-fee tier. Conditional on missing data, but it fails *silently*. **Fix:** treat missing thresholds as "no threshold" (flat `Price1`) rather than inventing 3/4, or log loudly.

### 2.2 LOW — Unguarded division in reduction calc ✅
**`DiscountCalculator.php:310`** — `$avgNightPrice = $bpTotal / $nights;` has no `$nights > 0` guard, unlike the analogous code at line 211. Requires a 0-night booking (normally blocked upstream), so low likelihood, but a one-line guard removes the crash path.

### 2.3 NEEDS-REVIEW — Asymmetric surcharge selection ⚠️
**`DiscountCalculator.php:474-483`** — discount selection picks the largest positive `Perc`; for negative `Perc` (surcharge) the branch `$perc < $bestPercent` keeps the *most negative* value. Whether this is a bug depends on (a) whether negative `Perc` actually occurs in supplier data and (b) the intended "best" semantics for surcharges. **Action:** confirm with a data sample before changing — do not blind-fix.

### 2.4 LOW — `strtotime()` result cast without false-check ✅
**`DiscountCalculator.php:171-177`** — `(int) strtotime($checkIn.' + '.$nights.' days')` turns a parse failure (`false`) into `0` → `1970-01-01`, silently corrupting date-window comparisons for malformed input. Guard the `false` case.

### 2.5 Notes on downgraded raw findings
The raw pricing audit listed 13 items; several (commission round-trip "precision loss", JSON-decode logging, decimal-separator double-conversion, a couple of "reviewed — correct per spec" entries) are code-quality observations, not defects, and are not reproduced here as bugs.

---

## 3. Architecture & Code Quality

### 3.1 HIGH — Incomplete `travel_core` extraction → cross-addon duplication
The repo is mid-migration ("BEFORE SPLIT TO CORE ADDON"). `travel_core` **is** actively used (140 imports in novoton across 73 files; 98 in sphinx across 56) — the refactor is incomplete, not abandoned. Duplicated/near-duplicated classes that should be (partly) in core:

| Class | novoton LOC | sphinx LOC | Note |
|---|---|---|---|
| `HotelRepository` | 564 | 741 | Divergent (different table schemas) → extract **interface** |
| `SecurityService` | 611 | 202 | ~150 LOC overlapping validate/sanitize |
| `ConfigProvider` | 521 | 344 | ~100 LOC common Registry+coerce boilerplate |
| `CacheService` | 582 | 90 | novoton's is 6× larger — review for feature creep |
| `Container`, `SyncLogRepository` | both | both | DI/logging re-implemented per addon |

Est. **2–3K LOC** consolidatable. Quick win: move the existing `HotelRepositoryInterface` (currently in novoton) into `travel_core/Contracts/`.

### 3.2 HIGH — Type-safety debt (PHPStan baseline)
The `phpstan-baseline.neon` suppresses on the order of **~1,800–2,600** errors (raw `message:` lines ≈1,759; error count higher with multipliers). Top categories are `mixed`→scalar casts, `foreach`/`count`/offset on `mixed`, and API-response parsing — concentrated in **backend controllers** and `Api/*Client` classes. `travel_core` already ships `TypeCoerce`/`RequestCoerce` helpers; standardizing controller input handling on them is the highest-leverage cleanup. Avoid raising the PHPStan level until the baseline is paid down.

### 3.3 MEDIUM — God classes (SRP)
`BookingSubmissionService` (876), `BookingRepository` (862), `PriceInfoService` (808), and the `novoton_price_compare.php` controller (1,101, with large inline HTML). Each mixes 8–13 concerns. Recommend extracting hydration/filter/builder/presenter collaborators incrementally — not a rewrite.

### 3.4 MEDIUM — Live "legacy"/"deprecated" code still in use
`PriceInfoFormatter::toScalar/toInt/toFloat` are `@deprecated` but still called from controllers; the `NovotonApi` facade is documented "Legacy — 46 methods, couples caller" yet still used in `SearchService`/`HotelAvailabilitySearcher`. Either finish the migration or drop the deprecation tags — the current state misleads. (Note: the recently-removed `HotelSyncCommand`/`HotelSync` in PR #133 is one such cleanup already done.)

---

## 4. Data, DB & Cron Pipeline

### 4.1 MEDIUM — Check-then-act race in batched sync ⚠️
**`AbstractBatchedSync.php` `run()`** loads state and tests `status === 'in_progress'` *before* acquiring the state lock; the lock is only taken inside `StateManager::start()`. Two overlapping cron runs can both pass the check and both call `start()`. **Fix:** acquire the lock before the status read and re-check after (double-checked pattern), and make `start()` a no-op if already in progress. *(This is the legitimate core of the raw audit's concurrency cluster.)*

### 4.2 ❌ DOWNGRADED — "State corruption on save"
The raw audit rated `StateManager::save()` (lines 144-157) CRITICAL for "corruption / duplicate processing." **Incorrect:** the temp-write + atomic `rename()` pattern is the *correct* approach. On `rename()` failure the state file is left unchanged and internally consistent; the real (minor) impact is that the latest progress increment is lost, not that state corrupts. Reclassified **LOW** (consider a retry on transient rename failure).

### 4.3 MEDIUM — Missing indexes on hot filter columns
Queries filter heavily on `has_room_price` and join `novoton_hotel_packages.hotel_id`, but the install/migration schema doesn't guarantee indexes on them (MySQL does not auto-index FK columns in all engines/versions). Add `KEY idx_has_room_price (has_room_price)`, a composite `(country, has_room_price)`, and verify `idx_hotel_id` on packages. **Verify against actual `SHOW CREATE TABLE` before adding** — the agent inferred this from the install file, not a live schema.

### 4.4 MEDIUM — Non-atomic flag + timestamp update
`DatabaseHelper::batchUpdateHasRoomPriceFlag()` updates the flag and `last_price_check` in separate queries with no transaction; a mid-run failure leaves a hotel marked "freshly checked" with no data. Wrap in a transaction and only stamp `last_price_check` on a successful fetch.

### 4.5 LOW — Caching observations
`CacheService` key sanitization (`preg_replace('/[^a-zA-Z0-9_-]/','_')`) is collision-prone without a type namespace; in-memory eviction is FIFO not LRU; static per-request booking cache isn't invalidated cross-process. All low impact for current scale; namespacing cache keys is the cheapest improvement.

### 4.6 Verified
`CronDispatcher` auto-discovery has **no dangling references** to the deleted `HotelSyncCommand` (PR #133) — confirmed.

---

## Prioritized action list

| Pri | Item | Location | Effort |
|---|---|---|---|
| **P0** | Mask credentials before logging | `ReservationApiClient.php:135,182` | 5 min |
| P1 | Refuse `http://` API URL (opt-in override) | `NovotonHttpClient.php:61` | 30 min |
| P1 | Lock-before-read in batched sync `run()` | `AbstractBatchedSync.php` | 2 h |
| P1 | Fee-tier: stop fabricating 3/4 defaults | `FeeCalculator.php:261` | 30 min |
| P2 | Add DB indexes (verify live schema first) | install/migration | 1 h |
| P2 | Transaction-wrap flag+timestamp update | `DatabaseHelper.php:30` | 1 h |
| P2 | Email format validation | `SecurityService.php` | 30 min |
| P2 | Guard div-by-zero + `strtotime` false-check | `DiscountCalculator.php:310,171` | 30 min |
| P3 | Move `HotelRepositoryInterface` → `travel_core` | architecture | 1 h |
| P3 | Standardize controller input on `RequestCoerce`/`TypeCoerce`, pay down PHPStan baseline | controllers | ongoing |
| P3 | Investigate surcharge selection w/ data sample | `DiscountCalculator.php:474` | invest. |

*P0/P1 are safe, localized fixes. P3 items are larger and should be scheduled, not rushed.*
