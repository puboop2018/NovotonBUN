<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Gates product-less hotels on immediate availability.
 *
 * For each destination with candidate hotels (no product yet, unflagged or
 * already flagged no_availability), runs one live search and marks hotels with
 * no immediate-confirmation offer as 'no_availability' (so AddProductsCommand
 * skips them), and clears that flag from hotels that have become bookable again.
 * Linked products and hotels skipped for other reasons are never touched.
 *
 * Marking is scoped to destinations that were probed successfully, so an API
 * error never mass-flags hotels — only clearing (always safe) still applies.
 *
 * Extracted from HotelSyncService so the availability concern lives behind its
 * own collaborator, paired with HotelSkipRepository. The inter-destination and
 * poll delays are injectable (defaulting to the production constants) so tests
 * can run without real sleeps. Behaviour is preserved verbatim.
 */
class HotelAvailabilityGate
{
    // Availability probe parameters (mirror DiscoverBoardsCommand): a single
    // search window per destination, far enough out to have inventory loaded.
    private const int NIGHTS = 7;
    private const int ADULTS = 2;
    private const int DAYS_AHEAD = 30;
    private const int POLL_INTERVAL = 3;     // seconds between result polls
    private const int POLL_DEADLINE = 60;    // hard cap per destination
    private const int DEST_DELAY_US = 500000; // 500ms between destinations

    public function __construct(
        private readonly SphinxApi $api,
        private readonly HotelSkipRepository $skipRepo,
        private readonly int $destDelayUs = self::DEST_DELAY_US,
        private readonly int $pollIntervalSecs = self::POLL_INTERVAL,
    ) {
    }

    /**
     * Run the availability gate for one country's destinations.
     *
     * @param int[] $destinationIds
     * @param array<string, mixed> $stats
     * @param callable(string): void $output Progress sink
     * @return array<string, mixed>
     */
    public function apply(string $countryCode, array $destinationIds, array $stats, callable $output): array
    {
        // Normalise to a unique list of positive int destination IDs.
        $destIds = [];
        foreach ($destinationIds as $d) {
            $di = ValidationHelpers::toInt($d);
            if ($di > 0) {
                $destIds[] = $di;
            }
        }
        $destIds = array_values(array_unique($destIds));
        if ($destIds === []) {
            return $stats;
        }

        $candidates = $this->skipRepo->findAvailabilityGateCandidates($destIds);
        if ($candidates === []) {
            $output("    {$countryCode}: availability gate — no unlinked hotels to check");
            return $stats;
        }

        $output(sprintf(
            '    %s: availability gate — %d candidate hotel(s) across %d destination(s)',
            $countryCode,
            count($candidates),
            count($destIds),
        ));

        // Probe each destination once; collect the set of hotel IDs that have at
        // least one immediate-confirmation offer, and which destinations answered.
        $checkIn = date('Y-m-d', strtotime('+' . self::DAYS_AHEAD . ' days'));
        $checkOut = date('Y-m-d', strtotime('+' . (self::DAYS_AHEAD + self::NIGHTS) . ' days'));
        $currency = ConfigProvider::getDefaultCurrency();
        $debug = ConfigProvider::isDebugLogging();

        /** @var array<string, true> $availableSet */
        $availableSet = [];
        /** @var array<int, true> $probedDestinations */
        $probedDestinations = [];
        $errors = 0;
        $httpClient = $this->api->getHttpClient();

        foreach ($destIds as $destId) {
            if ($httpClient->isCircuitOpen()) {
                $output('    Circuit breaker open — stopping availability probe early (unprobed hotels left unchanged)');
                break;
            }

            $searchResponse = $this->api->searchHotels([
                'destination_id' => $destId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'occupancy' => [['adults' => self::ADULTS, 'children_ages' => []]],
                'currency' => $currency,
            ]);

            if (!is_array($searchResponse)) {
                $errors++;
                if ($errors <= 5) {
                    $output("    [WARN] availability search failed for destination {$destId}");
                }
                usleep($this->destDelayUs);
                continue;
            }

            // Search was accepted → destination counts as probed (zero offers is
            // a legitimate "no availability" answer, not an error).
            $probedDestinations[$destId] = true;

            // Inline results (synchronous completion) + cursor-polled results.
            $availableSet += OfferAvailability::collectImmediateHotelIds(
                TypeCoerce::toRowList($searchResponse['results'] ?? $searchResponse['data'] ?? []),
            );
            $cursor = TypeCoerce::toString($searchResponse['cursor'] ?? $searchResponse['search_id'] ?? '');
            if ($cursor !== '') {
                $availableSet += $this->pollImmediateHotelIds($cursor);
            }

            if ($debug) {
                $output("    [DEBUG] dest={$destId}: " . count($availableSet) . ' immediate hotel(s) so far');
            }

            usleep($this->destDelayUs);
        }

        // Partition candidates into mark / clear operations.
        $toMark = [];
        $toClear = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            if ($hid === '') {
                continue;
            }
            $reason = TypeCoerce::toString($row['product_skip_reason'] ?? '');
            if (isset($availableSet[$hid])) {
                if ($reason === HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY) {
                    $toClear[] = $hid;
                }
                continue;
            }
            // Unavailable: only flag when this destination was actually probed
            // and the hotel carries no skip reason yet.
            $destId = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if ($reason === '' && isset($probedDestinations[$destId])) {
                $toMark[] = $hid;
            }
        }

        $marked = $this->skipRepo->markSkippedBatch($toMark, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        $cleared = $this->skipRepo->clearSkipReasonBatch($toClear, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $stats['availability_probed'] = count($probedDestinations);
        $stats['availability_gated'] = $marked;
        $stats['availability_cleared'] = $cleared;
        $stats['availability_errors'] = $errors;

        $output(sprintf(
            '    %s: availability gate — %d marked no_availability, %d cleared, %d search error(s)',
            $countryCode,
            $marked,
            $cleared,
            $errors,
        ));

        return $stats;
    }

    /**
     * Poll a search cursor to completion, collecting hotel IDs with an
     * immediate-confirmation offer. Memory stays flat: each batch is reduced to
     * the hotel-id set instead of being accumulated.
     *
     * The Sphinx spec's only definitive terminal is a cursor:null page; empty
     * pages with a live cursor are normal and keep being polled until the
     * per-destination deadline.
     *
     * @return array<string, true>
     */
    private function pollImmediateHotelIds(string $cursorToken): array
    {
        /** @var array<string, true> $hotelIds */
        $hotelIds = [];
        $cursor = $cursorToken;
        $deadline = time() + self::POLL_DEADLINE;

        while (time() < $deadline) {
            $response = $this->api->getHotelResults('', $cursor);
            if (!is_array($response)) {
                break;
            }

            $batch = TypeCoerce::toRowList($response['results'] ?? $response['data'] ?? []);
            if ($batch !== []) {
                $hotelIds += OfferAvailability::collectImmediateHotelIds($batch);
            }

            $nextCursor = TypeCoerce::toString($response['cursor'] ?? $response['next_cursor'] ?? '');
            if ($nextCursor === '') {
                break; // cursor:null → definitive end of search
            }
            $cursor = $nextCursor;

            if (time() < $deadline) {
                sleep($this->pollIntervalSecs);
            }
        }

        return $hotelIds;
    }
}
