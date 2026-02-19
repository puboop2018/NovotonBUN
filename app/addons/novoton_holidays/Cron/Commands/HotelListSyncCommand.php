<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

class HotelListSyncCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['hotel_list'];
    }

    public static function getDescription(): string
    {
        return 'Sync hotel list from API (upsert into novoton_hotels)';
    }

    public function execute(): array
    {
        $dbHelper = Container::getInstance()->databaseHelper();
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

            $hotels = $this->api->getHotelList($country);

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

                $hotelBatch[] = [
                    'hotel_id' => $hotel_id,
                    'hotel_name' => (string)($hotel->Hotel ?? ''),
                    'city' => (string)($hotel->City ?? ''),
                    'region' => (string)($hotel->Region ?? ''),
                    'country' => (string)($hotel->Country ?? $country),
                    'hotel_type' => (string)($hotel->HotelType ?? ''),
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

        $stats = ['total' => $total, 'added' => $added, 'updated' => $updated];
        $this->logComplete('hotel_list', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}
