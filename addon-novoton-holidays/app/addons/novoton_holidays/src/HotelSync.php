<?php
declare(strict_types=1);
/**
 * Novoton Hotel Synchronization Class (V3 Architecture)
 * Path: app/addons/novoton_holidays/src/HotelSync.php
 *
 * Sync Flow:
 * 1. hotel_list API → Get all hotels for selected countries
 * 2. For each hotel: getHotelInfo API → Store in novoton_hotels
 * 3. For each package: getPriceInfo API → Store in novoton_hotel_packages
 */

namespace Tygh\Addons\NovotonHolidays;

use Tygh\Addons\NovotonHolidays\Api\Contracts\NovotonApiKitInterface;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\SyncException;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;
use Tygh\Addons\NovotonHolidays\Api\AdultOnlyDetector;
use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Helpers\OutputWriterTrait;
use Tygh\Addons\NovotonHolidays\Services\Container;

class HotelSync
{
    use OutputWriterTrait;

    private NovotonApiKitInterface $api;
    private AdultOnlyDetector $adultOnlyDetector;
    private PropertyTypeDetector $propertyTypeDetector;
    private array $selectedCountries;
    private array $stats;

    /**
     * Constructor — API dependency must be injected explicitly.
     *
     * Accepts the narrow NovotonApiKitInterface so the sync class can
     * only reach the five domain sub-clients (hotels, pricing, ...)
     * and never falls back to deprecated facade methods. Concrete
     * NovotonApi still works because it implements the kit interface.
     */
    public function __construct(NovotonApiKitInterface $api)
    {
        $this->api = $api;
        $this->adultOnlyDetector = new AdultOnlyDetector();
        $this->propertyTypeDetector = new PropertyTypeDetector();
        $this->selectedCountries = ConfigProvider::getSelectedCountries();

        $this->stats = [
            'hotels_processed' => 0,
            'hotels_updated' => 0,
            'hotels_failed' => 0,
            'packages_processed' => 0,
            'packages_updated' => 0,
            'packages_failed' => 0,
            'errors' => []
        ];
    }

    /**
     * Parse star rating from hotel_type string
     * Common formats: "4*", "3* Sup", "5*", "Apart", "4* Sup"
     *
     * @param string $hotelType Hotel type from API
     * @return int|null Star rating 1-5 or null
     */
    private function parseStarRating(string $hotelType): ?int
    {
        if (empty($hotelType)) {
            return null;
        }

        // Match number followed by star
        if (preg_match('/^(\d)/', $hotelType, $matches)) {
            $stars = (int)$matches[1];
            if ($stars >= 1 && $stars <= 5) {
                return $stars;
            }
        }

        return null;
    }

    /**
     * Step 1: Sync hotel list from API
     * Calls hotel_list for each selected country
     * Optimized: Uses batch INSERT ON DUPLICATE KEY UPDATE
     *
     * @param string|null $country Specific country or null for all selected
     * @return array Stats
     */
    public function syncHotelList(?string $country = null): array
    {
        $countries = $country ? [$country] : $this->selectedCountries;

        foreach ($countries as $countryName) {
            $this->log("Fetching hotel_list for country: {$countryName}");

            try {
                $hotelList = $this->api->hotels()->getHotelList($countryName);

                if (!$hotelList || !isset($hotelList->hotelinfo)) {
                    $this->stats['errors'][] = "No hotels found for {$countryName}";
                    continue;
                }

                // SimpleXML's child accessor already iterates all sibling
                // <hotelinfo> elements — no need to normalise for scalar.
                $hotels = $hotelList->hotelinfo;

                // Collect hotel data for batch insert
                $batchData = [];
                $batchSize = 100; // Process in batches of 100

                foreach ($hotels as $hotelInfo) {
                    $hotelId = (string)$hotelInfo->IdHotel;
                    $hotelName = (string)$hotelInfo->Hotel;
                    $city = (string)($hotelInfo->City ?? '');
                    $hotelType = (string)($hotelInfo->HotelType ?? '');

                    // Parse star rating from hotel type
                    $starRating = $this->parseStarRating($hotelType);

                    // Detect property type from hotel name (Pass 1 only — no packages/rooms yet)
                    $propertyType = $this->propertyTypeDetector->detect($hotelName);

                    // Detect adults-only from hotel name
                    $isAdultsOnly = $this->adultOnlyDetector->detect($hotelName);

                    $this->stats['hotels_processed']++;

                    $batchData[] = [
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotelName,
                        'city' => $city,
                        'country' => $countryName,
                        'hotel_type' => $hotelType,
                        'star_rating' => $starRating,
                        'property_type' => $propertyType,
                        'is_adults_only' => $isAdultsOnly ? 'Y' : 'N',
                    ];

                    // Execute batch when full
                    if (count($batchData) >= $batchSize) {
                        $this->executeBatchHotelUpsert($batchData);
                        $batchData = [];
                    }
                }

                // Execute remaining batch
                if (!empty($batchData)) {
                    $this->executeBatchHotelUpsert($batchData);
                }

                $this->log("Processed " . count($hotels) . " hotels for {$countryName}");

            } catch (SyncException $e) {
                $this->stats['errors'][] = $e->getMessage();
                $this->stats['hotels_failed']++;
            } catch (ApiException $e) {
                $this->stats['errors'][] = "API error fetching hotel_list for {$countryName} (HTTP {$e->getHttpCode()}): " . $e->getMessage();
                $this->stats['hotels_failed']++;
            } catch (XmlParsingException $e) {
                $this->stats['errors'][] = "XML parsing error for hotel_list {$countryName}: " . $e->getMessage();
                $this->stats['hotels_failed']++;
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Unexpected error fetching hotel_list for {$countryName}: " . $e->getMessage();
                $this->stats['hotels_failed']++;
            }

            // Small delay between countries
            usleep(Constants::API_DELAY_BACKOFF);
        }

        return $this->stats;
    }

    /**
     * Execute batch INSERT ON DUPLICATE KEY UPDATE for hotels
     *
     * @param array<string, mixed> $batchData Array of hotel data arrays
     */
    private function executeBatchHotelUpsert(array $batchData): void
    {
        if (empty($batchData)) {
            return;
        }

        $values = [];
        foreach ($batchData as $hotel) {
            $star = $hotel['star_rating'];
            $starSql = ($star !== null) ? db_quote("?i", $star) : "NULL";
            $values[] = db_quote(
                "(?s, ?s, ?s, ?s, ?s, ",
                $hotel['hotel_id'],
                $hotel['hotel_name'],
                $hotel['city'],
                $hotel['country'],
                $hotel['hotel_type']
            ) . $starSql . db_quote(", ?s, ?s, NOW(), NOW())",
                $hotel['property_type'] ?? 'hotel',
                $hotel['is_adults_only'] ?? 'N'
            );
        }

        $sql = "INSERT INTO ?:novoton_hotels
                (hotel_id, hotel_name, city, country, hotel_type, star_rating, property_type, is_adults_only, hotel_list_synced_at, created_at)
                VALUES " . implode(', ', $values) . " AS new_row
                ON DUPLICATE KEY UPDATE
                hotel_name = new_row.hotel_name,
                city = new_row.city,
                country = new_row.country,
                hotel_type = new_row.hotel_type,
                star_rating = new_row.star_rating,
                property_type = new_row.property_type,
                is_adults_only = new_row.is_adults_only,
                hotel_list_synced_at = NOW()";

        db_query($sql);

        $this->stats['hotels_updated'] += count($batchData);
    }

    /**
     * Step 2: Sync hotel info for specific hotels
     * Calls getHotelInfo and stores full response in novoton_hotels
     *
     * @param array|null $hotelIds Specific hotel IDs or null for all
     * @param int $limit Max hotels to process (0 = unlimited)
     * @return array Stats
     */
    public function syncHotelInfo(?array $hotelIds = null, int $limit = 0): array
    {
        if ($hotelIds === null) {
            // Get hotels that need hotelinfo sync
            $query = "SELECT hotel_id FROM ?:novoton_hotels
                      WHERE hotelinfo_synced_at IS NULL
                         OR hotelinfo_synced_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                      ORDER BY hotelinfo_synced_at ASC";

            if ($limit > 0) {
                $query .= " LIMIT " . (int)$limit;
            }

            $hotelIds = db_get_fields($query);
        }

        // Pre-fetch hotel names to avoid N+1 query inside the loop
        $hotelNames = [];
        if (!empty($hotelIds)) {
            $hotelNames = db_get_hash_single_array(
                "SELECT hotel_id, hotel_name FROM ?:novoton_hotels WHERE hotel_id IN (?a)",
                ['hotel_id', 'hotel_name'],
                $hotelIds
            );
        }

        $this->log("Syncing hotelinfo for " . count($hotelIds) . " hotels");

        foreach ($hotelIds as $hotelId) {
            $this->stats['hotels_processed']++;

            try {
                $hotelInfo = $this->api->hotels()->getHotelInfo($hotelId);

                if (!$hotelInfo) {
                    $this->stats['errors'][] = "No hotelinfo for hotel {$hotelId}";
                    $this->stats['hotels_failed']++;
                    continue;
                }

                // Convert to JSON for storage
                $hotelDataJson = json_encode($hotelInfo);
                if ($hotelDataJson === false) {
                    $this->stats['errors'][] = "json_encode failed for hotelinfo {$hotelId}: " . json_last_error_msg();
                    $this->stats['hotels_failed']++;
                    continue;
                }

                // Extract packages count
                $packagesCount = 0;
                if (isset($hotelInfo->packages)) {
                    $packages = $hotelInfo->packages;
                    if (isset($packages->IdCont)) {
                        $packagesCount = 1;
                    } else {
                        $packagesCount = count($packages);
                    }
                }

                // Extract additional data
                $latitude = (string)($hotelInfo->Latitude ?? '');
                $longitude = (string)($hotelInfo->Longitude ?? '');
                $region = (string)($hotelInfo->Region ?? '');

                // Re-detect property type with full cascade (Pass 1-3)
                $hotelName = (string) ($hotelNames[$hotelId] ?? '');
                $packageNames = [];
                $roomNames = [];

                // Collect package names for Pass 2
                if (isset($hotelInfo->packages)) {
                    $pkgs = $hotelInfo->packages;
                    if (isset($pkgs->IdCont)) {
                        $pkgs = [$pkgs];
                    }
                    foreach ($pkgs as $pkg) {
                        $pn = (string)($pkg->PackageName ?? '');
                        if ($pn !== '') {
                            $packageNames[] = $pn;
                        }
                    }
                }

                // Collect room names for Pass 3
                if (isset($hotelInfo->rooms)) {
                    $rooms = $hotelInfo->rooms;
                    if (isset($rooms->IdRoom)) {
                        $rooms = [$rooms];
                    }
                    foreach ($rooms as $room) {
                        $rn = (string)($room->IdRoom ?? '');
                        if ($rn !== '') {
                            $roomNames[] = $rn;
                        }
                        $rt = (string)($room->Type ?? '');
                        if ($rt !== '' && $rt !== $rn) {
                            $roomNames[] = $rt;
                        }
                    }
                }

                $propertyType = $this->propertyTypeDetector->detect($hotelName, $packageNames, $roomNames);

                // Wrap hotel + package updates in a transaction for atomicity
                db_query("START TRANSACTION");
                try {
                    // Update hotel record (V3: hotel_data stores hotelinfo JSON)
                    db_query(
                        "UPDATE ?:novoton_hotels SET
                         hotel_data = ?s,
                         latitude = ?s,
                         longitude = ?s,
                         region = ?s,
                         packages_count = ?i,
                         property_type = ?s,
                         hotelinfo_synced_at = NOW()
                         WHERE hotel_id = ?s",
                        $hotelDataJson,
                        $latitude,
                        $longitude,
                        $region,
                        $packagesCount,
                        $propertyType,
                        $hotelId
                    );

                    // Sync packages for this hotel
                    $this->syncPackagesForHotel($hotelId, $hotelInfo);
                    db_query("COMMIT");
                } catch (\Exception $txe) {
                    db_query("ROLLBACK");
                    throw $txe;
                }

                $this->stats['hotels_updated']++;
                $this->log("Updated hotelinfo for hotel {$hotelId}");

            } catch (SyncException $e) {
                $this->stats['errors'][] = $e->getMessage();
                $this->stats['hotels_failed']++;
            } catch (ApiException $e) {
                $this->stats['errors'][] = "API error syncing hotelinfo for {$hotelId} (HTTP {$e->getHttpCode()}): " . $e->getMessage();
                $this->stats['hotels_failed']++;
            } catch (XmlParsingException $e) {
                $this->stats['errors'][] = "XML parsing error for hotelinfo {$hotelId}: " . $e->getMessage();
                $this->stats['hotels_failed']++;
            } catch (\Throwable $e) {
                $this->stats['errors'][] = "Unexpected error syncing hotelinfo for {$hotelId}: " . $e->getMessage();
                $this->stats['hotels_failed']++;
            }

            // Delay between API calls
            usleep(Constants::API_DELAY_HEAVY);
        }

        return $this->stats;
    }

    /**
     * Step 3: Sync packages and priceinfo for a hotel
     * Called after getHotelInfo, extracts packages and calls getPriceInfo for each
     *
     * @param string $hotelId Hotel ID
     * @param \SimpleXMLElement $hotelInfo Hotel info from API
     * @return int Number of packages synced
     */
    private function syncPackagesForHotel(string $hotelId, \SimpleXMLElement $hotelInfo): int
    {
        if (!isset($hotelInfo->packages)) {
            return 0;
        }

        $packages = $hotelInfo->packages;

        // Handle single package vs multiple
        if (isset($packages->IdCont)) {
            $packages = [$packages];
        }

        $synced = 0;
        $batchUpsertData = [];

        foreach ($packages as $package) {
            $packageId = (string)$package->IdCont;
            $packageName = (string)$package->PackageName;

            if (empty($packageId) || empty($packageName)) {
                continue;
            }

            $this->stats['packages_processed']++;

            try {
                // Get priceinfo for this package
                $priceInfo = $this->api->pricing()->getPriceInfo($hotelId, $packageName);

                $priceInfoJson = null;

                if ($priceInfo) {
                    $priceInfoJson = json_encode($priceInfo);
                    if ($priceInfoJson === false) {
                        $priceInfoJson = null;
                    }
                }

                // Collect for batch upsert instead of individual query
                $batchUpsertData[] = [
                    'hotel_id' => $hotelId,
                    'package_id' => $packageId,
                    'package_name' => $packageName,
                    'priceinfo_data' => $priceInfoJson,
                ];

                $this->stats['packages_updated']++;
                $synced++;

            } catch (SyncException $e) {
                $this->stats['errors'][] = $e->getMessage();
                $this->stats['packages_failed']++;
            } catch (ApiException $e) {
                $this->stats['errors'][] = "API error for package {$hotelId}/{$packageId} (HTTP {$e->getHttpCode()}): " . $e->getMessage();
                $this->stats['packages_failed']++;
            } catch (XmlParsingException $e) {
                $this->stats['errors'][] = "XML parsing error for package {$hotelId}/{$packageId}: " . $e->getMessage();
                $this->stats['packages_failed']++;
            } catch (\Throwable $e) {
                $syncEx = SyncException::packageSyncFailed($hotelId, $packageId, $e->getMessage(), $e);
                $this->stats['errors'][] = $syncEx->getMessage();
                $this->stats['packages_failed']++;
            }

            // Small delay between priceinfo calls
            usleep(Constants::API_DELAY_MODERATE);
        }

        // Batch upsert all collected packages in a single query
        if (!empty($batchUpsertData)) {
            $this->executeBatchPackageUpsert($batchUpsertData);
        }

        // Update packages_count (has_room_price is set exclusively by room_price check)
        db_query(
            "UPDATE ?:novoton_hotels SET packages_count = ?i WHERE hotel_id = ?s",
            $synced,
            $hotelId
        );

        return $synced;
    }

    /**
     * Execute batch INSERT ON DUPLICATE KEY UPDATE for packages
     *
     * @param array<string, mixed> $batchData Array of package data arrays
     */
    private function executeBatchPackageUpsert(array $batchData): void
    {
        if (empty($batchData)) {
            return;
        }

        $values = [];
        foreach ($batchData as $pkg) {
            $values[] = db_quote(
                "(?s, ?s, ?s, ?s, 'Y', NOW())",
                $pkg['hotel_id'],
                $pkg['package_id'],
                $pkg['package_name'],
                $pkg['priceinfo_data']
            );
        }

        $sql = "INSERT INTO ?:novoton_hotel_packages
                (hotel_id, package_id, package_name, priceinfo_data, needs_price_compute, synced_at)
                VALUES " . implode(', ', $values) . " AS new_row
                ON DUPLICATE KEY UPDATE
                package_name = new_row.package_name,
                priceinfo_data = new_row.priceinfo_data,
                needs_price_compute = 'Y',
                synced_at = NOW()";

        db_query($sql);
    }

    /**
     * Full sync: hotel_list → hotelinfo → priceinfo for all packages
     *
     * @param string|null $country Specific country or null for all
     * @param int $hotelLimit Max hotels for hotelinfo sync (0 = unlimited)
     * @return array Stats
     */
    public function fullSync(?string $country = null, int $hotelLimit = 0): array
    {
        $startTime = time();

        $this->log("Starting full sync...");

        // Step 1: Sync hotel list
        $this->log("Step 1: Syncing hotel list...");
        $this->syncHotelList($country);

        // Step 2: Sync hotelinfo for hotels
        $this->log("Step 2: Syncing hotelinfo...");
        $this->syncHotelInfo(null, $hotelLimit);

        $this->stats['duration'] = time() - $startTime;

        $this->log("Full sync completed in {$this->stats['duration']} seconds");
        $this->log("Hotels: {$this->stats['hotels_updated']} updated, {$this->stats['hotels_failed']} failed");
        $this->log("Packages: {$this->stats['packages_updated']} updated, {$this->stats['packages_failed']} failed");

        // Log to database
        $this->saveLog();

        return $this->stats;
    }

    /**
     * Sync only priceinfo for packages (faster refresh)
     *
     * @param int $limit Max packages to sync (0 = unlimited)
     * @return array Stats
     */
    public function syncPriceInfoOnly(int $limit = 0): array
    {
        $startTime = time();

        // Get packages that need priceinfo sync
        $query = "SELECT hp.hotel_id, hp.package_id, hp.package_name
                  FROM ?:novoton_hotel_packages hp
                  WHERE hp.synced_at IS NULL
                     OR hp.synced_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                  ORDER BY hp.synced_at ASC";

        if ($limit > 0) {
            $query .= " LIMIT " . (int)$limit;
        }

        $packages = db_get_array($query);

        $this->log("Syncing priceinfo for " . count($packages) . " packages");

        foreach ($packages as $pkg) {
            $this->stats['packages_processed']++;

            try {
                $priceInfo = $this->api->pricing()->getPriceInfo($pkg['hotel_id'], $pkg['package_name']);

                if (!$priceInfo) {
                    $this->stats['packages_failed']++;
                    continue;
                }

                $priceInfoJson = json_encode($priceInfo);
                if ($priceInfoJson === false) {
                    $priceInfoJson = null;
                }

                // Store raw data and flag for recomputation by compute_prices cron
                db_query(
                    "UPDATE ?:novoton_hotel_packages SET
                     priceinfo_data = ?s,
                     needs_price_compute = 'Y',
                     synced_at = NOW()
                     WHERE hotel_id = ?s AND package_id = ?s",
                    $priceInfoJson,
                    $pkg['hotel_id'],
                    $pkg['package_id']
                );

                $this->stats['packages_updated']++;

            } catch (SyncException $e) {
                $this->stats['errors'][] = $e->getMessage();
                $this->stats['packages_failed']++;
            } catch (ApiException $e) {
                $this->stats['errors'][] = "API error refreshing {$pkg['hotel_id']}/{$pkg['package_id']} (HTTP {$e->getHttpCode()}): " . $e->getMessage();
                $this->stats['packages_failed']++;
            } catch (XmlParsingException $e) {
                $this->stats['errors'][] = "XML parsing error refreshing {$pkg['hotel_id']}/{$pkg['package_id']}: " . $e->getMessage();
                $this->stats['packages_failed']++;
            } catch (\Throwable $e) {
                $syncEx = SyncException::packageSyncFailed($pkg['hotel_id'], $pkg['package_id'], $e->getMessage(), $e);
                $this->stats['errors'][] = $syncEx->getMessage();
                $this->stats['packages_failed']++;
            }

            usleep(Constants::API_DELAY_MODERATE);
        }

        $this->stats['duration'] = time() - $startTime;

        return $this->stats;
    }

    /**
     * Get sync stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Log message to output and event log.
     */
    private function log(string $message): void
    {
        if (defined('CONSOLE') && CONSOLE) {
            $this->output("[" . date('Y-m-d H:i:s') . "] {$message}");
        }

        fn_log_event('novoton_holidays', 'sync', [
            'message' => $message
        ]);
    }

    /**
     * Save sync log to database via repository
     */
    private function saveLog(): void
    {
        $syncRepo = Container::getInstance()->syncLogRepository();
        $syncRepo->create('hotels', [
            'total'    => $this->stats['hotels_processed'] + $this->stats['packages_processed'],
            'updated'  => $this->stats['hotels_updated'] + $this->stats['packages_updated'],
            'failed'   => $this->stats['hotels_failed'] + $this->stats['packages_failed'],
            'duration' => $this->stats['duration'] ?? 0,
            'status'   => 'completed',
        ]);
    }
}
