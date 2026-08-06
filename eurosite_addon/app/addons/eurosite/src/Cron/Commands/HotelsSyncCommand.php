<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Cron\Commands;

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * mode `hotels` — getOwnHotelsRequest per target city → ?:eurosite_hotels.
 *
 * Target cities = every whitelist-allowed city of each target country, union
 * the country's own-offer cities (is_own='Y'). Each hotel row stores its own
 * Touropcode ("LA" live) — the code search/booking payloads must use.
 * `&city=CODE` narrows to one city ad hoc.
 */
final class HotelsSyncCommand extends AbstractSyncCommand
{
    #[\Override]
    public static function getModes(): array
    {
        return ['hotels'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Sync own hotels + rooms for whitelisted/own-offer cities (getOwnHotelsRequest; &city=CODE for one)';
    }

    #[\Override]
    public function execute(array $params = []): array
    {
        return $this->runLogged('hotels', function () use ($params): array {
            $cities = $this->targetCityCodes(strtoupper(trim(TypeCoerce::toString($params['city'] ?? ''))));

            $api = Container::getApi();
            $hotelRepo = Container::hotels();
            $cityRepo = Container::cities();
            $total = 0;
            $synced = 0;
            $errors = [];
            foreach ($cities as $cityCode) {
                $country = '';
                $cityRow = $cityRepo->findByCode($cityCode);
                if ($cityRow !== null) {
                    $country = TypeCoerce::toString($cityRow['country_code'] ?? '');
                }
                $this->trySyncItem(function () use ($api, $hotelRepo, $cityCode, $country, &$total, &$synced): void {
                    $hotels = $api->getOwnHotels($cityCode);
                    $total += count($hotels);
                    $synced += $hotelRepo->upsertBatch($hotels, $country);
                    if ($hotels !== []) {
                        $this->output("  {$cityCode}: " . count($hotels) . ' hotels');
                    }
                }, "city {$cityCode}", $errors);
            }

            return [
                'total' => $total,
                'synced' => $synced,
                'failed' => count($errors),
                'error' => implode('; ', array_slice($errors, 0, 5)),
            ];
        });
    }

    /**
     * @return list<string>
     */
    private function targetCityCodes(string $only): array
    {
        if ($only !== '') {
            return [$only];
        }
        $whitelist = Container::whitelist();
        $cityRepo = Container::cities();
        $codes = [];
        foreach ($this->targetCountryCodes() as $country) {
            foreach ($whitelist->getAllowedCityCodes($country) as $code) {
                $codes[$code] = true;
            }
            foreach ($cityRepo->getByCountry($country, true) as $row) {
                $code = TypeCoerce::toString($row['city_code'] ?? '');
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        return array_keys($codes);
    }
}
