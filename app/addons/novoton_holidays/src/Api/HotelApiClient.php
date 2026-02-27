<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\XmlParsingException;

class HotelApiClient extends ApiClientBase
{
    protected array $noCacheFunctions = [
        Constants::API_FUNCTION_HOTEL_LIST,
        Constants::API_FUNCTION_HOTEL_INFO,
    ];

    /**
     * 1. hotel_list - List with hotel names
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelList(string $country = '%', string $city = '%', string $hotel = '%', string $hotelType = '%'): \SimpleXMLElement
    {
        $country = empty($country) ? '%' : $country;
        $city = empty($city) ? '%' : $city;
        $hotel = empty($hotel) ? '%' : $hotel;
        $hotelType = empty($hotelType) ? '%' : $hotelType;

        $xml = $this->xmlHeader() . '
        <hotel_list>
            <hotelinfo>
                <Country>' . htmlspecialchars($country) . '</Country>
                <City>' . htmlspecialchars($city) . '</City>
                <Hotel>' . htmlspecialchars($hotel) . '</Hotel>
                <HotelType>' . htmlspecialchars($hotelType) . '</HotelType>
            </hotelinfo>
        </hotel_list>';

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_LIST, $xml);
    }

    /**
     * 2. hotelinfo - Information for hotel services
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelInfo(string $hotelId, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <hotelinfo>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotelinfo>';

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_INFO, $xml, $lang);
    }

    /**
     * 2b. hotelinfo batch - fetch multiple hotels in parallel using curl_multi
     *
     * @param array $hotelIds Array of hotel IDs
     * @return array hotel_id => SimpleXMLElement|false
     */
    public function getHotelInfoBatch(array $hotelIds, string $lang = 'UK', int $concurrency = 5): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        $requests = [];
        foreach ($hotelIds as $hotelId) {
            $xml = $this->xmlHeader() . '
                <hotelinfo>
                    <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
                </hotelinfo>';

            $requests[$hotelId] = ['function' => Constants::API_FUNCTION_HOTEL_INFO, 'xml' => $xml, 'lang' => $lang];
        }

        $rawResponses = $this->httpClient->sendBatchRequests($requests, $concurrency);

        $results = [];
        foreach ($rawResponses as $hotelId => $raw) {
            if ($raw === false) {
                $results[$hotelId] = false;
            } else {
                try {
                    $results[$hotelId] = $this->xmlParser->cleanAndParse($raw);
                } catch (XmlParsingException $e) {
                    $results[$hotelId] = false;
                }
            }
        }

        return $results;
    }

    /**
     * 5. hotel_description - Description of hotel
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelDescription(string $hotelId, string $lang = 'UK', bool $includePackage = false): \SimpleXMLElement
    {
        $packageXml = $includePackage ? '<PackageDescription>Yes</PackageDescription>' : '';

        $xml = $this->xmlHeader() . '
        <hotel_description>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
            ' . $packageXml . '
        </hotel_description>';

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_DESCRIPTION, $xml, $lang);
    }

    /**
     * 6. hotel_images - Pictures of hotel
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelImages(string $hotelId, string $lang = 'UK'): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <hotel_images>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotel_images>';

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_IMAGES, $xml, $lang);
    }

    /**
     * 27. hotel_facilities - Hotel facilities
     *
     * @return \SimpleXMLElement|false
     */
    public function getHotelFacilities(string $hotelId): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <hotel_facilities>
            <IdHotel>' . htmlspecialchars($hotelId) . '</IdHotel>
        </hotel_facilities>';

        return $this->callApiAndParse(Constants::API_FUNCTION_HOTEL_FACILITIES, $xml);
    }

    /**
     * 26. list_facilities - List all facilities
     *
     * @return \SimpleXMLElement|false
     */
    public function listFacilities(): \SimpleXMLElement
    {
        $xml = $this->xmlHeader() . '
        <list_facilities>
        </list_facilities>';

        return $this->callApiAndParse(Constants::API_FUNCTION_LIST_FACILITIES, $xml);
    }
}
