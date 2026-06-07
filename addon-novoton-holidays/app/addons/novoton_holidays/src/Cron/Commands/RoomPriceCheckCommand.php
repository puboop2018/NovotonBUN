<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;

class RoomPriceCheckCommand extends AbstractCronCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['room_price'];
    }

    public static function getDescription(): string
    {
        return 'Check which hotels have active room_price data';
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $dbHelper = Container::getInstance()->databaseHelper();
        // Remember whether check_in was supplied so we can warn the operator: a
        // defaulted date can land out of season and return 0 priced hotels, which
        // is easily mistaken for "no hotel has prices".
        $check_in_param = $this->getParam('check_in', '');
        $check_in = is_string($check_in_param) ? $check_in_param : '';
        $datesDefaulted = $check_in === '';
        if ($datesDefaulted) {
            $check_in = date('Y-m-d', (int) strtotime('+30 days'));
        }
        $nights = (int)$this->getParam('nights', 7);
        $limit = (int)$this->getParam('limit', 500);
        $country = strtoupper($this->getParam('country', ''));
        $check_out = date('Y-m-d', (int) strtotime($check_in . ' + ' . $nights . ' days'));

        $this->output('Checking hotels with active prices...');
        $this->output("Check-in: {$check_in}, Check-out: {$check_out}, Nights: {$nights}, Limit: {$limit}");
        if ($datesDefaulted) {
            $this->output('  NOTE: no &check_in supplied — using default (+30 days). Out-of-season');
            $this->output('        dates can return 0 priced hotels. Pass &check_in=YYYY-MM-DD to');
            $this->output('        test the dates customers actually search.');
        }
        if ($country) {
            $this->output("Country: {$country}");
        }
        $this->output('');

        $conditions = $country ? ['country' => $country] : [];
        $hotels = $dbHelper->getHotelsForSync($conditions, $limit, ['hotel_id', 'hotel_name', 'country']);

        $withPricesIds = [];
        $withoutPricesIds = [];
        $withPricesCount = 0;
        $withoutPricesCount = 0;
        $invalidCount = 0;

        foreach ($hotels as $idx => $hotel) {
            // Mirror the admin "Check Prices" call (novoton_prices.php): bypass the
            // price cache so we always hit the live API, and do NOT pass
            // 'children' => 0 (an int lands in the cache-key params as 0 instead of
            // [], diverging from the admin's key and reading stale/empty entries).
            $params = [
                'hotel_id' => $hotel['hotel_id'],
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => 2,
                'nocache' => true,
            ];

            $has_prices = false;
            $invalid = false;
            try {
                $response = $this->api->pricing()->getRoomPrice($params);

                if ($response instanceof \SimpleXMLElement) {
                    // Presence check only — mirrors PricingApiClient::getRoomPrice() (line 244)
                    // and the admin check_prices_hotel. The cron only sets has_room_price Y/N;
                    // it does not store or return a price amount.
                    $has_prices = !empty($response->xpath('//Price'));
                } else {
                    // getRoomPrice() returned false — XML parse/API error, distinct
                    // from a valid response that simply carries no <Price> nodes.
                    $invalid = true;
                }
            } catch (\Exception $e) {
                // API failure for this hotel — treat as no price
                $invalid = true;
            }

            if ($has_prices) {
                $withPricesIds[] = $hotel['hotel_id'];
                $this->output("NVT-{$hotel['hotel_id']} | {$hotel['hotel_name']} - has prices");
            } else {
                $withoutPricesIds[] = $hotel['hotel_id'];
                if ($invalid) {
                    $invalidCount++;
                }
            }

            // Batch update every 25 hotels
            if (($idx + 1) % 25 === 0) {
                $dbHelper->batchUpdateHasRoomPriceFlag($withPricesIds, $withoutPricesIds);
                $withPricesCount += count($withPricesIds);
                $withoutPricesCount += count($withoutPricesIds);
                $withPricesIds = [];
                $withoutPricesIds = [];
            }

            usleep(ConfigProvider::API_DELAY_MS * 1000);
        }

        // Final batch
        if (!empty($withPricesIds) || !empty($withoutPricesIds)) {
            $dbHelper->batchUpdateHasRoomPriceFlag($withPricesIds, $withoutPricesIds);
            $withPricesCount += count($withPricesIds);
            $withoutPricesCount += count($withoutPricesIds);
        }

        $this->output('');
        $this->output("Hotels WITH prices: {$withPricesCount}");
        $this->output("Hotels WITHOUT prices: {$withoutPricesCount}");
        $this->output("  of which invalid API response: {$invalidCount}");
        $this->output('Total checked: ' . ($withPricesCount + $withoutPricesCount));

        $stats = [
            'with_prices' => $withPricesCount,
            'without_prices' => $withoutPricesCount,
            'invalid' => $invalidCount,
        ];
        $this->logComplete('room_price', $stats);
        return ['success' => true, 'stats' => $stats];
    }
}
