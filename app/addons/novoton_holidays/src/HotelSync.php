<?php
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

use Tygh\Addons\NovotonHolidays\Services\ConfigService;
use Tygh\Addons\NovotonHolidays\Exceptions\SyncException;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class HotelSync
{
    private $api;
    private $selectedCountries;
    private $productPrefixes;
    private $stats;

    public function __construct()
    {
        $this->api = new NovotonApi();
        $this->selectedCountries = ConfigService::getSelectedCountries();
        $this->productPrefixes = ConfigService::getProductCodePrefixes();

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
                $hotelList = $this->api->getHotelList($countryName);

                if (!$hotelList || !isset($hotelList->hotelinfo)) {
                    $this->stats['errors'][] = "No hotels found for {$countryName}";
                    continue;
                }

                // Handle single hotel vs multiple
                $hotels = $hotelList->hotelinfo;
                if (!is_array($hotels) && !($hotels instanceof \Traversable)) {
                    $hotels = [$hotels];
                }

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

                    $this->stats['hotels_processed']++;

                    $batchData[] = [
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotelName,
                        'city' => $city,
                        'country' => $countryName,
                        'hotel_type' => $hotelType,
                        'star_rating' => $starRating
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
            } catch (\Exception $e) {
                $this->stats['errors'][] = "Unexpected error fetching hotel_list for {$countryName}: " . $e->getMessage();
                $this->stats['hotels_failed']++;
            }

            // Small delay between countries
            usleep(500000);
        }

        return $this->stats;
    }

    /**
     * Execute batch INSERT ON DUPLICATE KEY UPDATE for hotels
     *
     * @param array $batchData Array of hotel data arrays
     */
    private function executeBatchHotelUpsert(array $batchData)
    {
        if (empty($batchData)) {
            return;
        }

        $values = [];
        foreach ($batchData as $hotel) {
            $values[] = db_quote(
                "(?s, ?s, ?s, ?s, ?s, ?i, NOW(), NOW())",
                $hotel['hotel_id'],
                $hotel['hotel_name'],
                $hotel['city'],
                $hotel['country'],
                $hotel['hotel_type'],
                $hotel['star_rating']
            );
        }

        $sql = "INSERT INTO ?:novoton_hotels
                (hotel_id, hotel_name, city, country, hotel_type, star_rating, hotel_list_synced_at, created_at)
                VALUES " . implode(', ', $values) . "
                ON DUPLICATE KEY UPDATE
                hotel_name = VALUES(hotel_name),
                city = VALUES(city),
                country = VALUES(country),
                hotel_type = VALUES(hotel_type),
                star_rating = VALUES(star_rating),
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

        $this->log("Syncing hotelinfo for " . count($hotelIds) . " hotels");

        foreach ($hotelIds as $hotelId) {
            $this->stats['hotels_processed']++;

            try {
                $hotelInfo = $this->api->getHotelInfo($hotelId);

                if (!$hotelInfo) {
                    $this->stats['errors'][] = "No hotelinfo for hotel {$hotelId}";
                    $this->stats['hotels_failed']++;
                    continue;
                }

                // Convert to JSON for storage
                $hotelDataJson = json_encode($hotelInfo);

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
                         hotelinfo_synced_at = NOW()
                         WHERE hotel_id = ?s",
                        $hotelDataJson,
                        $latitude,
                        $longitude,
                        $region,
                        $packagesCount,
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
            } catch (\Exception $e) {
                $this->stats['errors'][] = "Unexpected error syncing hotelinfo for {$hotelId}: " . $e->getMessage();
                $this->stats['hotels_failed']++;
            }

            // Delay between API calls
            usleep(300000);
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

        foreach ($packages as $package) {
            $packageId = (string)$package->IdCont;
            $packageName = (string)$package->PackageName;

            if (empty($packageId) || empty($packageName)) {
                continue;
            }

            $this->stats['packages_processed']++;

            try {
                // Get priceinfo for this package
                $priceInfo = $this->api->getPriceInfo($hotelId, $packageName);

                $priceInfoJson = null;
                $seasonsCount = 0;
                $hasEarlyBooking = 'N';
                $minPrice = null;

                if ($priceInfo) {
                    $priceInfoJson = json_encode($priceInfo);

                    // Count seasons
                    if (isset($priceInfo->seasons->season)) {
                        $seasons = $priceInfo->seasons->season;
                        if (isset($seasons->IdSeason)) {
                            $seasonsCount = 1;
                        } else {
                            $seasonsCount = count($seasons);
                        }
                    }

                    // Check for early booking
                    if (isset($priceInfo->early_booking) && !empty($priceInfo->early_booking)) {
                        $hasEarlyBooking = 'Y';
                    }

                    // Calculate min price from season_price
                    $minPrice = $this->calculateMinPrice($priceInfo);
                }

                // Upsert package record
                db_query(
                    "INSERT INTO ?:novoton_hotel_packages
                     (hotel_id, package_id, package_name, priceinfo_data, seasons_count, has_early_booking, min_price, synced_at)
                     VALUES (?s, ?s, ?s, ?s, ?i, ?s, ?d, NOW())
                     ON DUPLICATE KEY UPDATE
                     package_name = ?s,
                     priceinfo_data = ?s,
                     seasons_count = ?i,
                     has_early_booking = ?s,
                     min_price = ?d,
                     synced_at = NOW()",
                    $hotelId,
                    $packageId,
                    $packageName,
                    $priceInfoJson,
                    $seasonsCount,
                    $hasEarlyBooking,
                    $minPrice,
                    $packageName,
                    $priceInfoJson,
                    $seasonsCount,
                    $hasEarlyBooking,
                    $minPrice
                );

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
            } catch (\Exception $e) {
                $syncEx = SyncException::packageSyncFailed($hotelId, $packageId, $e->getMessage(), $e);
                $this->stats['errors'][] = $syncEx->getMessage();
                $this->stats['packages_failed']++;
            }

            // Small delay between priceinfo calls
            usleep(200000);
        }

        // Update hotel has_prices flag
        $hasPrices = $synced > 0 ? 'Y' : 'N';
        db_query(
            "UPDATE ?:novoton_hotels SET has_prices = ?s WHERE hotel_id = ?s",
            $hasPrices,
            $hotelId
        );

        return $synced;
    }

    /**
     * Calculate minimum adult price from priceinfo
     *
     * @param \SimpleXMLElement $priceInfo Price info from API
     * @return float|null Minimum price or null
     */
    private function calculateMinPrice($priceInfo)
    {
        $minPrice = null;

        if (!isset($priceInfo->season_price)) {
            return null;
        }

        $seasonPrices = $priceInfo->season_price;

        // Handle single vs multiple
        if (isset($seasonPrices->IdRoom)) {
            $seasonPrices = [$seasonPrices];
        }

        foreach ($seasonPrices as $sp) {
            // Only look at adult prices
            $idAge = (string)($sp->IdAge ?? '');
            if ($idAge !== 'ADULT') {
                continue;
            }

            // Check Price1 through Price20
            for ($i = 1; $i <= 20; $i++) {
                $priceKey = "Price{$i}";
                if (isset($sp->$priceKey)) {
                    $price = (float)$sp->$priceKey;
                    if ($price > 0 && ($minPrice === null || $price < $minPrice)) {
                        $minPrice = $price;
                    }
                }
            }
        }

        return $minPrice;
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
                $priceInfo = $this->api->getPriceInfo($pkg['hotel_id'], $pkg['package_name']);

                if (!$priceInfo) {
                    $this->stats['packages_failed']++;
                    continue;
                }

                $priceInfoJson = json_encode($priceInfo);

                // Count seasons
                $seasonsCount = 0;
                if (isset($priceInfo->seasons->season)) {
                    $seasons = $priceInfo->seasons->season;
                    if (isset($seasons->IdSeason)) {
                        $seasonsCount = 1;
                    } else {
                        $seasonsCount = count($seasons);
                    }
                }

                // Check for early booking
                $hasEarlyBooking = 'N';
                if (isset($priceInfo->early_booking) && !empty($priceInfo->early_booking)) {
                    $hasEarlyBooking = 'Y';
                }

                // Calculate min price
                $minPrice = $this->calculateMinPrice($priceInfo);

                // Update package
                db_query(
                    "UPDATE ?:novoton_hotel_packages SET
                     priceinfo_data = ?s,
                     seasons_count = ?i,
                     has_early_booking = ?s,
                     min_price = ?d,
                     synced_at = NOW()
                     WHERE hotel_id = ?s AND package_id = ?s",
                    $priceInfoJson,
                    $seasonsCount,
                    $hasEarlyBooking,
                    $minPrice,
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
            } catch (\Exception $e) {
                $syncEx = SyncException::packageSyncFailed($pkg['hotel_id'], $pkg['package_id'], $e->getMessage(), $e);
                $this->stats['errors'][] = $syncEx->getMessage();
                $this->stats['packages_failed']++;
            }

            usleep(200000);
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
     * Log message
     */
    private function log(string $message): void
    {
        if (defined('CONSOLE') && CONSOLE) {
            echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
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
        $syncRepo = new \Tygh\Addons\NovotonHolidays\Repository\SyncLogRepository();
        $syncRepo->create('hotels', [
            'total'    => $this->stats['hotels_processed'] + $this->stats['packages_processed'],
            'updated'  => $this->stats['hotels_updated'] + $this->stats['packages_updated'],
            'failed'   => $this->stats['hotels_failed'] + $this->stats['packages_failed'],
            'duration' => $this->stats['duration'] ?? 0,
            'status'   => 'completed',
        ]);
    }
}
