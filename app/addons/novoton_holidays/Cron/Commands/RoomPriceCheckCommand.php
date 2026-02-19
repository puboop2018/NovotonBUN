<?php
namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Helpers\Config;
use Tygh\Addons\NovotonHolidays\Helpers\DatabaseHelper;

class RoomPriceCheckCommand extends AbstractCronCommand
{
    public static function getModes(): array
    {
        return ['room_price'];
    }

    public static function getDescription(): string
    {
        return 'Check which hotels have active room_price data';
    }

    public function execute(): array
    {
        $check_in = $this->getParam('check_in', date('Y-m-d', strtotime('+7 days')));
        $nights = (int)$this->getParam('nights', 7);
        $limit = (int)$this->getParam('limit', 500);
        $country = strtoupper($this->getParam('country', ''));
        $check_out = date('Y-m-d', strtotime($check_in . ' + ' . $nights . ' days'));

        $this->output("Checking hotels with active prices...");
        $this->output("Check-in: {$check_in}, Check-out: {$check_out}, Nights: {$nights}, Limit: {$limit}");
        if ($country) $this->output("Country: {$country}");
        $this->output("");

        $conditions = $country ? ['country' => $country] : [];
        $hotels = DatabaseHelper::getHotelsForSync($conditions, $limit, ['hotel_id', 'hotel_name', 'country']);

        $withPricesIds = [];
        $withoutPricesIds = [];
        $withPricesCount = 0;
        $withoutPricesCount = 0;

        foreach ($hotels as $idx => $hotel) {
            $params = [
                'hotel_id' => $hotel['hotel_id'],
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => 2,
                'children' => 0
            ];

            $best_price = 0;
            try {
                $response = $this->api->getRoomPrice($params);

                if ($response instanceof \SimpleXMLElement) {
                    $prices = $response->xpath('//Price');
                    if (!empty($prices)) {
                        foreach ($prices as $p) {
                            $pv = floatval((string)$p);
                            if ($pv > 0 && ($best_price == 0 || $pv < $best_price)) {
                                $best_price = $pv;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // API failure for this hotel — treat as no price
            }

            if ($best_price > 0) {
                $withPricesIds[] = $hotel['hotel_id'];
                $this->output("NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - EUR " . number_format($best_price, 2));
            } else {
                $withoutPricesIds[] = $hotel['hotel_id'];
            }

            // Batch update every 25 hotels
            if (($idx + 1) % 25 == 0) {
                DatabaseHelper::batchUpdateHasPricesFlag($withPricesIds, $withoutPricesIds);
                $withPricesCount += count($withPricesIds);
                $withoutPricesCount += count($withoutPricesIds);
                $withPricesIds = [];
                $withoutPricesIds = [];
            }

            usleep(Config::API_DELAY_MS * 1000);
        }

        // Final batch
        if (!empty($withPricesIds) || !empty($withoutPricesIds)) {
            DatabaseHelper::batchUpdateHasPricesFlag($withPricesIds, $withoutPricesIds);
            $withPricesCount += count($withPricesIds);
            $withoutPricesCount += count($withoutPricesIds);
        }

        $this->output("");
        $this->output("Hotels WITH prices: {$withPricesCount}");
        $this->output("Hotels WITHOUT prices: {$withoutPricesCount}");
        $this->output("Total checked: " . ($withPricesCount + $withoutPricesCount));

        $stats = ['with_prices' => $withPricesCount, 'without_prices' => $withoutPricesCount];
        $this->logComplete('room_price', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}
