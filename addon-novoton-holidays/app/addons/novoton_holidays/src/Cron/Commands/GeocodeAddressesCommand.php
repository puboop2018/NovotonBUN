<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Exceptions\GeocodeTransportException;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;

/**
 * Reverse-geocode hotel coordinates into approximate street addresses via
 * OSM Nominatim — the Novoton API has no street field, so this is the only
 * source for the "street, city, country" PDP location line.
 *
 * Every attempt stamps geocoded_at, even when no street was found, so the
 * backlog drains monotonically and re-runs are naturally resumable with no
 * state file: a candidate is simply a row with real coordinates and no
 * stamp. Requests are paced to 1/second per the public Nominatim usage
 * policy; a transport failure aborts the run early, leaving the remaining
 * hotels unstamped for the next run.
 *
 * Params:
 *   limit=N   hotels per run (default 300 ≈ 5 min at 1 req/s)
 *   status=1  print backlog counters, no processing
 *   force=1   clear all stamps first (full re-geocode)
 */
class GeocodeAddressesCommand extends AbstractCronCommand
{
    private const int DEFAULT_LIMIT = 300;

    /** Rows with real coordinates that have never been attempted. */
    private const string ELIGIBLE_WHERE = 'latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0 AND longitude <> 0 AND geocoded_at IS NULL';

    private ?NominatimClient $client = null;

    /** Pacing hook (seconds), injectable for tests. */
    private ?\Closure $sleeper = null;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['geocode_addresses'];
    }

    public static function getDescription(): string
    {
        return 'Reverse-geocode hotel coordinates into street addresses (OSM Nominatim)';
    }

    public function setClient(NominatimClient $client): void
    {
        $this->client = $client;
    }

    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = \Closure::fromCallable($sleeper);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if (!empty($this->getParam('status'))) {
            return $this->printStatus();
        }

        if (!ConfigProvider::isGeocodingEnabled()) {
            $this->output('Geocoding is disabled — enable it in the Novoton addon settings first.');

            return ['success' => false, 'error' => 'Geocoding disabled in settings'];
        }

        $contactEmail = ConfigProvider::getGeocodingContactEmail();
        if ($contactEmail === '') {
            $this->output('Set the geocoding contact email in the addon settings first — the Nominatim usage policy requires identifying the application.');

            return ['success' => false, 'error' => 'Missing geocoding contact email'];
        }

        if (!empty($this->getParam('force'))) {
            db_query('UPDATE ?:novoton_hotels SET geocoded_at = NULL');
            $this->output('force=1: cleared all geocode stamps — full re-geocode.');
        }

        $limit = TypeCoerce::toInt($this->getParam('limit', self::DEFAULT_LIMIT));
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $hotels = $this->getCandidates($limit);

        if ($hotels === []) {
            $this->output('Nothing to geocode — every hotel with coordinates is already stamped.');
            $this->output('(Hotels without coordinates become eligible after a hotel_list sync.)');
            $stats = ['geocoded' => 0, 'no_street' => 0, 'remaining' => 0];
            $this->logComplete('geocode_addresses', $stats);

            return ['success' => true, 'stats' => $stats];
        }

        $endpoint = ConfigProvider::getGeocodingEndpoint();
        $this->output('Reverse geocoding ' . count($hotels) . " hotels via {$endpoint} (1 req/s)...");
        $this->output('');

        $client = $this->client ?? new NominatimClient(null, $endpoint, $contactEmail, 'NovotonHolidays/1.0');
        $sleeper = $this->sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };

        $withStreet = 0;
        $noStreet = 0;
        $aborted = false;
        $abortError = '';

        foreach ($hotels as $i => $hotel) {
            if ($i > 0) {
                $sleeper(1); // Nominatim public-instance policy: max 1 request/second
            }

            $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            if ($hotelId === '') {
                continue;
            }

            try {
                $street = $client->reverse(
                    TypeCoerce::toFloat($hotel['latitude'] ?? 0),
                    TypeCoerce::toFloat($hotel['longitude'] ?? 0),
                );
            } catch (GeocodeTransportException $e) {
                $aborted = true;
                $abortError = $e->getMessage();
                $this->output("ABORT: {$abortError} — remaining hotels stay unstamped for the next run.");
                break;
            }

            // Stamp even when no street was found so the hotel is not
            // retried every run; a NULL street keeps the display on the
            // city/region line.
            if ($street === null) {
                db_query(
                    'UPDATE ?:novoton_hotels SET street_address = NULL, geocoded_at = NOW() WHERE hotel_id = ?s',
                    $hotelId,
                );
                $noStreet++;
            } else {
                db_query(
                    'UPDATE ?:novoton_hotels SET street_address = ?s, geocoded_at = NOW() WHERE hotel_id = ?s',
                    $street,
                    $hotelId,
                );
                $withStreet++;
            }

            $label = TypeCoerce::toString($hotel['hotel_name'] ?? '');
            $this->output("  {$hotelId} | " . ($label !== '' ? $label : '(unnamed)') . ' -> ' . ($street ?? '(no street found)'));
        }

        $remaining = $this->countCandidates();

        $this->output('');
        $this->output("Done: {$withStreet} with street, {$noStreet} without. Remaining backlog: {$remaining}.");

        $stats = [
            'geocoded' => $withStreet,
            'no_street' => $noStreet,
            'remaining' => $remaining,
            'aborted' => $aborted,
        ];
        $this->logComplete('geocode_addresses', $stats);
        $this->logToSyncTable('geocode_addresses', $withStreet + $noStreet, $aborted ? 1 : 0);

        if ($aborted) {
            return ['success' => false, 'error' => $abortError, 'stats' => $stats];
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * @return array<string, mixed>
     */
    private function printStatus(): array
    {
        $pending = $this->countCandidates();
        $attempted = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE geocoded_at IS NOT NULL',
        ));
        $withStreet = TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotels WHERE street_address IS NOT NULL AND street_address != ''",
        ));
        $noCoords = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE latitude IS NULL OR longitude IS NULL OR latitude = 0 OR longitude = 0',
        ));

        $this->output('Geocoding status:');
        $this->output("  Pending (have coordinates, not yet attempted): {$pending}");
        $this->output("  Attempted: {$attempted} (streets found: {$withStreet})");
        $this->output("  Without usable coordinates (run mode=hotel_list first): {$noCoords}");

        return [
            'success' => true,
            'stats' => [
                'pending' => $pending,
                'attempted' => $attempted,
                'with_street' => $withStreet,
                'no_coordinates' => $noCoords,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getCandidates(int $limit): array
    {
        return TypeCoerce::toRowList(db_get_array(
            'SELECT hotel_id, hotel_name, latitude, longitude FROM ?:novoton_hotels WHERE '
                . self::ELIGIBLE_WHERE . ' ORDER BY hotel_id LIMIT ?i',
            $limit,
        ));
    }

    private function countCandidates(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE ' . self::ELIGIBLE_WHERE,
        ));
    }
}
