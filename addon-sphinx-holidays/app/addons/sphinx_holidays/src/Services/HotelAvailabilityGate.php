<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Gates ALL hotels on immediate availability.
 *
 * For each destination with candidate hotels (unflagged or carrying a legacy
 * no_availability flag — linked to a CS-Cart product or not), runs a live
 * search per probe window (PROBE_WINDOWS_DAYS_AHEAD) and DELETES hotels with
 * no immediate-confirmation offer in ANY window — "Hotels with immediate
 * confirmation" means only bookable hotels exist anywhere: a linked hotel
 * loses its CS-Cart product first (fn_delete_product) and then its row, an
 * unlinked one just its row. The hotel row is only removed once the product
 * is confirmed gone, so a product delete the platform refuses never strands
 * an orphan product without its hotel. The gate also clears the legacy flag
 * from hotels that have become bookable again. Hotels skipped for other
 * reasons are never touched.
 *
 * Deletion is scoped to destinations that were probed successfully, so an API
 * error never mass-deletes hotels — only clearing (always safe) still applies.
 *
 * Extracted from HotelSyncService so the availability concern lives behind its
 * own collaborator, paired with HotelSkipRepository. The inter-destination and
 * poll delays are injectable (defaulting to the production constants) so tests
 * can run without real sleeps.
 */
class HotelAvailabilityGate
{
    // Availability probe parameters. A hotel counts as available when ANY
    // probe window yields an immediate-confirmation offer — a single fixed
    // window (the old +30d-only probe) excluded hotels whose inventory sits
    // outside that arbitrary week (e.g. summer-only hotels probed in spring).
    private const int NIGHTS = 7;
    private const int ADULTS = 2;
    /** @var list<int> check-in offsets (days from today), each probed for NIGHTS */
    private const array PROBE_WINDOWS_DAYS_AHEAD = [14, 30, 60];
    private const int POLL_INTERVAL = 3;     // seconds between result polls
    private const int POLL_DEADLINE = 60;    // hard cap per destination+window
    private const int DEST_DELAY_US = 500000; // 500ms between searches

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
            $output("    {$countryCode}: availability gate — no hotels to check");
            return $stats;
        }

        $output(sprintf(
            '    %s: availability gate — %d candidate hotel(s) across %d destination(s)',
            $countryCode,
            count($candidates),
            count($destIds),
        ));

        // Probe each destination across the probe windows; collect the set of
        // hotel IDs with at least one immediate-confirmation offer in ANY
        // window, and which destinations answered at least once.
        $currency = ConfigProvider::getDefaultCurrency();
        $debug = ConfigProvider::isDebugLogging();

        // Per-destination candidate hotel sets, so a destination can stop
        // probing further windows once every candidate is already available.
        /** @var array<int, array<string, true>> $candidatesByDest */
        $candidatesByDest = [];
        foreach ($candidates as $row) {
            $hid = TypeCoerce::toString($row['hotel_id'] ?? '');
            $did = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if ($hid !== '' && $did > 0) {
                $candidatesByDest[$did][$hid] = true;
            }
        }

        /** @var array<string, true> $availableSet */
        $availableSet = [];
        /** @var array<int, true> $probedDestinations */
        $probedDestinations = [];
        $errors = 0;
        $stopProbing = false;
        $httpClient = $this->api->getHttpClient();

        foreach ($destIds as $destId) {
            foreach (self::PROBE_WINDOWS_DAYS_AHEAD as $daysAhead) {
                if ($httpClient->isCircuitOpen()) {
                    $output('    Circuit breaker open — stopping availability probe early (unprobed hotels left unchanged)');
                    $stopProbing = true;
                    break;
                }

                $searchResponse = $this->api->searchHotels([
                    'destination_id' => $destId,
                    'check_in' => date('Y-m-d', strtotime("+{$daysAhead} days")),
                    'check_out' => date('Y-m-d', strtotime('+' . ($daysAhead + self::NIGHTS) . ' days')),
                    'occupancy' => [['adults' => self::ADULTS, 'children_ages' => []]],
                    'currency' => $currency,
                ]);

                if (!is_array($searchResponse)) {
                    $errors++;
                    if ($errors <= 5) {
                        $output("    [WARN] availability search failed for destination {$destId} (+{$daysAhead}d window)");
                    }
                    usleep($this->destDelayUs);
                    continue;
                }

                // Search was accepted → destination counts as probed (zero offers
                // is a legitimate "no availability" answer, not an error).
                $probedDestinations[$destId] = true;

                // Inline results (synchronous completion) + cursor-polled results.
                $availableSet += OfferAvailability::collectImmediateHotelIds(
                    TypeCoerce::toRowList($searchResponse['results'] ?? []),
                );
                $cursor = TypeCoerce::toString($searchResponse['cursor'] ?? '');
                if ($cursor !== '') {
                    $availableSet += $this->pollImmediateHotelIds($cursor);
                }

                if ($debug) {
                    $output("    [DEBUG] dest={$destId} +{$daysAhead}d: " . count($availableSet) . ' immediate hotel(s) so far');
                }

                usleep($this->destDelayUs);

                // All of this destination's candidates already available —
                // further windows can't change the partition below.
                if (isset($candidatesByDest[$destId])
                    && array_diff_key($candidatesByDest[$destId], $availableSet) === []
                ) {
                    break;
                }
            }

            if ($stopProbing) {
                break;
            }
        }

        // Partition candidates into delete / clear operations. Unavailable
        // hotels in successfully-probed destinations are removed outright
        // (legacy-flagged rows included, so old flag-mode leftovers purge on
        // the first run); available hotels get any legacy flag cleared.
        $toDelete = [];
        /** @var array<string, int> $toDeleteLinked hotel_id => product_id */
        $toDeleteLinked = [];
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
            $destId = ValidationHelpers::toInt($row['destination_id'] ?? 0);
            if (isset($probedDestinations[$destId])) {
                $productId = ValidationHelpers::toInt($row['product_id'] ?? 0);
                if ($productId > 0) {
                    $toDeleteLinked[$hid] = $productId;
                } else {
                    $toDelete[] = $hid;
                }
            }
        }

        $deleted = $this->skipRepo->deleteUnlinkedBatch($toDelete);
        [$linkedDeleted, $productsDeleted] = $this->deleteLinkedHotels($toDeleteLinked, $output);
        $deleted += $linkedDeleted;
        $cleared = $this->skipRepo->clearSkipReasonBatch($toClear, HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $stats['availability_probed'] = count($probedDestinations);
        $stats['availability_deleted'] = $deleted;
        $stats['availability_products_deleted'] = $productsDeleted;
        $stats['availability_cleared'] = $cleared;
        $stats['availability_errors'] = $errors;

        $output(sprintf(
            '    %s: availability gate — %d removed (no immediate-confirmation offer, %d CS-Cart product(s) deleted with them), %d cleared, %d search error(s)',
            $countryCode,
            $deleted,
            $productsDeleted,
            $cleared,
            $errors,
        ));

        return $stats;
    }

    /**
     * Delete linked unavailable hotels together with their CS-Cart products.
     *
     * "Hotels with immediate confirmation" extends to the product catalog: a
     * hotel without a bookable offer must not remain purchasable. The product
     * goes FIRST; the hotel row is deleted only once the product is confirmed
     * gone (deleted now, or already absent), so a delete the platform refuses
     * leaves the pair intact for the next run instead of stranding an orphan
     * product with no hotel row behind it.
     *
     * @param array<string, int> $hotelToProduct hotel_id => product_id
     * @param callable(string): void $output
     * @return array{0: int, 1: int} [hotels deleted, products deleted]
     */
    private function deleteLinkedHotels(array $hotelToProduct, callable $output): array
    {
        if ($hotelToProduct === [] || !function_exists('fn_delete_product')) {
            return [0, 0];
        }

        $productsDeleted = 0;
        $rowsToDelete = [];
        foreach ($hotelToProduct as $hotelId => $productId) {
            if (fn_delete_product($productId)) {
                $productsDeleted++;
                $rowsToDelete[] = (string) $hotelId;
                continue;
            }

            // A false return also covers "product already gone" (deleted by
            // hand, or by deduplication). Only a product that still exists
            // blocks the hotel row.
            $stillExists = function_exists('fn_get_product_name')
                && !in_array(fn_get_product_name($productId), ['', false, null], true);
            if ($stillExists) {
                $output("    [WARN] could not delete product {$productId} for hotel {$hotelId} — row kept for the next run");
                continue;
            }

            $rowsToDelete[] = (string) $hotelId;
        }

        return [$this->skipRepo->deleteBatch($rowsToDelete), $productsDeleted];
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

            $batch = TypeCoerce::toRowList($response['results'] ?? []);
            if ($batch !== []) {
                $hotelIds += OfferAvailability::collectImmediateHotelIds($batch);
            }

            $nextCursor = TypeCoerce::toString($response['cursor'] ?? '');
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
