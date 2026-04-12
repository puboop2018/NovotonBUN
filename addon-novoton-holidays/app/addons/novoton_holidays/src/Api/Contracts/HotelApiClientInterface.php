<?php
declare(strict_types=1);
/**
 * Contract for the Novoton Hotels API sub-client.
 *
 * Covers list/info/descriptions/images/facilities endpoints of the Novoton API.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Api\Contracts;

interface HotelApiClientInterface
{
    /** 1. hotel_list — List with hotel names */
    public function getHotelList(string $country = '%', string $city = '%', string $hotel = '%', string $hotelType = '%'): \SimpleXMLElement;

    /** 2. hotelinfo — Information for hotel services */
    public function getHotelInfo(string $hotelId, string $lang = 'UK'): \SimpleXMLElement;

    /**
     * 2b. hotelinfo batch — fetch multiple hotels in parallel via curl_multi.
     *
     * @param string[] $hotelIds
     * @return array<string, \SimpleXMLElement|false>
     */
    public function getHotelInfoBatch(array $hotelIds, string $lang = 'UK', int $concurrency = 5): array;

    /** 5. hotel_description — Description of hotel */
    public function getHotelDescription(string $hotelId, string $lang = 'UK', bool $includePackage = false): \SimpleXMLElement;

    /** 6. hotel_images — Pictures of hotel */
    public function getHotelImages(string $hotelId, string $lang = 'UK'): \SimpleXMLElement;

    /** 27. hotel_facilities — Hotel facilities */
    public function getHotelFacilities(string $hotelId): \SimpleXMLElement;

    /** 26. list_facilities — List all facilities */
    public function listFacilities(): \SimpleXMLElement;
}
