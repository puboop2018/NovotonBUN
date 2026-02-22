<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

class ReservationApiClient extends ApiClientBase
{
    /**
     * 7. hotel_res_RQ - Reservation request
     *
     * @return \SimpleXMLElement|false
     */
    public function createReservation(array $bookingData)
    {
        $isTestMode = ConfigProvider::isTestBooking();

        $remark = $bookingData['remark'] ?? '';
        $comment = $bookingData['comment'] ?? '';

        if ($isTestMode) {
            $remark = 'test reservation, do not proceed';
            $comment = 'test reservation, do not proceed';
        }

        // Build guests XML
        $guestsXml = '';
        $idGuest = 1;
        $allGuests = $bookingData['guests'] ?? [];
        foreach ($allGuests as $guest) {
            $guestsXml .= '
    <Guests>
        <IdGuest>' . $idGuest . '</IdGuest>
        <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
        <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
        <Age>' . (int) ($guest['age'] ?? 0) . '</Age>
    </Guests>';
            $idGuest++;
        }

        // Check if this is multi-room booking
        $rooms = $bookingData['rooms'] ?? [];
        $hotelAccXml = '';

        if (!empty($rooms) && count($rooms) > 1) {
            $guestIdCounter = 1;
            foreach ($rooms as $roomIdx => $roomData) {
                $roomGuests = $roomData['guests'] ?? [];

                $roomAccXml = '';
                foreach ($roomGuests as $guest) {
                    $roomAccXml .= '
            <room_acc>
                <IdGuest>' . $guestIdCounter . '</IdGuest>
                <Name>' . htmlspecialchars($guest['name']) . '</Name>
            </room_acc>';
                    $guestIdCounter++;
                }

                $hotelAccXml .= '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($roomData['room_id']) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($roomData['board_id']) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar>' . htmlspecialchars($bookingData['star_rating'] ?? '') . '</IdStar>
        <Holder>' . htmlspecialchars($roomGuests[0]['name'] ?? $bookingData['holder']) . '</Holder>
        <ISO_National>' . htmlspecialchars($bookingData['iso_national'] ?? Constants::DEFAULT_ISO_NATIONAL) . '</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment . ' [Room ' . ($roomIdx + 1) . ']') . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
            }
        } else {
            $roomAccXml = '';
            $idGuest = 1;
            foreach ($allGuests as $guest) {
                $roomAccXml .= '
            <room_acc>
                <IdGuest>' . $idGuest . '</IdGuest>
                <Name>' . htmlspecialchars($guest['name']) . '</Name>
            </room_acc>';
                $idGuest++;
            }

            $hotelAccXml = '
    <hotel_acc>
        <ConfNum></ConfNum>
        <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
        <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
        <IdRoom>' . htmlspecialchars($bookingData['room_id']) . '</IdRoom>
        <IdBoard>' . htmlspecialchars($bookingData['board_id']) . '</IdBoard>
        <IdExtBoard></IdExtBoard>
        <IdStar>' . htmlspecialchars($bookingData['star_rating'] ?? '') . '</IdStar>
        <Holder>' . htmlspecialchars($bookingData['holder']) . '</Holder>
        <ISO_National>' . htmlspecialchars($bookingData['iso_national'] ?? Constants::DEFAULT_ISO_NATIONAL) . '</ISO_National>
        <Remark>' . htmlspecialchars($remark) . '</Remark>
        <Comment>' . htmlspecialchars($comment) . '</Comment>' . $roomAccXml . '
    </hotel_acc>';
        }

        $discountType = $bookingData['discount_type'] ?? '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_res_RQ>
    <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
    <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
    <IdHotel>' . htmlspecialchars($bookingData['hotel_id']) . '</IdHotel>
    <CreatedBy>' . htmlspecialchars(Constants::DEFAULT_CREATED_BY) . '</CreatedBy>
    <PackageName>' . htmlspecialchars($bookingData['package_name'] ?? '') . '</PackageName>
    <CheckIn>' . htmlspecialchars($bookingData['check_in']) . '</CheckIn>
    <CheckOut>' . htmlspecialchars($bookingData['check_out']) . '</CheckOut>
    <DiscountType>' . htmlspecialchars($discountType) . '</DiscountType>' . $guestsXml . $hotelAccXml . '
    <OrderNum>' . htmlspecialchars($bookingData['order_num'] ?? '') . '</OrderNum>
</hotel_res_RQ>';

        $this->lastRequest = $xml;

        if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_res_RQ Request (Test Mode: ' . ($isTestMode ? 'YES' : 'NO') . ')',
                'xml' => $xml
            ]);
        }

        $response = $this->callApi(Constants::API_FUNCTION_RESERVATION, $xml, $bookingData['lang'] ?? 'UK');

        if (defined('NOVOTON_DEBUG') || ConfigProvider::isDebugLogging()) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton hotel_res_RQ Response',
                'response' => $response
            ]);
        }

        return $this->xmlParser->parse($response);
    }

    /**
     * 15. resinfo - Reservations Info
     *
     * @return \SimpleXMLElement|false
     */
    public function getReservationInfo(string $idNum = '', string $confirmAgency = '', string $lang = 'UK')
    {
        $searchXml = $idNum ? '<IdNum>' . htmlspecialchars($idNum) . '</IdNum>' :
                              '<ConfirmAgency>' . htmlspecialchars($confirmAgency) . '</ConfirmAgency>';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <resinfo>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            ' . $searchXml . '
        </resinfo>';

        return $this->callApiAndParse(Constants::API_FUNCTION_RES_INFO, $xml, $lang);
    }

    /**
     * 22. hotel_request - Request alternatives when no prices available
     *
     * @return \SimpleXMLElement|array|false
     */
    public function createHotelRequest(array $requestData, string $lang = 'UK', bool $returnXml = false)
    {
        $guestsXml = '';
        if (!empty($requestData['guests'])) {
            foreach ($requestData['guests'] as $guest) {
                $guestsXml .= '
<Guests>
  <IdGuest>' . htmlspecialchars($guest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
  <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
  <Age>' . (int) ($guest['age'] ?? 30) . '</Age>
</Guests>';
            }
        }

        $roomAccXml = '';
        if (!empty($requestData['room_guests'])) {
            foreach ($requestData['room_guests'] as $roomGuest) {
                $roomAccXml .= '
<room_acc>
  <IdGuest>' . htmlspecialchars($roomGuest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($roomGuest['name'] ?? '') . '</Name>
</room_acc>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_request>
  <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
  <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
  <IdHotel>' . htmlspecialchars($requestData['hotel_id']) . '</IdHotel>
  <CreatedBy>' . htmlspecialchars($requestData['created_by'] ?? Constants::DEFAULT_CREATED_BY) . '</CreatedBy>
  <PackageName>' . htmlspecialchars($requestData['package_name'] ?? '') . '</PackageName>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
' . $guestsXml . '
<hotel_acc>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
  <IdRoom>' . htmlspecialchars($requestData['room_id'] ?? '') . '</IdRoom>
  <IdBoard>' . htmlspecialchars($requestData['board_id'] ?? '') . '</IdBoard>
  <IdExtBoard>' . htmlspecialchars($requestData['ext_board_id'] ?? '') . '</IdExtBoard>
  <IdStar>' . htmlspecialchars($requestData['star_rating'] ?? '') . '</IdStar>
  <Holder>' . htmlspecialchars($requestData['holder'] ?? '') . '</Holder>
  <Remark>' . htmlspecialchars($requestData['remark'] ?? '') . '</Remark>
  <Comment>' . htmlspecialchars($requestData['comment'] ?? '') . '</Comment>
' . $roomAccXml . '
</hotel_acc>
</hotel_request>';

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton hotel_request Request',
            'xml' => $xml
        ]);

        $response = $this->callApi(Constants::API_FUNCTION_HOTEL_REQUEST, $xml, $lang);
        $parsed = $this->xmlParser->parse($response);

        if ($returnXml) {
            $xmlMasked = preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
            $xmlMasked = preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xmlMasked);

            return [
                'xml_sent' => $xmlMasked,
                'xml_response' => $response,
                'parsed' => $parsed,
                'id_num' => isset($parsed->IdNum) ? (string)$parsed->IdNum : null
            ];
        }

        return $parsed;
    }

    /**
     * Generate hotel_request XML without sending (for preview/testing)
     */
    public function generateHotelRequestXml(array $requestData): string
    {
        $guestsXml = '';
        if (!empty($requestData['guests'])) {
            foreach ($requestData['guests'] as $guest) {
                $guestsXml .= '
<Guests>
  <IdGuest>' . htmlspecialchars($guest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($guest['name'] ?? '') . '</Name>
  <BirthDay>' . htmlspecialchars($guest['birthday'] ?? '') . '</BirthDay>
  <Age>' . (int) ($guest['age'] ?? 30) . '</Age>
</Guests>';
            }
        }

        $roomAccXml = '';
        if (!empty($requestData['room_guests'])) {
            foreach ($requestData['room_guests'] as $roomGuest) {
                $roomAccXml .= '
<room_acc>
  <IdGuest>' . htmlspecialchars($roomGuest['id'] ?? 1) . '</IdGuest>
  <Name>' . htmlspecialchars($roomGuest['name'] ?? '') . '</Name>
</room_acc>';
            }
        }

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<hotel_request>
  <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
  <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
  <IdHotel>' . htmlspecialchars($requestData['hotel_id']) . '</IdHotel>
  <CreatedBy>' . htmlspecialchars($requestData['created_by'] ?? Constants::DEFAULT_CREATED_BY) . '</CreatedBy>
  <PackageName>' . htmlspecialchars($requestData['package_name'] ?? '') . '</PackageName>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
' . $guestsXml . '
<hotel_acc>
  <CheckIn>' . htmlspecialchars($requestData['check_in']) . '</CheckIn>
  <CheckOut>' . htmlspecialchars($requestData['check_out']) . '</CheckOut>
  <IdRoom>' . htmlspecialchars($requestData['room_id'] ?? '') . '</IdRoom>
  <IdBoard>' . htmlspecialchars($requestData['board_id'] ?? '') . '</IdBoard>
  <IdExtBoard>' . htmlspecialchars($requestData['ext_board_id'] ?? '') . '</IdExtBoard>
  <IdStar>' . htmlspecialchars($requestData['star_rating'] ?? '') . '</IdStar>
  <Holder>' . htmlspecialchars($requestData['holder'] ?? '') . '</Holder>
  <Remark>' . htmlspecialchars($requestData['remark'] ?? '') . '</Remark>
  <Comment>' . htmlspecialchars($requestData['comment'] ?? '') . '</Comment>
' . $roomAccXml . '
</hotel_acc>
</hotel_request>';

        $xmlMasked = preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
        $xmlMasked = preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xmlMasked);

        return $xmlMasked;
    }

    /**
     * 23. alternative_RS - Check for available requested alternatives
     *
     * @return \SimpleXMLElement|false
     */
    public function getAlternatives(string $idNum, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
<alternative_RS>
  <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
  <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
  <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
</alternative_RS>';

        fn_log_event('general', 'runtime', [
            'message' => 'Novoton alternative_RS Request',
            'xml' => $xml
        ]);

        return $this->callApiAndParse(Constants::API_FUNCTION_ALTERNATIVE_RS, $xml, $lang);
    }

    /**
     * 8. hotel_acc_RQ_html - Invoice as HTML
     *
     * @return string|false
     */
    public function getInvoiceHtml(string $idNum, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_acc_RQ_html>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ_html>';

        return $this->callApi(Constants::API_FUNCTION_INVOICE_HTML, $xml, $lang);
    }

    /**
     * 9. hotel_acc_RQ - Invoice as XML
     *
     * @return \SimpleXMLElement|false
     */
    public function getInvoiceXml(string $idNum, string $lang = 'UK')
    {
        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <hotel_acc_RQ>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            <IdNum>' . htmlspecialchars($idNum) . '</IdNum>
        </hotel_acc_RQ>';

        return $this->callApiAndParse(Constants::API_FUNCTION_INVOICE_XML, $xml, $lang);
    }

    /**
     * 14. list_invoices - List Invoices
     *
     * @return \SimpleXMLElement|false
     */
    public function listInvoices(string $arrFrom = '', string $arrTo = '', string $lang = 'UK')
    {
        $arrFromXml = $arrFrom ? '<ArrFrom>' . htmlspecialchars($arrFrom) . '</ArrFrom>' : '';
        $arrToXml = $arrTo ? '<ArrTo>' . htmlspecialchars($arrTo) . '</ArrTo>' : '';

        $xml = '<?xml version="1.0" encoding="windows-1251"?>
        <list_invoices>
            <usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>
            <psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>
            ' . $arrFromXml . '
            ' . $arrToXml . '
        </list_invoices>';

        return $this->callApiAndParse(Constants::API_FUNCTION_LIST_INVOICES, $xml, $lang);
    }
}
