<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Constants;

class DestinationApiClient extends ApiClientBase
{
    /**
     * 16. resort_list - Destinations List
     *
     * @return \SimpleXMLElement|false
     */
    public function getResortList(string $country = '', string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <resort_list>
            <Country>' . htmlspecialchars($country) . '</Country>
        </resort_list>';

        return $this->callApiAndParse(Constants::API_FUNCTION_RESORT_LIST, $xml, $lang);
    }

    /**
     * 25. offers_update - Updated/New Offers
     *
     * @return \SimpleXMLElement|false
     */
    public function getOffersUpdate(string $dateTime, string $country = '', string $resort = '', string $hotel = '')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <offers_update>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <DateTime>' . htmlspecialchars($dateTime) . '</DateTime>
            <Country>' . htmlspecialchars($country) . '</Country>
            <Resort>' . htmlspecialchars($resort) . '</Resort>
            <Hotel>' . htmlspecialchars($hotel) . '</Hotel>
        </offers_update>';

        return $this->callApiAndParse(Constants::API_FUNCTION_OFFERS_UPDATE, $xml);
    }

    /**
     * 24. kickback_RS - Check for kickback (commission)
     *
     * @return \SimpleXMLElement|false
     */
    public function getKickbackInfo(string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <kickback_RS>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
        </kickback_RS>';

        return $this->callApiAndParse(Constants::API_FUNCTION_KICKBACK, $xml, $lang);
    }
}
