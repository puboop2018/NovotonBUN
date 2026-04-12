<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Api\PropertyTypeDetector;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

class HotelListSyncCommand extends AbstractCronCommand
{
    /**
     * @return array<string, mixed>
     */
    public static function getModes(): array
    {
        return ['hotel_list'];
    }

    public static function getDescription(): string
    {
        return 'Sync hotel list from API (upsert into novoton_hotels)';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $dbHelper = Container::getInstance()->databaseHelper();
        $detector = new PropertyTypeDetector();
        $this->output("Syncing hotels from API (hotel_list)...");
        $this->output("");

        $countries = ConfigProvider::getSelectedCountries();
        $this->output("Countries: " . implode(', ', $countries));
        $this->output("");

        $total = 0;
        $added = 0;
        $updated = 0;

        foreach ($countries as $country) {
            $this->output("Fetching {$country}... ", false);

            $hotels = $this->api->hotels()->getHotelList($country);

            if (empty($hotels)) {
                $this->output("0 hotels (or error)");
                continue;
            }

            $count = count($hotels);
            $total += $count;
            $this->output("{$count} hotels");

            $hotelBatch = [];
            foreach ($hotels as $hotel) {
                $hotel_id = (string)($hotel->IdHotel ?? '');
                if (empty($hotel_id)) continue;

                $hotelName = (string)($hotel->Hotel ?? '');
                $hotelType = (string)($hotel->HotelType ?? '');

                $hotelBatch[] = [
                    'hotel_id' => $hotel_id,
                    'hotel_name' => $hotelName,
                    'city' => (string)($hotel->City ?? ''),
                    'region' => (string)($hotel->Region ?? ''),
                    'country' => (string)($hotel->Country ?? $country),
                    'hotel_type' => $hotelType,
                    'star_rating' => self::parseStarRating($hotelType),
                    'property_type' => $detector->detect($hotelName),
                    'latitude' => (string)($hotel->Lat ?? ''),
                    'longitude' => (string)($hotel->Lng ?? ''),
                    'hotel_list_synced_at' => date('Y-m-d H:i:s'),
                ];
            }

            $result = $dbHelper->upsertHotels($hotelBatch);
            $added += $result['inserted'];
            $updated += $result['updated'];

            $this->output("  -> Inserted: {$result['inserted']}, Updated: {$result['updated']}");
        }

        $this->output("");
        $this->output("Total hotels: {$total}");
        $this->output("Synced: " . ($added + $updated) . " (new: {$added})");

        // Ensure Novoton aliases exist in travel_api_alias (idempotent)
        if (function_exists('fn_novoton_holidays_seed_travel_aliases')) {
            fn_novoton_holidays_seed_travel_aliases();
        }

        $stats = ['total' => $total, 'added' => $added, 'updated' => $updated];
        $this->logComplete('hotel_list', $stats);
        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Parse star rating from hotel_type string.
     * Common formats: "4*", "3* Sup", "5*", "Apart"
     *
     * @return int|null Star rating 1-5 or null if not parseable
     */
    private static function parseStarRating(string $hotelType): ?int
    {
        if ($hotelType === '') {
            return null;
        }
        if (preg_match('/^(\d)/', $hotelType, $matches)) {
            $stars = (int) $matches[1];
            return ($stars >= 1 && $stars <= 5) ? $stars : null;
        }
        return null;
    }
}
