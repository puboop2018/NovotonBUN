<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Structured timing / hit-rate metrics for the hotel-availability path.
 *
 * Emits compact, greppable events to the CS-Cart event log so the early-render
 * behaviour, cache TTL and any future cache-warming can be tuned from data
 * instead of guessed (see docs/adr/0001-availability-early-render-and-metrics.md).
 *
 * One event per meaningful transition — cache hit/miss, first offer, complete —
 * never per poll, so volume stays bounded (<= 3 events per search). Gated behind
 * the addon's `search_metrics` setting (or `debug_logging`, which implies it) so
 * it stays quiet by default; an operator flips it on to collect a sample window.
 *
 * @package SphinxHolidays
 * @since   1.4.0
 */
final class SearchMetrics
{
    /** Cache hit on the product-page search — served inline, no polling. */
    public const string EVENT_CACHE_HIT = 'cache_hit';

    /** Cache miss — async live search initiated (carries from_price_ms). */
    public const string EVENT_SEARCH_START = 'search_start';

    /** API returned final results synchronously (rare). */
    public const string EVENT_SYNC_COMPLETE = 'sync_complete';

    /** First poll that yielded the hotel's offers (carries elapsed_ms, poll). */
    public const string EVENT_FIRST_OFFER = 'first_offer';

    /** Stream drained to the end (carries elapsed_ms, polls, offers). */
    public const string EVENT_COMPLETE = 'complete';

    /**
     * Record a metric event when metrics logging is enabled; no-op otherwise.
     *
     * @param array<string, scalar|null> $data Event-specific fields (timings, counts, ids)
     */
    public static function record(string $event, array $data = []): void
    {
        if (!ConfigProvider::isSearchMetricsEnabled()) {
            return;
        }

        fn_log_event('general', 'runtime', self::buildPayload($event, $data));
    }

    /**
     * Build the structured log payload. Pure (no side effects) so it can be
     * unit tested without the CS-Cart logging globals.
     *
     * The `message` / `metric` keys take precedence over any same-named key in
     * $data (array-union semantics), so a metric line is always identifiable.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, scalar|null>
     */
    public static function buildPayload(string $event, array $data = []): array
    {
        return ['message' => 'sphinx.metric ' . $event, 'metric' => $event] + $data;
    }
}
