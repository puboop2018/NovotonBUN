# ADR-0001 — Early-render availability + search-path metrics

**Status:** Accepted — 2026-06-12
**Context roadmap:** `ARCHITECTURE_PLAN.md` (Audit §3). This ADR records a P0
hardening increment on the hotel-availability path that sits *ahead* of the §3
queue because it closes a correctness risk we introduced, not a debt item.
**Scope:** `sphinx_holidays` hotel product-page availability search.

---

## Context

The product-page availability flow is a read-through cache over a slow live
aggregator:

1. `search.php` builds a cache key from the search params + `hotel_id`
   (`CacheService::buildSearchKey`). On a **hit** it renders inline with no
   polling. The key is derived from params, not the session, so the cache is
   **cross-user and sticky for its TTL** (`cache_ttl_search`, default 900s).
2. On a **miss** it shows an instant "from" price (`cache/hotels`, ~1s) and
   starts an async search; the browser polls `search_poll.php` for incremental
   results.
3. The poller **early-stopped**: as soon as the target hotel had *any* offers it
   stopped polling, on the assumption that "a hotel's offers arrive together in
   one bulk page." `search_poll.php` therefore wrote the cache on **every** poll
   (it could not wait for a terminal status — the API never sends
   `status=completed`).

### The problem

Early-stop is a per-request latency bet, but the cache it populates is
**cross-user and sticky**. So if a hotel's offers ever split across pages, the
poller stops early, caches an **incomplete** set, and **every** visitor is then
served that incomplete set until the TTL expires. The blast radius is the whole
audience, not one request. A live run against hotel 3371 confirmed the happy
path (all 3 offers in page 1) but cannot guarantee the invariant for every
hotel/date/occupancy.

We also have **no timing/hit-rate signal**, so every downstream decision
(grace size, TTL, whether to add cache-warming or per-destination caching) is
currently a guess.

---

## Decision

### P0a — Render early, poll to the end, cache only the complete set

- **Client (`search.tpl`):** on the first poll that yields offers, reveal them
  and drop the skeleton (keep the fast first paint), but **keep polling** until
  the cursor is exhausted (or `maxPolls` trips). Late offers append
  progressively.
- **Server (`search_poll.php`):** accumulate per-poll in the session
  (per-user, in-flight buffer) but write the **cross-user cache only at
  end-of-stream** (cursor exhausted) or on an explicit `finalize`, tagged
  `complete => true`.

Net: users keep early-stop latency (~first-offer time), while the shared cache
only ever holds **complete** sets. The A-vs-B trade-off (fast vs complete)
dissolves — we get both.

### P0b — Search-path metrics

A small, pure, unit-tested `Helpers\SearchMetrics` emits compact structured
events to the CS-Cart event log at **meaningful transitions only** (≤3 per
search): `cache_hit`, `search_start` (with `from_price_ms`), `first_offer`
(elapsed + poll index), `complete` (elapsed + polls + offers), `sync_complete`.
Gated behind the existing `debug_logging` setting to keep production logs quiet.

These give us, from data: cache hit-rate, time-to-from-price, time-to-first-offer,
polls-to-first-offer, and time-to-complete.

---

## Consequences

**Positive**
- Removes the shared-incompleteness risk: the cross-user cache is authoritative.
- Catches split-page offers automatically (they append + land in the cache).
- Unlocks data-driven tuning of everything below.

**Negative / trade-offs**
- During an in-flight search the cache key is empty, so concurrent first
  visitors each run their own search (no partial is served). This is the
  cache-stampede window addressed by **P1** below — accepted for now.
- If the user navigates away before the stream ends, nothing is cached (same
  cost as a miss). Acceptable.
- `maxPolls` bail does **not** finalize → no partial is cached as complete.

**Follow-ups (deferred, tracked against `ARCHITECTURE_PLAN.md`)**
- **P1 — single-flight lock** on cold popular keys, *if* P0b shows concurrent
  cold misses.
- **P2 — async warming** of popular (hotel × common dates/occupancy) into the
  same cache, mirroring novoton's `OffersUpdateCommand`.
- **Per-destination caching** and **SSE**: do **not** build on spec — each has a
  metric-driven trigger (destination-browse traffic / poll round-trip overhead).
- A dedicated `search_metrics` setting (separate from `debug_logging`, which
  still implies it) ships with this increment, so metrics can run continuously
  in production without verbose debug noise.

---

## Verification

- Unit: `SearchMetricsTest` (pure payload builder).
- Manual: hotel 3371 / destination 168566 — confirm first paint at
  first-offer time, background polling to `cursor:null`, and a `complete`
  cache entry that a second visitor hits inline.
